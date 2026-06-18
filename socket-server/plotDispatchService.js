const {
    PLOT_DISPATCH_ACTIVE_PREFIX,
    startedAction,
    dispatchedPlotAction,
    broadcastAction,
    exhaustedAction,
    isPlotDispatchInProgress,
} = require('./plotDispatchMessages');
const DEFAULT_DISPATCH_TIMEOUT_SECONDS = 30;

const parseJsonArray = (raw) => {
    if (!raw) {
        return [];
    }
    if (Array.isArray(raw)) {
        return raw;
    }
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

const summarizeDriver = (driver) => ({
    id: driver.id,
    name: driver.name ?? null,
    profile_image: driver.profile_image ?? null,
    priority_plot: driver.priority_plot ?? null,
    plot_id: driver.plot_id ?? null,
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

    const emitBookingUpdated = (dbName, booking) => {
        emitToCompanyRooms(dbName, 'booking-updated-event', booking);
    };

    const clearPlotDispatchSession = (bookingIdInt) => {
        const key = String(bookingIdInt);
        const session = plotDispatchSessions.get(key);
        if (session?.timeoutId) {
            clearTimeout(session.timeoutId);
        }
        plotDispatchSessions.delete(key);
    };

    const getDispatchTimeoutMs = async (db) => {
        try {
            const [rows] = await db.query(
                'SELECT dispatch_timeout FROM settings ORDER BY id DESC LIMIT 1'
            );
            if (rows.length && rows[0].dispatch_timeout) {
                const seconds = parseInt(rows[0].dispatch_timeout, 10);
                if (!Number.isNaN(seconds) && seconds > 0) {
                    return seconds * 1000;
                }
            }
        } catch (e) {
            console.error(`[PlotDispatch] Settings fetch error:`, e.message);
        }

        return DEFAULT_DISPATCH_TIMEOUT_SECONDS * 1000;
    };

    const loadBackupPlotChain = async (db, primaryPlotId) => {
        const primaryStr = String(primaryPlotId);
        const chain = [primaryStr];

        try {
            const [plotRows] = await db.query(
                'SELECT backup_plots FROM plots WHERE id = ?',
                [primaryPlotId]
            );
            const backups = parseJsonArray(plotRows[0]?.backup_plots);
            for (const plotId of backups) {
                const plotStr = String(plotId);
                if (!chain.includes(plotStr)) {
                    chain.push(plotStr);
                }
            }
        } catch (e) {
            console.error(`[PlotDispatch] Backup plot chain error:`, e.message);
        }

        return chain;
    };

    const fetchPlotNameMap = async (db, plotIds) => {
        const uniqueIds = [...new Set(plotIds.map((id) => parseInt(id, 10)).filter((id) => !Number.isNaN(id)))];
        if (!uniqueIds.length) {
            return new Map();
        }

        try {
            const placeholders = uniqueIds.map(() => '?').join(', ');
            const [rows] = await db.query(
                `SELECT id, name FROM plots WHERE id IN (${placeholders})`,
                uniqueIds
            );
            return new Map(rows.map((row) => [String(row.id), row.name ?? null]));
        } catch (e) {
            console.error('[PlotDispatch] Plot name fetch error:', e.message);
            return new Map();
        }
    };

    const fetchIdleDriversInPlot = async (db, plotIdInt, dbName) => {
        const plotIdStr = String(plotIdInt);
        const [idleRows] = await db.query(
            `SELECT id, name, profile_image, plot_id, priority_plot, driving_status, online_status
             FROM drivers
             WHERE driving_status = 'idle'
             AND online_status = 'online'
             AND (plot_id = ? OR plot_id = ?)
             ORDER BY CAST(COALESCE(priority_plot, '9999') AS UNSIGNED) ASC, id ASC`,
            [plotIdStr, plotIdInt]
        );

        let drivers = idleRows;
        const plotQueueKey = `${plotIdInt}_${dbName}`;
        const queueSnapshot = getQueueSnapshot(plotQueueKey);

        if (queueSnapshot.length > 0) {
            drivers = drivers.sort((a, b) => {
                const rankA = queueSnapshot.find((q) => q.driver_id === String(a.id))?.rank ?? 9999;
                const rankB = queueSnapshot.find((q) => q.driver_id === String(b.id))?.rank ?? 9999;
                return rankA - rankB;
            });
        }

        return drivers;
    };

    const buildPlotChainSummary = (plotChain, visitedPlots, plotNameMap, primaryPlotId) => (
        plotChain.map((plotId, index) => ({
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
        cycleId,
        plotIdInt,
        plotChain,
        visitedPlots,
        drivers,
        rejectedDriverIds = [],
        timeoutMs,
        isBackupPlot,
        phase,
        booking,
        primaryPlotId,
    }) => {
        const plotNameMap = await fetchPlotNameMap(db, plotChain);
        const currentPlotName = plotNameMap.get(String(plotIdInt)) ?? null;
        const driverSummaries = drivers.map(summarizeDriver);
        const rejectedSummaries = driverSummaries.filter((driver) =>
            rejectedDriverIds.includes(String(driver.id))
        );
        const pendingSummaries = driverSummaries.filter((driver) =>
            !rejectedDriverIds.includes(String(driver.id))
        );

        return {
            booking_id: bookingIdInt,
            dispatch_cycle_id: cycleId,
            dispatch_type: 'auto_dispatch_plot_base',
            phase,
            current_plot_id: plotIdInt,
            current_plot_name: currentPlotName,
            is_backup_plot: isBackupPlot,
            primary_plot_id: primaryPlotId,
            plot_chain: buildPlotChainSummary(plotChain, visitedPlots, plotNameMap, primaryPlotId),
            visited_plot_ids: visitedPlots,
            driver_count: drivers.length,
            driver_ids: drivers.map((driver) => String(driver.id)),
            drivers: driverSummaries,
            pending_driver_ids: pendingSummaries.map((driver) => String(driver.id)),
            pending_drivers: pendingSummaries,
            rejected_driver_ids: rejectedDriverIds,
            rejected_drivers: rejectedSummaries,
            expires_in_seconds: timeoutMs ? Math.round(timeoutMs / 1000) : null,
            dispatcher_action: booking?.dispatcher_action ?? null,
            booking,
        };
    };

    const emitPlotDispatchStatus = async (dbName, eventName, payload) => {
        emitToCompanyRooms(dbName, eventName, payload);
        emitToCompanyRooms(dbName, 'plot-dispatch-status', payload);
    };

    const emitToCompanyRooms = (dbName, event, payload) => {
        io.to(`dispatcher_${dbName}`).emit(event, payload);
        io.to(`admin_${dbName}`).emit(event, payload);
        io.to(`client_${dbName}`).emit(event, payload);
        dispatcherSockets.forEach((sid) => io.to(sid).emit(event, payload));
        adminSockets.forEach((sid) => io.to(sid).emit(event, payload));
        clientSockets.forEach((sid) => io.to(sid).emit(event, payload));
    };

    const notifyDriversRideWithdrawn = (notifiedDriverIds, acceptedDriverId, bookingIdInt) => {
        notifiedDriverIds.forEach((driverId) => {
            if (String(driverId) === String(acceptedDriverId)) {
                return;
            }

            const driverSocketId = driverSockets.get(String(driverId).trim());
            if (driverSocketId) {
                io.to(driverSocketId).emit('ride-no-longer-available', {
                    booking_id: bookingIdInt,
                    message: 'This ride has been accepted by another driver',
                });
            }
        });
    };

    const markCycleExhausted = async ({ bookingIdInt, tenantDb, db, dbName, cycleId }) => {
        clearPlotDispatchSession(bookingIdInt);

        try {
            await db.query(
                `UPDATE booking_dispatch_cycles SET status = 'exhausted', updated_at = NOW() WHERE id = ? AND status = 'in_progress'`,
                [cycleId]
            );
        } catch (e) {
            console.error(`[PlotDispatch] Cycle exhaust update error:`, e.message);
        }

        const dispatcherAction = exhaustedAction();

        try {
            await db.query(
                `UPDATE bookings SET driver = NULL, booking_status = 'unassigned', dispatcher_action = ? WHERE id = ?`,
                [dispatcherAction, bookingIdInt]
            );
        } catch (e) {
            console.error(`[PlotDispatch] Booking exhaust update error:`, e.message);
        }

        let updatedBooking = null;
        try {
            const [rows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
            updatedBooking = rows[0] ?? null;
        } catch (e) {
            console.error(`[PlotDispatch] Fetch booking after exhaust error:`, e.message);
        }

        if (!updatedBooking) {
            return;
        }

        let plotChain = [];
        let visitedPlots = [];
        let primaryPlotId = updatedBooking.pickup_plot_id ? parseInt(updatedBooking.pickup_plot_id, 10) : null;
        try {
            const [cycleRows] = await db.query(
                'SELECT primary_plot_id, visited_plot_ids FROM booking_dispatch_cycles WHERE id = ?',
                [cycleId]
            );
            if (cycleRows[0]?.primary_plot_id) {
                primaryPlotId = parseInt(cycleRows[0].primary_plot_id, 10);
                plotChain = await loadBackupPlotChain(db, primaryPlotId);
                visitedPlots = parseJsonArray(cycleRows[0].visited_plot_ids);
            }
        } catch (e) {
            console.error('[PlotDispatch] Exhausted status cycle fetch error:', e.message);
        }

        const payload = {
            booking_id: bookingIdInt,
            dispatch_cycle_id: cycleId,
            message: dispatcherAction,
            dispatcher_action: dispatcherAction,
            booking: updatedBooking,
        };

        emitToCompanyRooms(dbName, 'plot-dispatch-failed', payload);
        emitToCompanyRooms(dbName, 'auto-dispatch-failed', payload);
        emitToCompanyRooms(dbName, 'manual-dispatch-required', {
            ...payload,
            fallback: 'manual_dispatch_only',
        });

        const exhaustedStatus = await buildDispatchStatusPayload({
            db,
            bookingIdInt,
            cycleId,
            plotIdInt: primaryPlotId,
            plotChain,
            visitedPlots,
            drivers: [],
            phase: 'exhausted',
            booking: updatedBooking,
            primaryPlotId,
            isBackupPlot: false,
            timeoutMs: null,
        });
        await emitPlotDispatchStatus(dbName, 'plot-dispatch-exhausted', exhaustedStatus);

        await broadcastDashboardCardsUpdate(tenantDb);
        await broadcastTodaysBookingsListUpdate(tenantDb, db, dbName, bookingIdInt);

        console.log(`[PlotDispatch] Exhausted all plots for booking #${bookingIdInt}`);
    };

    const advancePlotRoundOrExhaust = async ({
        bookingIdInt,
        tenantDb,
        db,
        dbName,
        cycleId,
        plotIdInt,
        plotChain,
        visitedPlots,
        timeoutMs,
        reason,
    }) => {
        const visited = visitedPlots;
        const nextPlot = plotChain.find((plotId) => !visited.includes(String(plotId)));

        if (reason === 'timeout') {
            console.log(`[PlotDispatch] Timeout — no acceptance in plot ${plotIdInt}`);
        } else if (reason === 'no_drivers') {
            console.log(`[PlotDispatch] No drivers in plot ${plotIdInt}`);
        } else if (reason === 'all_rejected') {
            console.log(`[PlotDispatch] All drivers rejected in plot ${plotIdInt}`);
        }

        if (nextPlot) {
            const previousPlotId = plotIdInt;
            console.log(`[PlotDispatch] Advancing to backup plot ${nextPlot}`);

            const plotNameMap = await fetchPlotNameMap(db, [previousPlotId, nextPlot]);
            emitToCompanyRooms(dbName, 'plot-dispatch-backup-advanced', {
                booking_id: bookingIdInt,
                dispatch_cycle_id: cycleId,
                from_plot_id: previousPlotId,
                from_plot_name: plotNameMap.get(String(previousPlotId)) ?? null,
                to_plot_id: parseInt(nextPlot, 10),
                to_plot_name: plotNameMap.get(String(nextPlot)) ?? null,
                reason,
                visited_plot_ids: visited,
            });

            await broadcastPlotRound({
                bookingIdInt,
                tenantDb,
                db,
                dbName,
                cycleId,
                plotIdInt: parseInt(nextPlot, 10),
                plotChain,
                visitedPlots: visited,
                timeoutMs,
                isBackupPlot: String(nextPlot) !== String(plotChain[0]),
                primaryPlotId: parseInt(plotChain[0], 10),
            });
            return;
        }

        await markCycleExhausted({ bookingIdInt, tenantDb, db, dbName, cycleId });
    };

    const broadcastPlotRound = async ({
        bookingIdInt,
        tenantDb,
        db,
        dbName,
        cycleId,
        plotIdInt,
        plotChain,
        visitedPlots,
        timeoutMs,
        isFreshCycle = false,
        isBackupPlot = false,
        primaryPlotId = null,
    }) => {
        const plotIdStr = String(plotIdInt);
        const resolvedPrimaryPlotId = primaryPlotId ?? parseInt(plotChain[0], 10);
        const visited = visitedPlots.includes(plotIdStr)
            ? [...visitedPlots]
            : [...visitedPlots, plotIdStr];

        const drivers = await fetchIdleDriversInPlot(db, plotIdInt, dbName);
        console.log(`[PlotDispatch] Plot ${plotIdInt}: ${drivers.length} idle driver(s)`);

        const offerToken = ++globalOfferToken;
        const notifiedDriverIds = drivers.map((d) => String(d.id));

        try {
            await db.query(
                `UPDATE booking_dispatch_cycles
                 SET current_plot_id = ?, visited_plot_ids = ?, notified_driver_ids = ?, offer_token = ?, updated_at = NOW()
                 WHERE id = ? AND status = 'in_progress'`,
                [plotIdInt, JSON.stringify(visited), JSON.stringify(notifiedDriverIds), offerToken, cycleId]
            );
        } catch (e) {
            console.error(`[PlotDispatch] Cycle round update error:`, e.message);
            return;
        }

        const [bookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        if (!bookingRows.length) {
            return;
        }
        const booking = bookingRows[0];

        if (['cancelled', 'completed'].includes(booking.booking_status)) {
            clearPlotDispatchSession(bookingIdInt);
            return;
        }
        if (booking.driver) {
            clearPlotDispatchSession(bookingIdInt);
            return;
        }

        const dispatchAmount = (
            booking.booking_amount === null || booking.booking_amount === undefined || booking.booking_amount == 0
        ) ? (booking.offered_amount ?? null) : booking.booking_amount;

        const timeoutSeconds = Math.round(timeoutMs / 1000);
        const actionMessage = broadcastAction(drivers.length, plotIdInt, isBackupPlot, timeoutSeconds);

        try {
            await db.query(
                `UPDATE bookings SET driver = NULL, booking_amount = ?, booking_status = 'pending', dispatcher_action = ? WHERE id = ?`,
                [dispatchAmount, actionMessage, bookingIdInt]
            );
        } catch (e) {
            console.error(`[PlotDispatch] Booking round update error:`, e.message);
            return;
        }

        const [updatedRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        const updatedBooking = updatedRows[0];

        for (const driver of drivers) {
            const driverSocketId = driverSockets.get(String(driver.id).trim());
            if (driverSocketId) {
                io.to(driverSocketId).emit('new-ride-request', {
                    booking_id: updatedBooking.id,
                    assignment_type: 'plot_dispatch',
                    message: `You have a new ride request in ${isBackupPlot ? 'backup plot' : 'your plot'}`,
                    booking: updatedBooking,
                    plot_id: plotIdInt,
                    expires_in_seconds: timeoutMs / 1000,
                    driver: summarizeDriver(driver),
                });
            }

            try {
                await sendNotificationToDriver(
                    db,
                    driver.id,
                    'New Ride Available',
                    'You have a new ride request in your plot',
                    { booking_id: String(updatedBooking.id), type: 'new_ride' }
                );
            } catch (e) {
                console.error(`[PlotDispatch] FCM error for driver #${driver.id}:`, e.message);
            }

            try {
                await db.query(
                    'INSERT INTO send_new_rides (booking_id, driver_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                    [bookingIdInt, driver.id]
                );
            } catch (e) {
                console.error(`[PlotDispatch] send_new_rides insert error:`, e.message);
            }
        }

        const statusPayload = await buildDispatchStatusPayload({
            db,
            bookingIdInt,
            cycleId,
            plotIdInt,
            plotChain,
            visitedPlots: visited,
            drivers,
            rejectedDriverIds: [],
            timeoutMs,
            isBackupPlot,
            phase: isBackupPlot ? 'backup' : 'primary',
            booking: updatedBooking,
            primaryPlotId: resolvedPrimaryPlotId,
        });

        emitToCompanyRooms(dbName, 'driver-assignment-pending', statusPayload);
        await emitPlotDispatchStatus(
            dbName,
            isFreshCycle ? 'plot-dispatch-started' : (isBackupPlot ? 'plot-dispatch-backup-round' : 'plot-dispatch-round'),
            statusPayload
        );
        emitBookingUpdated(dbName, updatedBooking);

        if (isFreshCycle) {
            dispatcherSockets.forEach((sid) => io.to(sid).emit('notification-ride', updatedBooking));
            adminSockets.forEach((sid) => io.to(sid).emit('notification-ride', updatedBooking));
        }

        clearPlotDispatchSession(bookingIdInt);

        const sessionState = {
            offerToken,
            tenantDb,
            dbName,
            cycleId,
            plotIdInt,
            plotChain,
            visitedPlots: visited,
            notifiedDriverIds,
            rejectedDriverIds: [],
            timeoutMs,
            primaryPlotId: resolvedPrimaryPlotId,
        };

        const timeoutId = setTimeout(async () => {
            try {
                const session = plotDispatchSessions.get(String(bookingIdInt));
                if (!session || session.offerToken !== offerToken) {
                    return;
                }
                plotDispatchSessions.delete(String(bookingIdInt));

                const [cycleRows] = await db.query(
                    'SELECT id, status, offer_token FROM booking_dispatch_cycles WHERE id = ?',
                    [cycleId]
                );
                if (!cycleRows.length || cycleRows[0].status !== 'in_progress' || cycleRows[0].offer_token !== offerToken) {
                    return;
                }

                const [checkRows] = await db.query(
                    'SELECT booking_status, driver FROM bookings WHERE id = ?',
                    [bookingIdInt]
                );
                if (!checkRows.length) {
                    return;
                }

                const { booking_status: status, driver: assignedDriver } = checkRows[0];
                if (['cancelled', 'completed', 'ongoing', 'started'].includes(status) || assignedDriver) {
                    return;
                }

                await advancePlotRoundOrExhaust({
                    bookingIdInt,
                    tenantDb,
                    db,
                    dbName,
                    cycleId,
                    plotIdInt,
                    plotChain,
                    visitedPlots: visited,
                    timeoutMs,
                    reason: drivers.length ? 'timeout' : 'no_drivers',
                });
            } catch (e) {
                console.error(`[PlotDispatch] Timeout handler error:`, e.message);
            }
        }, timeoutMs);

        if (!drivers.length) {
            clearPlotDispatchSession(bookingIdInt);
            await advancePlotRoundOrExhaust({
                bookingIdInt,
                tenantDb,
                db,
                dbName,
                cycleId,
                plotIdInt,
                plotChain,
                visitedPlots: visited,
                timeoutMs,
                reason: 'no_drivers',
            });
            return;
        }

        plotDispatchSessions.set(String(bookingIdInt), { ...sessionState, timeoutId });
    };

    const startPlotDispatchCycle = async ({ bookingId, tenantDb }) => {
        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;
        const bookingIdInt = parseInt(bookingId, 10);

        console.log(`[PlotDispatch] Starting cycle for booking #${bookingIdInt}`);

        const connection = await db.getConnection();

        try {
            await connection.beginTransaction();

            const [bookingRows] = await connection.query(
                'SELECT * FROM bookings WHERE id = ? FOR UPDATE',
                [bookingIdInt]
            );
            if (!bookingRows.length) {
                await connection.rollback();
                console.log(`[PlotDispatch] Booking #${bookingIdInt} not found`);
                return { started: false, reason: 'booking_not_found' };
            }

            const booking = bookingRows[0];

            if (['cancelled', 'completed'].includes(booking.booking_status)) {
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

            if (existingCycles.length) {
                const existing = existingCycles[0];
                if (['in_progress', 'accepted'].includes(existing.status)) {
                    await connection.rollback();
                    console.log(`[PlotDispatch] Cycle already ${existing.status} for booking #${bookingIdInt}`);
                    return { started: false, reason: 'cycle_already_active', dispatch_cycle_id: existing.id };
                }
            }

            let primaryPlotId = booking.pickup_plot_id ? parseInt(booking.pickup_plot_id, 10) : null;
            if (!primaryPlotId) {
                primaryPlotId = await waitingQueue.resolvePlotIdFromBooking(db, booking);
                if (primaryPlotId) {
                    await connection.query(
                        'UPDATE bookings SET pickup_plot_id = ? WHERE id = ?',
                        [primaryPlotId, bookingIdInt]
                    );
                    booking.pickup_plot_id = primaryPlotId;
                }
            }

            if (!primaryPlotId) {
                await connection.rollback();
                console.log(`[PlotDispatch] No pickup plot resolved for booking #${bookingIdInt}`);
                emitToCompanyRooms(dbName, 'plot-dispatch-failed', {
                    booking_id: bookingIdInt,
                    message: 'No pickup plot could be resolved for this booking',
                    booking,
                });
                return { started: false, reason: 'no_plot' };
            }

            let cycleId;
            if (existingCycles.length) {
                await connection.query(
                    `UPDATE booking_dispatch_cycles
                     SET primary_plot_id = ?, current_plot_id = ?, status = 'in_progress',
                         visited_plot_ids = ?, notified_driver_ids = ?, offer_token = 1, updated_at = NOW()
                     WHERE booking_id = ?`,
                    [primaryPlotId, primaryPlotId, JSON.stringify([String(primaryPlotId)]), JSON.stringify([]), bookingIdInt]
                );
                cycleId = existingCycles[0].id;
            } else {
                const [insertResult] = await connection.query(
                    `INSERT INTO booking_dispatch_cycles
                     (booking_id, primary_plot_id, current_plot_id, status, visited_plot_ids, notified_driver_ids, offer_token, created_at, updated_at)
                     VALUES (?, ?, ?, 'in_progress', ?, ?, 1, NOW(), NOW())`,
                    [bookingIdInt, primaryPlotId, primaryPlotId, JSON.stringify([String(primaryPlotId)]), JSON.stringify([])]
                );
                cycleId = insertResult.insertId;
            }

            await connection.commit();

            const plotChain = await loadBackupPlotChain(db, primaryPlotId);
            const timeoutMs = await getDispatchTimeoutMs(db);

            const startedMessage = startedAction(primaryPlotId);
            await db.query(
                'UPDATE bookings SET dispatcher_action = ? WHERE id = ?',
                [startedMessage, bookingIdInt]
            );
            const [startedBookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
            if (startedBookingRows[0]) {
                emitBookingUpdated(dbName, startedBookingRows[0]);
            }

            await broadcastPlotRound({
                bookingIdInt,
                tenantDb,
                db,
                dbName,
                cycleId,
                plotIdInt: primaryPlotId,
                plotChain,
                visitedPlots: [],
                timeoutMs,
                isFreshCycle: true,
                isBackupPlot: false,
                primaryPlotId,
            });

            return { started: true, dispatch_cycle_id: cycleId, primary_plot_id: primaryPlotId };
        } catch (e) {
            try {
                await connection.rollback();
            } catch (rollbackError) {
                console.error(`[PlotDispatch] Rollback error:`, rollbackError.message);
            }
            console.error(`[PlotDispatch] Start cycle error:`, e.message);
            throw e;
        } finally {
            connection.release();
        }
    };

    const handlePlotDispatchAccept = async ({ bookingIdInt, tenantDb, driverId }) => {
        const session = plotDispatchSessions.get(String(bookingIdInt));
        let notifiedDriverIds = session?.notifiedDriverIds ?? [];

        if (session) {
            clearPlotDispatchSession(bookingIdInt);
        }

        if (!tenantDb) {
            return;
        }

        try {
            const db = getConnection(tenantDb);
            const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;

            if (!notifiedDriverIds.length) {
                const [cycleRows] = await db.query(
                    'SELECT notified_driver_ids FROM booking_dispatch_cycles WHERE booking_id = ?',
                    [bookingIdInt]
                );
                notifiedDriverIds = parseJsonArray(cycleRows[0]?.notified_driver_ids);
            }

            if (notifiedDriverIds.length) {
                notifyDriversRideWithdrawn(notifiedDriverIds, driverId, bookingIdInt);
            }

            const [rows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
            if (!rows.length) {
                return;
            }

            const booking = rows[0];
            const [driverRows] = await db.query('SELECT id, name, profile_image FROM drivers WHERE id = ?', [driverId]);
            const driver = driverRows[0] ?? {};

            const eventData = {
                booking_id: bookingIdInt,
                driver_id: driverId,
                driver_name: driver.name ?? null,
                driver_profile_image: driver.profile_image ?? null,
                dispatcher_action: booking.dispatcher_action,
                booking,
                message: `Driver #${driverId} accepted the ride`,
            };

            emitToCompanyRooms(dbName, 'job-accepted-by-driver', eventData);
            emitBookingUpdated(dbName, booking);

            const [cycleRows] = await db.query(
                'SELECT id, primary_plot_id, current_plot_id, visited_plot_ids FROM booking_dispatch_cycles WHERE booking_id = ?',
                [bookingIdInt]
            );
            const cycle = cycleRows[0];
            if (cycle) {
                const visitedPlots = parseJsonArray(cycle.visited_plot_ids);
                const plotChain = await loadBackupPlotChain(db, cycle.primary_plot_id);
                const acceptedStatus = await buildDispatchStatusPayload({
                    db,
                    bookingIdInt,
                    cycleId: cycle.id,
                    plotIdInt: cycle.current_plot_id ? parseInt(cycle.current_plot_id, 10) : null,
                    plotChain,
                    visitedPlots,
                    drivers: [{ id: driverId, name: driver.name, profile_image: driver.profile_image }],
                    phase: 'accepted',
                    booking,
                    primaryPlotId: cycle.primary_plot_id ? parseInt(cycle.primary_plot_id, 10) : null,
                    isBackupPlot: String(cycle.current_plot_id) !== String(cycle.primary_plot_id),
                    timeoutMs: null,
                });
                await emitPlotDispatchStatus(dbName, 'plot-dispatch-accepted', acceptedStatus);
            }

            await broadcastDashboardCardsUpdate(tenantDb);
            await broadcastTodaysBookingsListUpdate(tenantDb, db, dbName, bookingIdInt);
        } catch (e) {
            console.error(`[PlotDispatch] Accept broadcast error:`, e.message);
        }
    };

    const handlePlotDispatchReject = async ({ bookingIdInt, tenantDb, driverId }) => {
        let session = plotDispatchSessions.get(String(bookingIdInt));

        if (!session && tenantDb) {
            try {
                const db = getConnection(tenantDb);
                const [rows] = await db.query(
                    'SELECT dispatcher_action FROM bookings WHERE id = ?',
                    [bookingIdInt]
                );
                if (!isPlotDispatchInProgress(rows[0]?.dispatcher_action)) {
                    return { handled: false };
                }
            } catch (e) {
                return { handled: false };
            }
        } else if (!session) {
            return { handled: false };
        }

        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;

        if (!session) {
            const [cycleRows] = await db.query(
                'SELECT id, primary_plot_id, current_plot_id, visited_plot_ids, notified_driver_ids, offer_token FROM booking_dispatch_cycles WHERE booking_id = ? AND status = ?',
                [bookingIdInt, 'in_progress']
            );
            if (!cycleRows.length) {
                return { handled: false };
            }

            const cycle = cycleRows[0];
            session = {
                cycleId: cycle.id,
                plotIdInt: cycle.current_plot_id ? parseInt(cycle.current_plot_id, 10) : null,
                plotChain: await loadBackupPlotChain(db, cycle.primary_plot_id),
                visitedPlots: parseJsonArray(cycle.visited_plot_ids),
                notifiedDriverIds: parseJsonArray(cycle.notified_driver_ids).map(String),
                rejectedDriverIds: [],
                timeoutMs: await getDispatchTimeoutMs(db),
                primaryPlotId: cycle.primary_plot_id ? parseInt(cycle.primary_plot_id, 10) : null,
                offerToken: cycle.offer_token,
                tenantDb,
                dbName,
            };
        }

        if (!session.notifiedDriverIds.includes(String(driverId))) {
            const [notifiedRows] = await db.query(
                'SELECT driver_id FROM send_new_rides WHERE booking_id = ? AND driver_id = ?',
                [bookingIdInt, driverId]
            );
            if (!notifiedRows.length) {
                return {
                    handled: true,
                    status: 400,
                    body: { success: false, message: 'Driver was not notified for this plot dispatch round' },
                };
            }
        }

        const rejectedDriverIds = [...new Set([...(session.rejectedDriverIds ?? []), String(driverId)])];
        session.rejectedDriverIds = rejectedDriverIds;

        const rejectEvent = {
            booking_id: bookingIdInt,
            driver_id: driverId,
            message: `Driver #${driverId} rejected the ride`,
            rejected_driver_ids: rejectedDriverIds,
            pending_driver_ids: session.notifiedDriverIds.filter((id) => !rejectedDriverIds.includes(String(id))),
        };
        emitToCompanyRooms(dbName, 'job-rejected-by-driver', rejectEvent);
        emitToCompanyRooms(dbName, 'plot-dispatch-driver-rejected', rejectEvent);

        const allRejected = session.notifiedDriverIds.length > 0
            && session.notifiedDriverIds.every((id) => rejectedDriverIds.includes(String(id)));

        if (allRejected) {
            console.log(`[PlotDispatch] All drivers rejected in plot ${session.plotIdInt} — advancing`);
            if (session.timeoutId) {
                clearTimeout(session.timeoutId);
            }
            plotDispatchSessions.delete(String(bookingIdInt));

            const [checkRows] = await db.query(
                'SELECT booking_status, driver FROM bookings WHERE id = ?',
                [bookingIdInt]
            );
            if (checkRows.length) {
                const { booking_status: status, driver: assignedDriver } = checkRows[0];
                if (!assignedDriver && status === 'pending') {
                    await advancePlotRoundOrExhaust({
                        bookingIdInt,
                        tenantDb,
                        db,
                        dbName,
                        cycleId: session.cycleId,
                        plotIdInt: session.plotIdInt,
                        plotChain: session.plotChain,
                        visitedPlots: session.visitedPlots,
                        timeoutMs: session.timeoutMs,
                        reason: 'all_rejected',
                    });
                }
            }

            return {
                handled: true,
                status: 200,
                body: {
                    success: true,
                    message: 'All drivers in this plot rejected — advancing to the next plot if available',
                },
            };
        }

        if (plotDispatchSessions.has(String(bookingIdInt))) {
            plotDispatchSessions.set(String(bookingIdInt), session);
        }

        console.log(`[PlotDispatch] Driver #${driverId} rejected booking #${bookingIdInt} — waiting for other drivers`);

        return {
            handled: true,
            status: 200,
            body: {
                success: true,
                message: 'Reject noted — other drivers in this plot may still accept until the timer expires',
                rejected_driver_ids: rejectedDriverIds,
            },
        };
    };

    const getPlotDispatchStatus = async ({ bookingId, tenantDb }) => {
        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;
        const bookingIdInt = parseInt(bookingId, 10);

        const [bookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        if (!bookingRows.length) {
            return { found: false };
        }

        const booking = bookingRows[0];
        const [cycleRows] = await db.query(
            'SELECT * FROM booking_dispatch_cycles WHERE booking_id = ?',
            [bookingIdInt]
        );
        const cycle = cycleRows[0] ?? null;
        const session = plotDispatchSessions.get(String(bookingIdInt));

        if (!cycle) {
            return {
                found: true,
                active: false,
                booking,
                dispatch_type: 'auto_dispatch_plot_base',
            };
        }

        const plotChain = cycle.primary_plot_id
            ? await loadBackupPlotChain(db, cycle.primary_plot_id)
            : [];
        const visitedPlots = parseJsonArray(cycle.visited_plot_ids);
        const notifiedIds = parseJsonArray(cycle.notified_driver_ids).map(String);
        const rejectedDriverIds = session?.rejectedDriverIds ?? [];

        let drivers = [];
        if (cycle.current_plot_id) {
            drivers = await fetchIdleDriversInPlot(db, parseInt(cycle.current_plot_id, 10), dbName);
            if (notifiedIds.length) {
                drivers = drivers.filter((driver) => notifiedIds.includes(String(driver.id)));
            }
        }

        const isBackupPlot = cycle.primary_plot_id
            && String(cycle.current_plot_id) !== String(cycle.primary_plot_id);
        const phase = cycle.status === 'accepted'
            ? 'accepted'
            : (cycle.status === 'exhausted' ? 'exhausted' : (isBackupPlot ? 'backup' : 'primary'));

        const status = await buildDispatchStatusPayload({
            db,
            bookingIdInt,
            cycleId: cycle.id,
            plotIdInt: cycle.current_plot_id ? parseInt(cycle.current_plot_id, 10) : null,
            plotChain,
            visitedPlots,
            drivers,
            rejectedDriverIds,
            timeoutMs: session?.timeoutMs ?? await getDispatchTimeoutMs(db),
            isBackupPlot,
            phase,
            booking,
            primaryPlotId: cycle.primary_plot_id ? parseInt(cycle.primary_plot_id, 10) : null,
        });

        return {
            found: true,
            active: cycle.status === 'in_progress',
            ...status,
        };
    };

    return {
        PLOT_DISPATCH_ACTIVE_PREFIX,
        isPlotDispatchActive,
        clearPlotDispatchSession,
        startPlotDispatchCycle,
        handlePlotDispatchAccept,
        handlePlotDispatchReject,
        getPlotDispatchStatus,
        plotDispatchSessions,
    };
};

module.exports = { createPlotDispatchService, PLOT_DISPATCH_ACTIVE_PREFIX };
