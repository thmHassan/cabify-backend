const {
    PLOT_DISPATCH_ACTIVE_PREFIX,
    startedAction,
    singleOfferAction,
    exhaustedAction,
    exhaustedBiddingAction,
    missingPickupPlotAction,
    acceptedAction,
    isPlotDispatchInProgress,
} = require('./plotDispatchMessages');

const DEFAULT_DISPATCH_TIMEOUT_SECONDS = 30;
const TERMINAL_BOOKING_STATUSES = new Set(['cancelled', 'completed', 'no_show']);

const parseJsonArray = (raw) => {
    if (!raw) return [];
    if (Array.isArray(raw)) return raw;
    if (typeof raw === 'string') {
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }
    return [];
};

const uniqueStrings = (items) => [...new Set((items || []).map((item) => String(item)).filter(Boolean))];

const summarizeDriver = (driver) => ({
    id: driver.id,
    name: driver.name ?? null,
    profile_image: driver.profile_image ?? null,
    priority_plot: driver.priority_plot ?? null,
    plot_id: driver.plot_id ?? null,
    rank: driver.dispatch_rank ?? driver.queue_rank ?? null,
});

const createPlotDispatchService = ({
    io,
    driverSockets,
    dispatcherSockets,
    adminSockets,
    clientSockets,
    getConnection,
    getQueueSnapshot,
    waitingQueue,
    broadcastDashboardCardsUpdate,
    broadcastTodaysBookingsListUpdate,
    sendNotificationToDriver,
}) => {
    const plotDispatchSessions = new Map();
    let globalOfferToken = 0;

    const isPlotDispatchActive = (dispatcherAction) => isPlotDispatchInProgress(dispatcherAction);
    const tenantSocketKey = (database, id) => (
        database && id !== undefined && id !== null && id !== ''
            ? `${String(database).trim()}:${String(id).trim()}`
            : null
    );
    const getTenantDriverSocket = (dbName, driverId) => {
        const key = tenantSocketKey(dbName, driverId);
        return key ? driverSockets.get(key) : null;
    };

    const emitToCompanyRooms = (dbName, event, payload) => {
        const eventPayload = { ...payload, database: dbName };
        io.to(`dispatcher_${dbName}`).emit(event, eventPayload);
        io.to(`admin_${dbName}`).emit(event, eventPayload);
        io.to(`client_${dbName}`).emit(event, eventPayload);
    };

    const emitBookingUpdated = (dbName, booking) => {
        emitToCompanyRooms(dbName, 'booking-updated-event', booking);
    };

    const clearPlotDispatchSession = (bookingIdInt) => {
        const key = String(bookingIdInt);
        const session = plotDispatchSessions.get(key);
        if (session?.timeoutId) clearTimeout(session.timeoutId);
        plotDispatchSessions.delete(key);
    };

    const isTerminalBookingStatus = (status) =>
        TERMINAL_BOOKING_STATUSES.has(String(status || '').toLowerCase());

    const terminatePlotDispatchForBooking = async ({
        bookingId,
        tenantDb,
        db: providedDb = null,
        dbName: providedDbName = null,
        status = null,
    }) => {
        const bookingIdInt = parseInt(bookingId, 10);
        if (!bookingIdInt) return null;

        const db = providedDb || getConnection(tenantDb);
        const dbName = providedDbName || (tenantDb?.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb);
        clearPlotDispatchSession(bookingIdInt);

        await db.query(
            `UPDATE booking_dispatch_cycles
             SET status = 'exhausted',
                 current_driver_id = NULL,
                 current_driver_rank = NULL,
                 offer_expires_at = NULL,
                 updated_at = NOW()
             WHERE booking_id = ? AND status = 'in_progress'`,
            [bookingIdInt]
        );

        try {
            await db.query('DELETE FROM send_new_rides WHERE booking_id = ?', [bookingIdInt]);
        } catch (e) {
            console.error('[PlotDispatch] send_new_rides cleanup error:', e.message);
        }

        const [bookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        const booking = bookingRows[0] ?? null;
        if (booking && dbName) {
            emitToCompanyRooms(dbName, 'plot-dispatch-status', {
                booking_id: bookingIdInt,
                phase: 'terminal',
                message: status ? `Booking ${status}` : 'Booking is no longer dispatchable',
                dispatcher_action: booking.dispatcher_action,
                booking,
            });
            emitBookingUpdated(dbName, booking);
        }

        return booking;
    };

    const getDispatchTimeoutMs = async (db) => {
        try {
            const [rows] = await db.query('SELECT dispatch_timeout FROM settings ORDER BY id DESC LIMIT 1');
            if (rows.length && rows[0].dispatch_timeout) {
                const seconds = parseInt(rows[0].dispatch_timeout, 10);
                if (!Number.isNaN(seconds) && seconds > 0) return seconds * 1000;
            }
        } catch (e) {
            console.error('[PlotDispatch] Settings fetch error:', e.message);
        }
        return DEFAULT_DISPATCH_TIMEOUT_SECONDS * 1000;
    };

    const isBiddingFallbackEnabled = async (db, bookingId = null) => {
        try {
            if (bookingId) {
                const [bookingRows] = await db.query(
                    'SELECT bidding_fallback FROM bookings WHERE id = ? LIMIT 1',
                    [bookingId]
                );
                if (bookingRows[0]?.bidding_fallback === 1 || bookingRows[0]?.bidding_fallback === true || bookingRows[0]?.bidding_fallback === '1') {
                    return true;
                }
            }

            const [rows] = await db.query(
                `SELECT status FROM dispatch_system
                 WHERE dispatch_system = 'auto_dispatch_plot_base'
                   AND steps = 'put_in_bidding_panel'
                 LIMIT 1`
            );
            return rows[0]?.status === 'enable' || rows[0]?.status === 'enabled' || rows[0]?.status === 1;
        } catch (e) {
            console.error('[PlotDispatch] Bidding fallback setting error:', e.message);
            return false;
        }
    };

    const loadBackupPlotChain = async (db, primaryPlotId) => {
        const primaryStr = String(primaryPlotId);
        const chain = [primaryStr];
        try {
            const [plotRows] = await db.query('SELECT backup_plots FROM plots WHERE id = ?', [primaryPlotId]);
            const backups = parseJsonArray(plotRows[0]?.backup_plots);
            for (const plotId of backups) {
                const plotStr = String(plotId);
                if (!chain.includes(plotStr)) chain.push(plotStr);
            }
        } catch (e) {
            console.error('[PlotDispatch] Backup plot chain error:', e.message);
        }
        return chain;
    };

    const fetchPlotNameMap = async (db, plotIds) => {
        const uniqueIds = [...new Set((plotIds || []).map((id) => parseInt(id, 10)).filter((id) => !Number.isNaN(id)))];
        if (!uniqueIds.length) return new Map();
        try {
            const placeholders = uniqueIds.map(() => '?').join(', ');
            const [rows] = await db.query(`SELECT id, name FROM plots WHERE id IN (${placeholders})`, uniqueIds);
            return new Map(rows.map((row) => [String(row.id), row.name ?? null]));
        } catch (e) {
            console.error('[PlotDispatch] Plot name fetch error:', e.message);
            return new Map();
        }
    };

    const fetchEligibleDriversInPlot = async (db, plotIdInt, dbName, booking = null) => {
        const requestedVehicle = booking?.vehicle && String(booking.vehicle).trim() !== ''
            ? String(booking.vehicle).trim()
            : null;
        const vehicleSql = requestedVehicle ? 'AND d.assigned_vehicle = ?' : '';
        const params = requestedVehicle
            ? [plotIdInt, String(plotIdInt), plotIdInt, requestedVehicle]
            : [plotIdInt, String(plotIdInt), plotIdInt];

        const [rows] = await db.query(
            `SELECT d.id, d.name, d.profile_image, d.plot_id, d.priority_plot, d.assigned_vehicle, d.driving_status, d.online_status,
                    q.\`rank\` AS queue_rank
             FROM drivers d
             LEFT JOIN plot_driver_queues q
               ON q.driver_id = d.id AND q.plot_id = ?
             WHERE d.driving_status = 'idle'
               AND d.online_status = 'online'
               AND (d.plot_id = ? OR d.plot_id = ?)
               ${vehicleSql}
             ORDER BY (q.\`rank\` IS NULL) ASC,
                      q.\`rank\` ASC,
                      CAST(COALESCE(d.priority_plot, '9999') AS UNSIGNED) ASC,
                      d.id ASC`,
            params
        );

        const queueSnapshot = getQueueSnapshot(`${plotIdInt}_${dbName}`);
        return rows
            .map((driver, index) => {
                const snapshotRank = queueSnapshot.find((q) => q.driver_id === String(driver.id))?.rank;
                return {
                    ...driver,
                    dispatch_rank: Number(driver.queue_rank ?? snapshotRank ?? driver.priority_plot ?? index + 1),
                };
            })
            .sort((a, b) => {
                const rankA = Number(a.dispatch_rank || 9999);
                const rankB = Number(b.dispatch_rank || 9999);
                if (rankA !== rankB) return rankA - rankB;
                return Number(a.id) - Number(b.id);
            });
    };

    const buildPlotChainSummary = (plotChain, visitedPlots, plotNameMap, primaryPlotId) => (
        (plotChain || []).map((plotId, index) => ({
            plot_id: parseInt(plotId, 10),
            plot_name: plotNameMap.get(String(plotId)) ?? null,
            is_primary: String(plotId) === String(primaryPlotId),
            is_backup: index > 0,
            visited: visitedPlots.includes(String(plotId)),
        }))
    );

    const buildDispatchStatusPayload = async ({
        db,
        bookingIdInt,
        cycle,
        plotChain,
        visitedPlots,
        drivers = [],
        rejectedDriverIds = [],
        timeoutMs,
        phase,
        booking,
    }) => {
        const plotIdInt = cycle?.current_plot_id ? parseInt(cycle.current_plot_id, 10) : null;
        const primaryPlotId = cycle?.primary_plot_id ? parseInt(cycle.primary_plot_id, 10) : null;
        const plotNameMap = await fetchPlotNameMap(db, plotChain);
        const currentPlotName = plotIdInt ? plotNameMap.get(String(plotIdInt)) ?? null : null;
        const driverSummaries = drivers.map(summarizeDriver);
        const currentDriverId = cycle?.current_driver_id ? String(cycle.current_driver_id) : null;
        const pendingSummaries = currentDriverId
            ? driverSummaries.filter((driver) => String(driver.id) === currentDriverId)
            : [];
        const rejectedSummaries = driverSummaries.filter((driver) =>
            rejectedDriverIds.includes(String(driver.id))
        );

        return {
            booking_id: bookingIdInt,
            dispatch_cycle_id: cycle?.id ?? null,
            dispatch_type: 'auto_dispatch_plot_base',
            phase,
            current_plot_id: plotIdInt,
            current_plot_name: currentPlotName,
            is_backup_plot: primaryPlotId && plotIdInt && String(plotIdInt) !== String(primaryPlotId),
            primary_plot_id: primaryPlotId,
            plot_chain: buildPlotChainSummary(plotChain, visitedPlots, plotNameMap, primaryPlotId),
            visited_plot_ids: visitedPlots,
            driver_count: driverSummaries.length,
            driver_ids: driverSummaries.map((driver) => String(driver.id)),
            drivers: driverSummaries,
            current_driver_id: currentDriverId,
            current_driver_rank: cycle?.current_driver_rank ?? null,
            pending_driver_ids: pendingSummaries.map((driver) => String(driver.id)),
            pending_drivers: pendingSummaries,
            rejected_driver_ids: rejectedDriverIds,
            rejected_drivers: rejectedSummaries,
            attempted_driver_ids: parseJsonArray(cycle?.attempted_driver_ids).map(String),
            expires_at: cycle?.offer_expires_at ?? null,
            expires_in_seconds: timeoutMs ? Math.round(timeoutMs / 1000) : null,
            offer_token: cycle?.offer_token ?? null,
            dispatcher_action: booking?.dispatcher_action ?? null,
            booking,
        };
    };

    const emitPlotDispatchStatus = async (dbName, eventName, payload) => {
        emitToCompanyRooms(dbName, eventName, payload);
        emitToCompanyRooms(dbName, 'plot-dispatch-status', payload);
    };

    const loadCycle = async (db, cycleId) => {
        const [rows] = await db.query('SELECT * FROM booking_dispatch_cycles WHERE id = ?', [cycleId]);
        return rows[0] ?? null;
    };

    const loadCycleByBooking = async (db, bookingIdInt) => {
        const [rows] = await db.query('SELECT * FROM booking_dispatch_cycles WHERE booking_id = ?', [bookingIdInt]);
        return rows[0] ?? null;
    };

    const notifyDriverOfferWithdrawn = (dbName, driverId, bookingIdInt, message = 'This ride is no longer available') => {
        const driverSocketId = getTenantDriverSocket(dbName, driverId);
        if (driverSocketId) {
            io.to(driverSocketId).emit('ride-no-longer-available', {
                booking_id: bookingIdInt,
                message,
            });
        }
    };

    const broadcastFixedFareBidding = async ({ db, dbName, booking, plotChain }) => {
        const requestedVehicle = booking?.vehicle && String(booking.vehicle).trim() !== ''
            ? String(booking.vehicle).trim()
            : null;
        const vehicleSql = requestedVehicle ? 'AND d.assigned_vehicle = ?' : '';
        const params = requestedVehicle ? [requestedVehicle] : [];
        const [drivers] = await db.query(
            `SELECT DISTINCT d.id
             FROM drivers d
             WHERE d.driving_status = 'idle'
               AND d.online_status = 'online'
               AND d.status = 'accepted'
               ${vehicleSql}`,
            params
        );

        let sentCount = 0;
        const bookingPayload = {
            ...booking,
            bidding_fallback: true,
            fixed_fare: true,
            assignment_type: 'fixed_fare_bidding_fallback',
        };

        for (const driver of drivers) {
            try {
                await db.query(
                    `INSERT IGNORE INTO send_new_rides (booking_id, driver_id, created_at, updated_at)
                     VALUES (?, ?, NOW(), NOW())`,
                    [booking.id, driver.id]
                );
            } catch (e) {
                console.error('[PlotDispatch] Bidding send_new_rides insert error:', e.message);
            }

            const socketId = getTenantDriverSocket(dbName, driver.id);
            if (socketId) {
                io.to(socketId).emit('new-ride', bookingPayload);
                sentCount++;
            }

            try {
                await sendNotificationToDriver(
                    db,
                    driver.id,
                    'Ride Available',
                    'A fixed-fare ride is available in the bidding panel',
                    { booking_id: String(booking.id), type: 'fixed_fare_bidding' }
                );
            } catch (e) {
                console.error(`[PlotDispatch] Bidding FCM error for driver #${driver.id}:`, e.message);
            }
        }

        emitToCompanyRooms(dbName, 'fixed-fare-bidding-opened', {
            booking_id: booking.id,
            driver_count: drivers.length,
            booking: bookingPayload,
        });

        return sentCount;
    };

    const markManualAttention = async ({ bookingIdInt, tenantDb, db, dbName, cycleId, action, eventName = 'manual-dispatch-required' }) => {
        const [currentBookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        const currentBooking = currentBookingRows[0] ?? null;
        if (!currentBooking) return null;
        if (isTerminalBookingStatus(currentBooking.booking_status)) {
            await terminatePlotDispatchForBooking({
                bookingId: bookingIdInt,
                tenantDb,
                db,
                dbName,
                status: currentBooking.booking_status,
            });
            return currentBooking;
        }

        clearPlotDispatchSession(bookingIdInt);
        await db.query(
            `UPDATE bookings
             SET driver = NULL, pending_driver_id = NULL, booking_status = 'pending', dispatcher_action = ?
             WHERE id = ?`,
            [action, bookingIdInt]
        );
        const [bookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        const booking = bookingRows[0] ?? null;
        if (!booking) return null;

        const payload = {
            booking_id: bookingIdInt,
            dispatch_cycle_id: cycleId,
            message: action,
            dispatcher_action: action,
            booking,
        };
        emitToCompanyRooms(dbName, eventName, payload);
        emitToCompanyRooms(dbName, 'auto-dispatch-failed', payload);
        emitBookingUpdated(dbName, booking);
        await broadcastDashboardCardsUpdate(tenantDb);
        await broadcastTodaysBookingsListUpdate(tenantDb, db, dbName, bookingIdInt);
        return booking;
    };

    const markCycleExhausted = async ({ bookingIdInt, tenantDb, db, dbName, cycleId }) => {
        const [currentBookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        const currentBooking = currentBookingRows[0] ?? null;
        if (!currentBooking || isTerminalBookingStatus(currentBooking.booking_status)) {
            await terminatePlotDispatchForBooking({
                bookingId: bookingIdInt,
                tenantDb,
                db,
                dbName,
                status: currentBooking?.booking_status,
            });
            return;
        }

        const cycle = await loadCycle(db, cycleId);
        const plotChain = cycle?.primary_plot_id ? await loadBackupPlotChain(db, cycle.primary_plot_id) : [];
        const fallbackToBidding = await isBiddingFallbackEnabled(db, bookingIdInt);
        const dispatcherAction = fallbackToBidding ? exhaustedBiddingAction() : exhaustedAction();

        await db.query(
            `UPDATE booking_dispatch_cycles
             SET status = 'exhausted',
                 current_driver_id = NULL,
                 current_driver_rank = NULL,
                 offer_expires_at = NULL,
                 fallback_to_bidding = ?,
                 updated_at = NOW()
             WHERE id = ? AND status = 'in_progress'`,
            [fallbackToBidding ? 1 : 0, cycleId]
        );

        const booking = await markManualAttention({
            bookingIdInt,
            tenantDb,
            db,
            dbName,
            cycleId,
            action: dispatcherAction,
            eventName: 'plot-dispatch-failed',
        });
        if (!booking) return;
        if (isTerminalBookingStatus(booking.booking_status)) return;

        if (fallbackToBidding) {
            await broadcastFixedFareBidding({ db, dbName, booking, plotChain });
        }

        const exhaustedCycle = await loadCycle(db, cycleId);
        const exhaustedStatus = await buildDispatchStatusPayload({
            db,
            bookingIdInt,
            cycle: exhaustedCycle,
            plotChain,
            visitedPlots: parseJsonArray(exhaustedCycle?.visited_plot_ids),
            drivers: [],
            rejectedDriverIds: parseJsonArray(exhaustedCycle?.rejected_driver_ids).map(String),
            phase: 'exhausted',
            booking,
            timeoutMs: null,
        });
        await emitPlotDispatchStatus(dbName, 'plot-dispatch-exhausted', exhaustedStatus);
        console.log(`[PlotDispatch] Exhausted all plots for booking #${bookingIdInt}`);
    };

    const offerNextDriver = async ({
        bookingIdInt,
        tenantDb,
        db,
        dbName,
        cycleId,
        plotChain,
        plotIdInt,
        timeoutMs,
        reason,
        isFreshCycle = false,
    }) => {
        const cycle = await loadCycle(db, cycleId);
        if (!cycle || cycle.status !== 'in_progress') return;

        const [bookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        const booking = bookingRows[0] ?? null;
        if (!booking || isTerminalBookingStatus(booking.booking_status) || ['ongoing', 'started'].includes(booking.booking_status) || booking.driver) {
            await terminatePlotDispatchForBooking({
                bookingId: bookingIdInt,
                tenantDb,
                db,
                dbName,
                status: booking?.booking_status,
            });
            return;
        }

        const visited = uniqueStrings([...parseJsonArray(cycle.visited_plot_ids), plotIdInt]);
        const attempted = uniqueStrings(parseJsonArray(cycle.attempted_driver_ids));
        const rejected = uniqueStrings(parseJsonArray(cycle.rejected_driver_ids));
        const drivers = await fetchEligibleDriversInPlot(db, plotIdInt, dbName, booking);
        const candidate = drivers.find((driver) => !attempted.includes(String(driver.id)));

        if (!candidate) {
            const nextPlot = (plotChain || []).find((plotId) => !visited.includes(String(plotId)));
            if (nextPlot) {
                const plotNameMap = await fetchPlotNameMap(db, [plotIdInt, nextPlot]);
                emitToCompanyRooms(dbName, 'plot-dispatch-backup-advanced', {
                    booking_id: bookingIdInt,
                    dispatch_cycle_id: cycleId,
                    from_plot_id: plotIdInt,
                    from_plot_name: plotNameMap.get(String(plotIdInt)) ?? null,
                    to_plot_id: parseInt(nextPlot, 10),
                    to_plot_name: plotNameMap.get(String(nextPlot)) ?? null,
                    reason: reason || 'plot_exhausted',
                    visited_plot_ids: visited,
                });
                await db.query(
                    `UPDATE booking_dispatch_cycles
                     SET visited_plot_ids = ?, current_plot_id = ?, updated_at = NOW()
                     WHERE id = ?`,
                    [JSON.stringify(visited), parseInt(nextPlot, 10), cycleId]
                );
                return offerNextDriver({
                    bookingIdInt,
                    tenantDb,
                    db,
                    dbName,
                    cycleId,
                    plotChain,
                    plotIdInt: parseInt(nextPlot, 10),
                    timeoutMs,
                    reason: 'backup_advanced',
                });
            }

            await markCycleExhausted({ bookingIdInt, tenantDb, db, dbName, cycleId });
            return;
        }

        clearPlotDispatchSession(bookingIdInt);

        const offerToken = ++globalOfferToken;
        const timeoutSeconds = Math.round(timeoutMs / 1000);
        const isBackupPlot = String(plotIdInt) !== String(plotChain[0]);
        const rank = Number(candidate.dispatch_rank || candidate.queue_rank || attempted.length + 1);
        const actionMessage = singleOfferAction(candidate.id, rank, plotIdInt, isBackupPlot, timeoutSeconds);
        const nextAttempted = uniqueStrings([...attempted, candidate.id]);
        const notified = uniqueStrings([...parseJsonArray(cycle.notified_driver_ids), candidate.id]);
        const expiresAt = new Date(Date.now() + timeoutMs);
        const dispatchAmount = (
            booking.booking_amount === null || booking.booking_amount === undefined || booking.booking_amount == 0
        ) ? (booking.offered_amount ?? null) : booking.booking_amount;

        await db.query(
            `UPDATE booking_dispatch_cycles
             SET current_plot_id = ?,
                 current_driver_id = ?,
                 current_driver_rank = ?,
                 visited_plot_ids = ?,
                 notified_driver_ids = ?,
                 attempted_driver_ids = ?,
                 rejected_driver_ids = ?,
                 offer_token = ?,
                 offer_expires_at = ?,
                 updated_at = NOW()
             WHERE id = ? AND status = 'in_progress'`,
            [
                plotIdInt,
                candidate.id,
                rank,
                JSON.stringify(visited),
                JSON.stringify(notified),
                JSON.stringify(nextAttempted),
                JSON.stringify(rejected),
                offerToken,
                expiresAt,
                cycleId,
            ]
        );

        await db.query(
            `UPDATE bookings
             SET driver = NULL,
                 pending_driver_id = ?,
                 booking_amount = ?,
                 booking_status = 'pending',
                 dispatcher_action = ?
             WHERE id = ?`,
            [candidate.id, dispatchAmount, actionMessage, bookingIdInt]
        );

        await db.query(
            `INSERT IGNORE INTO send_new_rides (booking_id, driver_id, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())`,
            [bookingIdInt, candidate.id]
        );

        const [updatedRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        const updatedBooking = updatedRows[0];
        const updatedCycle = await loadCycle(db, cycleId);

        const driverSocketId = getTenantDriverSocket(dbName, candidate.id);
        if (driverSocketId) {
            io.to(driverSocketId).emit('new-ride-request', {
                booking_id: updatedBooking.id,
                assignment_type: 'plot_dispatch',
                dispatch_cycle_id: cycleId,
                offer_token: offerToken,
                plot_id: plotIdInt,
                rank,
                expires_at: expiresAt.toISOString(),
                expires_in_seconds: timeoutSeconds,
                message: `You have a new ride request in ${isBackupPlot ? 'backup plot' : 'your plot'}`,
                booking: updatedBooking,
                driver: summarizeDriver(candidate),
            });
        }

        try {
            await sendNotificationToDriver(
                db,
                candidate.id,
                'New Ride Available',
                'You have a new ride request in your plot',
                { booking_id: String(updatedBooking.id), type: 'new_ride', offer_token: String(offerToken) }
            );
        } catch (e) {
            console.error(`[PlotDispatch] FCM error for driver #${candidate.id}:`, e.message);
        }

        const statusPayload = await buildDispatchStatusPayload({
            db,
            bookingIdInt,
            cycle: updatedCycle,
            plotChain,
            visitedPlots: visited,
            drivers,
            rejectedDriverIds: rejected,
            timeoutMs,
            phase: isBackupPlot ? 'backup' : 'primary',
            booking: updatedBooking,
        });

        emitToCompanyRooms(dbName, 'driver-assignment-pending', statusPayload);
        await emitPlotDispatchStatus(
            dbName,
            isFreshCycle ? 'plot-dispatch-started' : 'plot-dispatch-offer',
            statusPayload
        );
        emitBookingUpdated(dbName, updatedBooking);

        if (isFreshCycle) {
            emitToCompanyRooms(dbName, 'notification-ride', updatedBooking);
        }

        const timeoutId = setTimeout(async () => {
            try {
                const session = plotDispatchSessions.get(String(bookingIdInt));
                if (!session || session.offerToken !== offerToken) return;
                const currentCycle = await loadCycle(db, cycleId);
                if (!currentCycle || currentCycle.status !== 'in_progress' || currentCycle.offer_token !== offerToken) return;
                const [checkRows] = await db.query('SELECT booking_status, driver, pending_driver_id FROM bookings WHERE id = ?', [bookingIdInt]);
                const currentBooking = checkRows[0];
                if (!currentBooking || isTerminalBookingStatus(currentBooking.booking_status)) {
                    await terminatePlotDispatchForBooking({
                        bookingId: bookingIdInt,
                        tenantDb,
                        db,
                        dbName,
                        status: currentBooking?.booking_status,
                    });
                    return;
                }
                if (currentBooking.booking_status !== 'pending' || currentBooking.driver) return;
                if (String(currentBooking.pending_driver_id) === String(candidate.id)) {
                    await db.query('UPDATE bookings SET pending_driver_id = NULL WHERE id = ?', [bookingIdInt]);
                    notifyDriverOfferWithdrawn(dbName, candidate.id, bookingIdInt, 'This ride offer expired');
                }
                plotDispatchSessions.delete(String(bookingIdInt));
                await offerNextDriver({
                    bookingIdInt,
                    tenantDb,
                    db,
                    dbName,
                    cycleId,
                    plotChain,
                    plotIdInt,
                    timeoutMs,
                    reason: 'timeout',
                });
            } catch (e) {
                console.error('[PlotDispatch] Timeout handler error:', e.message);
            }
        }, timeoutMs);

        plotDispatchSessions.set(String(bookingIdInt), {
            offerToken,
            currentDriverId: String(candidate.id),
            tenantDb,
            dbName,
            cycleId,
            plotIdInt,
            plotChain,
            timeoutMs,
            timeoutId,
        });
    };

    const startPlotDispatchCycle = async ({ bookingId, tenantDb }) => {
        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;
        const bookingIdInt = parseInt(bookingId, 10);
        const connection = await db.getConnection();

        try {
            await connection.beginTransaction();
            const [bookingRows] = await connection.query('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [bookingIdInt]);
            if (!bookingRows.length) {
                await connection.rollback();
                return { started: false, reason: 'booking_not_found' };
            }

            const booking = bookingRows[0];
            if (isTerminalBookingStatus(booking.booking_status)) {
                await connection.rollback();
                return { started: false, reason: 'terminal_status' };
            }
            if (booking.driver) {
                await connection.rollback();
                return { started: false, reason: 'already_assigned' };
            }

            const [existingCycles] = await connection.query(
                'SELECT * FROM booking_dispatch_cycles WHERE booking_id = ? FOR UPDATE',
                [bookingIdInt]
            );
            if (existingCycles.length && ['in_progress', 'accepted'].includes(existingCycles[0].status)) {
                await connection.rollback();
                return { started: false, reason: 'cycle_already_active', dispatch_cycle_id: existingCycles[0].id };
            }

            let primaryPlotId = booking.pickup_plot_id ? parseInt(booking.pickup_plot_id, 10) : null;
            if (!primaryPlotId) {
                primaryPlotId = await waitingQueue.resolvePlotIdFromBooking(db, booking);
                if (primaryPlotId) {
                    await connection.query('UPDATE bookings SET pickup_plot_id = ? WHERE id = ?', [primaryPlotId, bookingIdInt]);
                    booking.pickup_plot_id = primaryPlotId;
                }
            }

            if (!primaryPlotId) {
                await connection.query(
                    `UPDATE bookings
                     SET driver = NULL, pending_driver_id = NULL, booking_status = 'pending', dispatcher_action = ?
                     WHERE id = ?`,
                    [missingPickupPlotAction(), bookingIdInt]
                );
                await connection.commit();
                const [updatedRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
                const updatedBooking = updatedRows[0] ?? booking;
                emitToCompanyRooms(dbName, 'plot-dispatch-failed', {
                    booking_id: bookingIdInt,
                    message: missingPickupPlotAction(),
                    dispatcher_action: missingPickupPlotAction(),
                    booking: updatedBooking,
                });
                emitBookingUpdated(dbName, updatedBooking);
                await broadcastDashboardCardsUpdate(tenantDb);
                await broadcastTodaysBookingsListUpdate(tenantDb, db, dbName, bookingIdInt);
                return { started: false, reason: 'no_pickup_plot' };
            }

            let cycleId;
            if (existingCycles.length) {
                await connection.query(
                    `UPDATE booking_dispatch_cycles
                     SET primary_plot_id = ?,
                         current_plot_id = ?,
                         current_driver_id = NULL,
                         current_driver_rank = NULL,
                         status = 'in_progress',
                         visited_plot_ids = ?,
                         notified_driver_ids = ?,
                         attempted_driver_ids = ?,
                         rejected_driver_ids = ?,
                         offer_token = 1,
                         offer_expires_at = NULL,
                         fallback_to_bidding = 0,
                         updated_at = NOW()
                     WHERE booking_id = ?`,
                    [primaryPlotId, primaryPlotId, JSON.stringify([]), JSON.stringify([]), JSON.stringify([]), JSON.stringify([]), bookingIdInt]
                );
                cycleId = existingCycles[0].id;
            } else {
                const [insertResult] = await connection.query(
                    `INSERT INTO booking_dispatch_cycles
                     (booking_id, primary_plot_id, current_plot_id, status, visited_plot_ids, notified_driver_ids,
                      attempted_driver_ids, rejected_driver_ids, offer_token, fallback_to_bidding, created_at, updated_at)
                     VALUES (?, ?, ?, 'in_progress', ?, ?, ?, ?, 1, 0, NOW(), NOW())`,
                    [bookingIdInt, primaryPlotId, primaryPlotId, JSON.stringify([]), JSON.stringify([]), JSON.stringify([]), JSON.stringify([])]
                );
                cycleId = insertResult.insertId;
            }

            await connection.commit();

            const plotChain = await loadBackupPlotChain(db, primaryPlotId);
            const timeoutMs = await getDispatchTimeoutMs(db);
            const startedMessage = startedAction(primaryPlotId);
            await db.query('UPDATE bookings SET dispatcher_action = ?, pending_driver_id = NULL WHERE id = ?', [startedMessage, bookingIdInt]);
            const [startedBookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
            if (startedBookingRows[0]) emitBookingUpdated(dbName, startedBookingRows[0]);

            await offerNextDriver({
                bookingIdInt,
                tenantDb,
                db,
                dbName,
                cycleId,
                plotChain,
                plotIdInt: primaryPlotId,
                timeoutMs,
                isFreshCycle: true,
            });

            return { started: true, dispatch_cycle_id: cycleId, primary_plot_id: primaryPlotId };
        } catch (e) {
            try {
                await connection.rollback();
            } catch (rollbackError) {
                console.error('[PlotDispatch] Rollback error:', rollbackError.message);
            }
            console.error('[PlotDispatch] Start cycle error:', e.message);
            throw e;
        } finally {
            connection.release();
        }
    };

    const handlePlotDispatchAccept = async ({ bookingIdInt, tenantDb, driverId }) => {
        if (!tenantDb) return;
        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;
        const session = plotDispatchSessions.get(String(bookingIdInt));
        if (session) clearPlotDispatchSession(bookingIdInt);

        try {
            const [rows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
            if (!rows.length) return;
            const booking = rows[0];
            if (isTerminalBookingStatus(booking.booking_status)) {
                await terminatePlotDispatchForBooking({
                    bookingId: bookingIdInt,
                    tenantDb,
                    db,
                    dbName,
                    status: booking.booking_status,
                });
                notifyDriverOfferWithdrawn(dbName, driverId, bookingIdInt, 'This ride is no longer available');
                return;
            }
            const [driverRows] = await db.query('SELECT id, name, profile_image FROM drivers WHERE id = ?', [driverId]);
            const driver = driverRows[0] ?? {};
            const acceptedMessage = acceptedAction(driver.name, driverId);
            await db.query(
                'UPDATE bookings SET dispatcher_action = ? WHERE id = ?',
                [acceptedMessage, bookingIdInt]
            );
            booking.dispatcher_action = acceptedMessage;
            const cycle = await loadCycleByBooking(db, bookingIdInt);
            const notifiedDriverIds = uniqueStrings(parseJsonArray(cycle?.notified_driver_ids));
            notifiedDriverIds.forEach((notifiedId) => {
                if (String(notifiedId) !== String(driverId)) {
                    notifyDriverOfferWithdrawn(dbName, notifiedId, bookingIdInt, 'This ride has been accepted by another driver');
                }
            });

            const eventData = {
                booking_id: bookingIdInt,
                driver_id: driverId,
                driver_name: driver.name ?? null,
                driver_profile_image: driver.profile_image ?? null,
                dispatcher_action: booking.dispatcher_action,
                booking,
                message: driver.name ? `${driver.name} accepted the ride` : `Driver #${driverId} accepted the ride`,
            };

            emitToCompanyRooms(dbName, 'job-accepted-by-driver', eventData);
            emitBookingUpdated(dbName, booking);

            if (cycle) {
                const plotChain = cycle.primary_plot_id ? await loadBackupPlotChain(db, cycle.primary_plot_id) : [];
                const acceptedStatus = await buildDispatchStatusPayload({
                    db,
                    bookingIdInt,
                    cycle,
                    plotChain,
                    visitedPlots: parseJsonArray(cycle.visited_plot_ids),
                    drivers: [{ id: driverId, name: driver.name, profile_image: driver.profile_image }],
                    phase: 'accepted',
                    booking,
                    timeoutMs: null,
                });
                await emitPlotDispatchStatus(dbName, 'plot-dispatch-accepted', acceptedStatus);
            }

            await broadcastDashboardCardsUpdate(tenantDb);
            await broadcastTodaysBookingsListUpdate(tenantDb, db, dbName, bookingIdInt);
        } catch (e) {
            console.error('[PlotDispatch] Accept broadcast error:', e.message);
        }
    };

    const handlePlotDispatchReject = async ({ bookingIdInt, tenantDb, driverId }) => {
        if (!tenantDb) return { handled: false };
        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;
        const cycle = await loadCycleByBooking(db, bookingIdInt);
        if (!cycle || cycle.status !== 'in_progress') return { handled: false };

        const [bookingRows] = await db.query('SELECT booking_status FROM bookings WHERE id = ?', [bookingIdInt]);
        const bookingStatus = bookingRows[0]?.booking_status;
        if (isTerminalBookingStatus(bookingStatus)) {
            await terminatePlotDispatchForBooking({
                bookingId: bookingIdInt,
                tenantDb,
                db,
                dbName,
                status: bookingStatus,
            });
            notifyDriverOfferWithdrawn(dbName, driverId, bookingIdInt, 'This ride is no longer available');
            return {
                handled: true,
                status: 200,
                body: { success: true, message: 'Ride is no longer available', skipped: true },
            };
        }

        if (String(cycle.current_driver_id) !== String(driverId)) {
            notifyDriverOfferWithdrawn(dbName, driverId, bookingIdInt, 'This ride offer is no longer available');
            return {
                handled: true,
                status: 200,
                body: { success: true, message: 'Offer already moved to another driver', skipped: true },
            };
        }

        clearPlotDispatchSession(bookingIdInt);

        const rejected = uniqueStrings([...parseJsonArray(cycle.rejected_driver_ids), driverId]);
        await db.query(
            `UPDATE booking_dispatch_cycles
             SET rejected_driver_ids = ?,
                 current_driver_id = NULL,
                 current_driver_rank = NULL,
                 offer_expires_at = NULL,
                 updated_at = NOW()
             WHERE id = ?`,
            [JSON.stringify(rejected), cycle.id]
        );
        await db.query(
            `UPDATE bookings
             SET pending_driver_id = NULL
             WHERE id = ? AND pending_driver_id = ?`,
            [bookingIdInt, driverId]
        );

        const rejectEvent = {
            booking_id: bookingIdInt,
            driver_id: driverId,
            message: `Driver #${driverId} rejected the ride`,
            rejected_driver_ids: rejected,
        };
        emitToCompanyRooms(dbName, 'job-rejected-by-driver', rejectEvent);
        emitToCompanyRooms(dbName, 'plot-dispatch-driver-rejected', rejectEvent);

        const plotChain = cycle.primary_plot_id ? await loadBackupPlotChain(db, cycle.primary_plot_id) : [];
        await offerNextDriver({
            bookingIdInt,
            tenantDb,
            db,
            dbName,
            cycleId: cycle.id,
            plotChain,
            plotIdInt: parseInt(cycle.current_plot_id, 10),
            timeoutMs: await getDispatchTimeoutMs(db),
            reason: 'reject',
        });

        return {
            handled: true,
            status: 200,
            body: { success: true, message: 'Reject processed — offering to next ranked driver' },
        };
    };

    const getPlotDispatchStatus = async ({ bookingId, tenantDb }) => {
        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;
        const bookingIdInt = parseInt(bookingId, 10);
        const [bookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        if (!bookingRows.length) return { found: false };

        const booking = bookingRows[0];
        const cycle = await loadCycleByBooking(db, bookingIdInt);
        if (!cycle) {
            return { found: true, active: false, booking, dispatch_type: 'auto_dispatch_plot_base' };
        }

        const plotChain = cycle.primary_plot_id ? await loadBackupPlotChain(db, cycle.primary_plot_id) : [];
        const drivers = cycle.current_plot_id
            ? await fetchEligibleDriversInPlot(db, parseInt(cycle.current_plot_id, 10), dbName, booking)
            : [];
        const isBackupPlot = cycle.primary_plot_id && String(cycle.current_plot_id) !== String(cycle.primary_plot_id);
        const phase = cycle.status === 'accepted'
            ? 'accepted'
            : (cycle.status === 'exhausted' ? 'exhausted' : (isBackupPlot ? 'backup' : 'primary'));
        const status = await buildDispatchStatusPayload({
            db,
            bookingIdInt,
            cycle,
            plotChain,
            visitedPlots: parseJsonArray(cycle.visited_plot_ids),
            drivers,
            rejectedDriverIds: parseJsonArray(cycle.rejected_driver_ids).map(String),
            timeoutMs: await getDispatchTimeoutMs(db),
            phase,
            booking,
        });

        return { found: true, active: cycle.status === 'in_progress', ...status };
    };

    return {
        PLOT_DISPATCH_ACTIVE_PREFIX,
        isPlotDispatchActive,
        clearPlotDispatchSession,
        terminatePlotDispatchForBooking,
        startPlotDispatchCycle,
        handlePlotDispatchAccept,
        handlePlotDispatchReject,
        getPlotDispatchStatus,
        plotDispatchSessions,
    };
};

module.exports = { createPlotDispatchService, PLOT_DISPATCH_ACTIVE_PREFIX };
