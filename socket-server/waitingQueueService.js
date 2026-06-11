const DEFAULT_SEARCH_RADIUS_KM = 6;

const pointInPolygon = (lat, lng, polygon) => {
    if (!Array.isArray(polygon) || polygon.length === 0) {
        return false;
    }

    if (polygon.length === 2 && Array.isArray(polygon[0]) && Array.isArray(polygon[1])) {
        const lng1 = polygon[0][0];
        const lat1 = polygon[0][1];
        const lng2 = polygon[1][0];
        const lat2 = polygon[1][1];

        return (
            lat >= Math.min(lat1, lat2) &&
            lat <= Math.max(lat1, lat2) &&
            lng >= Math.min(lng1, lng2) &&
            lng <= Math.max(lng1, lng2)
        );
    }

    let inside = false;
    const x = lng;
    const y = lat;
    const numPoints = polygon.length;

    for (let i = 0, j = numPoints - 1; i < numPoints; j = i++) {
        const xi = polygon[i][0];
        const yi = polygon[i][1];
        const xj = polygon[j][0];
        const yj = polygon[j][1];

        const intersect = ((yi > y) !== (yj > y)) &&
            (x < ((xj - xi) * (y - yi)) / (yj - yi) + xi);

        if (intersect) {
            inside = !inside;
        }
    }

    return inside;
};

const parsePlotPolygon = (plot) => {
    if (!plot?.features) {
        return null;
    }

    try {
        const features = typeof plot.features === 'string' ? JSON.parse(plot.features) : plot.features;
        const geometry = features.geometry || features;
        const rawCoords = geometry.coordinates;

        if (typeof rawCoords === 'string') {
            return JSON.parse(rawCoords)[0];
        }

        if (Array.isArray(rawCoords)) {
            return Array.isArray(rawCoords[0]?.[0]) ? rawCoords[0] : rawCoords;
        }
    } catch (error) {
        console.error('[WaitingQueue] Failed to parse plot polygon:', error.message);
    }

    return null;
};

const getPlotReferencePoint = (plot, booking) => {
    if (booking?.pickup_point && String(booking.pickup_point).includes(',')) {
        const [latStr, lngStr] = String(booking.pickup_point).split(',');
        const lat = parseFloat(latStr.trim());
        const lng = parseFloat(lngStr.trim());

        if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
            return { lat, lng };
        }
    }

    const polygon = parsePlotPolygon(plot);
    if (polygon?.[0]) {
        return { lat: polygon[0][1], lng: polygon[0][0] };
    }

    return null;
};

const createWaitingQueueService = ({
    io,
    plotDriverQueues,
    driverLastLocationTime,
    getConnection,
    RECONNECTING_THRESHOLD_MS,
}) => {
    const plotKeyFor = (plotId, database) => `${plotId}_${database}`;

    const getSearchRadiusKm = async (db) => {
        try {
            const [settingsRows] = await db.query(
                'SELECT search_radius FROM settings ORDER BY id DESC LIMIT 1'
            );

            if (settingsRows.length && settingsRows[0].search_radius) {
                const radius = parseFloat(settingsRows[0].search_radius);
                if (!Number.isNaN(radius) && radius > 0) {
                    return radius;
                }
            }
        } catch (error) {
            console.error('[WaitingQueue] Settings fetch error:', error.message);
        }

        return DEFAULT_SEARCH_RADIUS_KM;
    };

    const persistPlotQueue = async (db, plotId, queue) => {
        await db.query('DELETE FROM plot_driver_queues WHERE plot_id = ?', [plotId]);

        for (const entry of queue) {
            await db.query(
                `INSERT INTO plot_driver_queues (plot_id, driver_id, rank, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())`,
                [plotId, entry.driver_id, entry.rank]
            );
        }
    };

    const loadPlotQueueFromDb = async (db, plotId, database) => {
        const plotKey = plotKeyFor(plotId, database);

        try {
            const [rows] = await db.query(
                'SELECT driver_id, rank FROM plot_driver_queues WHERE plot_id = ? ORDER BY rank ASC, id ASC',
                [plotId]
            );

            const queue = rows.map((row) => ({
                driver_id: String(row.driver_id),
                rank: row.rank,
            }));

            plotDriverQueues.set(plotKey, queue);
            return queue;
        } catch (error) {
            console.error('[WaitingQueue] Failed to load queue from DB:', error.message);
            return plotDriverQueues.get(plotKey) || [];
        }
    };

    const setPlotQueue = async (db, plotId, database, queue) => {
        const normalized = queue.map((entry, index) => ({
            driver_id: String(entry.driver_id),
            rank: index + 1,
        }));

        plotDriverQueues.set(plotKeyFor(plotId, database), normalized);

        try {
            await persistPlotQueue(db, plotId, normalized);
        } catch (error) {
            console.error('[WaitingQueue] Failed to persist queue:', error.message);
        }

        return normalized;
    };

    const resolvePlotIdFromBooking = async (db, booking) => {
        if (booking?.pickup_plot_id) {
            return parseInt(booking.pickup_plot_id, 10);
        }

        if (booking?.destination_plot_id) {
            return parseInt(booking.destination_plot_id, 10);
        }

        if (!booking?.pickup_point || !String(booking.pickup_point).includes(',')) {
            return null;
        }

        const [latStr, lngStr] = String(booking.pickup_point).split(',');
        const lat = parseFloat(latStr.trim());
        const lng = parseFloat(lngStr.trim());

        if (Number.isNaN(lat) || Number.isNaN(lng)) {
            return null;
        }

        try {
            const [plots] = await db.query('SELECT id, features FROM plots ORDER BY id DESC');

            for (const plot of plots) {
                const polygon = parsePlotPolygon(plot);
                if (polygon && pointInPolygon(lat, lng, polygon)) {
                    return parseInt(plot.id, 10);
                }
            }
        } catch (error) {
            console.error('[WaitingQueue] Plot resolution error:', error.message);
        }

        return null;
    };

    const findWaitingDriversForPlot = async (db, plotId, booking) => {
        const searchRadius = await getSearchRadiusKm(db);
        const [plotRows] = await db.query('SELECT id, name, features FROM plots WHERE id = ? LIMIT 1', [plotId]);
        const plot = plotRows[0] || { id: plotId, name: `Plot #${plotId}` };
        const reference = getPlotReferencePoint(plot, booking);

        const [inPlotDrivers] = await db.query(
            `SELECT d.id, d.name, d.plot_id, d.latitude, d.longitude, p.name AS plot_name
             FROM drivers d
             LEFT JOIN plots p ON d.plot_id = p.id
             WHERE d.plot_id = ?
               AND d.driving_status = 'idle'
               AND d.online_status = 'online'
             ORDER BY d.id ASC`,
            [plotId]
        );

        let nearestDrivers = [];

        if (reference) {
            const [rows] = await db.query(
                `SELECT d.id, d.name, d.plot_id, d.latitude, d.longitude, p.name AS plot_name,
                        (6371 * acos(
                            cos(radians(?)) * cos(radians(d.latitude)) * cos(radians(d.longitude) - radians(?))
                            + sin(radians(?)) * sin(radians(d.latitude))
                        )) AS distance
                 FROM drivers d
                 LEFT JOIN plots p ON d.plot_id = p.id
                 WHERE d.driving_status = 'idle'
                   AND d.online_status = 'online'
                   AND d.latitude IS NOT NULL
                   AND d.longitude IS NOT NULL
                 HAVING distance IS NOT NULL AND distance <= ?
                 ORDER BY distance ASC
                 LIMIT 50`,
                [reference.lat, reference.lng, reference.lat, searchRadius]
            );

            nearestDrivers = rows;
        }

        const merged = new Map();

        for (const driver of inPlotDrivers) {
            merged.set(String(driver.id), { ...driver, distance: 0 });
        }

        for (const driver of nearestDrivers) {
            const key = String(driver.id);
            if (!merged.has(key)) {
                merged.set(key, driver);
            }
        }

        const ranked = Array.from(merged.values())
            .sort((a, b) => {
                const distanceA = Number(a.distance ?? 9999);
                const distanceB = Number(b.distance ?? 9999);
                if (distanceA !== distanceB) {
                    return distanceA - distanceB;
                }

                return Number(a.id) - Number(b.id);
            })
            .map((driver, index) => ({
                driver_id: String(driver.id),
                rank: index + 1,
            }));

        return {
            plot,
            searchRadius,
            queue: ranked,
        };
    };

    const buildDriverPayload = async (db, database, plotId, queue, bookingId = null) => {
        if (!queue.length) {
            return {
                plot_id: plotId,
                booking_id: bookingId,
                drivers: [],
            };
        }

        const driverIds = queue.map((entry) => entry.driver_id);
        const [drivers] = await db.query(
            `SELECT d.id, d.name, d.plot_id, d.latitude, d.longitude, p.name AS plot_name
             FROM drivers d
             LEFT JOIN plots p ON d.plot_id = p.id
             WHERE d.id IN (?)`,
            [driverIds]
        );

        const now = Date.now();
        const plotName = drivers[0]?.plot_name || `Plot #${plotId}`;

        const payloadDrivers = queue
            .map((entry) => {
                const driver = drivers.find((row) => String(row.id) === String(entry.driver_id));
                if (!driver) {
                    return null;
                }

                const lastUpdate = driverLastLocationTime.get(String(driver.id)) || 0;
                const timeSince = now - lastUpdate;
                const isReconnecting = lastUpdate > 0 && timeSince > RECONNECTING_THRESHOLD_MS;

                return {
                    driver_id: driver.id,
                    driver_name: driver.name,
                    plot_id: plotId,
                    plot_name: driver.plot_name || plotName,
                    rank: entry.rank,
                    latitude: driver.latitude,
                    longitude: driver.longitude,
                    is_reconnecting: isReconnecting,
                };
            })
            .filter(Boolean);

        return {
            plot_id: plotId,
            booking_id: bookingId,
            drivers: payloadDrivers,
        };
    };

    const broadcastPlotRankUpdate = async (database, plotId, bookingId = null) => {
        if (!plotId) {
            return null;
        }

        const db = getConnection(`tenant${database}`);
        const queue = await loadPlotQueueFromDb(db, plotId, database);
        const payload = await buildDriverPayload(db, database, plotId, queue, bookingId);

        const response = {
            success: true,
            database,
            plot_id: payload.plot_id,
            booking_id: payload.booking_id,
            drivers: payload.drivers,
            total_idle_drivers: payload.drivers.length,
        };

        io.to(`driver_${database}`).emit('my-rank-update', response);
        io.to(`dispatcher_${database}`).emit('my-rank-update', response);
        io.to(`admin_${database}`).emit('my-rank-update', response);
        io.to(`client_${database}`).emit('my-rank-update', response);

        payload.drivers.forEach((driver) => {
            const legacyEvent = {
                driver_id: driver.driver_id,
                plot: plotId,
                rank: driver.rank,
                is_reconnecting: driver.is_reconnecting,
            };

            io.to(`dispatcher_${database}`).emit('waiting-driver-rank-updated', legacyEvent);
            io.to(`admin_${database}`).emit('waiting-driver-rank-updated', legacyEvent);
            io.to(`client_${database}`).emit('waiting-driver-rank-updated', legacyEvent);
        });

        return response;
    };

    const broadcastAllPlotRankUpdates = async (database, bookingId = null) => {
        const db = getConnection(`tenant${database}`);
        const plotIds = new Set();

        for (const plotKey of plotDriverQueues.keys()) {
            if (!plotKey.endsWith(`_${database}`)) {
                continue;
            }

            const plotId = plotKey.slice(0, plotKey.length - (`_${database}`).length);
            if (plotId) {
                plotIds.add(plotId);
            }
        }

        if (plotIds.size === 0) {
            try {
                const [rows] = await db.query('SELECT DISTINCT plot_id FROM plot_driver_queues');
                rows.forEach((row) => plotIds.add(String(row.plot_id)));
            } catch (error) {
                console.error('[WaitingQueue] Failed to load plot ids:', error.message);
            }
        }

        const responses = [];
        for (const plotId of plotIds) {
            responses.push(await broadcastPlotRankUpdate(database, plotId, bookingId));
        }

        return responses;
    };

    const refreshPlotWaitingQueueForBooking = async (tenantDb, booking, bookingId = null) => {
        const db = getConnection(tenantDb);
        const database = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;
        const plotId = await resolvePlotIdFromBooking(db, booking);

        if (!plotId) {
            console.log('[WaitingQueue] No plot resolved for booking', booking?.id || bookingId);
            return null;
        }

        const { queue } = await findWaitingDriversForPlot(db, plotId, booking);
        await setPlotQueue(db, plotId, database, queue);

        return broadcastPlotRankUpdate(database, plotId, bookingId || booking?.id || null);
    };

    const getOrAssignRank = async (db, plotId, database, driverId) => {
        const plotKey = plotKeyFor(plotId, database);
        let queue = plotDriverQueues.get(plotKey) || [];

        if (!queue.length) {
            queue = await loadPlotQueueFromDb(db, plotId, database);
        }

        const driverIdStr = String(driverId);
        const existing = queue.find((entry) => entry.driver_id === driverIdStr);
        if (existing) {
            return existing.rank;
        }

        queue.push({ driver_id: driverIdStr, rank: queue.length + 1 });
        await setPlotQueue(db, plotId, database, queue);

        return queue.find((entry) => entry.driver_id === driverIdStr)?.rank ?? queue.length;
    };

    const removeFromQueue = async (db, driverId, database) => {
        const driverIdStr = String(driverId);
        let changedPlots = [];

        for (const [plotKey, queue] of plotDriverQueues.entries()) {
            if (!plotKey.endsWith(`_${database}`)) {
                continue;
            }

            const index = queue.findIndex((entry) => entry.driver_id === driverIdStr);
            if (index === -1) {
                continue;
            }

            queue.splice(index, 1);
            queue.forEach((entry, idx) => {
                entry.rank = idx + 1;
            });

            const plotId = plotKey.slice(0, plotKey.length - (`_${database}`).length);
            plotDriverQueues.set(plotKey, queue);

            try {
                await persistPlotQueue(db, plotId, queue);
            } catch (error) {
                console.error('[WaitingQueue] Failed to persist queue removal:', error.message);
            }

            changedPlots.push(plotId);
        }

        return changedPlots;
    };

    const updateDriverRankInQueue = async (database, plotId, driverId, newRank) => {
        const plotKey = plotKeyFor(plotId, database);
        const db = getConnection(`tenant${database}`);

        let queue = plotDriverQueues.get(plotKey) || [];
        if (!queue.length) {
            queue = await loadPlotQueueFromDb(db, plotId, database);
        }

        if (!queue.length) {
            return { success: 0, message: 'Queue not found for this plot' };
        }

        const driverIdStr = String(driverId);
        const currentIndex = queue.findIndex((entry) => entry.driver_id === driverIdStr);

        if (currentIndex === -1) {
            return { success: 0, message: 'Driver not found in waiting queue' };
        }

        const parsedRank = parseInt(newRank, 10);
        if (Number.isNaN(parsedRank) || parsedRank < 1 || parsedRank > queue.length) {
            return {
                success: 0,
                message: `Invalid rank. Rank must be between 1 and ${queue.length}`,
            };
        }

        const [driverEntry] = queue.splice(currentIndex, 1);
        const targetIndex = Math.min(parsedRank - 1, queue.length);
        queue.splice(targetIndex, 0, driverEntry);
        queue.forEach((entry, index) => {
            entry.rank = index + 1;
        });

        await setPlotQueue(db, plotId, database, queue);

        return { success: 1, message: 'Rank updated successfully' };
    };

    const applyDriverRankUpdate = async (database, plotId, driverId, newRank) => {
        const result = await updateDriverRankInQueue(database, plotId, driverId, newRank);
        if (result.success !== 1) {
            return result;
        }

        await broadcastPlotRankUpdate(database, plotId, null);
        return result;
    };

    return {
        getSearchRadiusKm,
        resolvePlotIdFromBooking,
        findWaitingDriversForPlot,
        refreshPlotWaitingQueueForBooking,
        broadcastPlotRankUpdate,
        broadcastAllPlotRankUpdates,
        getOrAssignRank,
        removeFromQueue,
        applyDriverRankUpdate,
        loadPlotQueueFromDb,
        setPlotQueue,
        buildDriverPayload,
        plotKeyFor,
    };
};

module.exports = {
    DEFAULT_SEARCH_RADIUS_KM,
    pointInPolygon,
    createWaitingQueueService,
};
