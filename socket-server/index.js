const path = require("path");
require("dotenv").config({
    path: path.join(__dirname, "../.env"),
});

const express = require("express");
const cors = require("cors");
const http = require("http");
const { Server } = require("socket.io");
const PDFDocument = require('pdfkit');
const axios = require("axios");
const { getConnection } = require("./db")
const transporter = require("./utils/Emailconfig");
const { sendToDevice, sendNotificationToDriver, sendNotificationToUser } = require("./utils/FCMService");
const { getBookingConfirmationEmail } = require("./utils/Emailtemplate");

console.log("Loaded VIP Token:", process.env.VIP_WEBHOOK_TOKEN);

const app = express();
const server = http.createServer(app);

const io = new Server(server, {
    cors: {
        origin: "*"
    }
});

const driverSockets = new Map();
const userSockets = new Map();
const dispatcherSockets = new Map();
const clientSockets = new Map();
const adminSockets = new Map();

const plotDriverQueues = new Map();

const getOrAssignRank = (plotKey, driverId) => {
    if (!plotDriverQueues.has(plotKey)) {
        plotDriverQueues.set(plotKey, []);
    }
    const queue = plotDriverQueues.get(plotKey);

    const existing = queue.find(d => d.driver_id === driverId.toString());
    if (existing) {
        console.log(`[WaitingQueue] Driver #${driverId} already in ${plotKey} with rank ${existing.rank}`);
        return existing.rank;
    }

    const newRank = queue.length + 1;
    queue.push({ driver_id: driverId.toString(), rank: newRank });
    plotDriverQueues.set(plotKey, queue);

    console.log(`[WaitingQueue] Plot ${plotKey} → Driver #${driverId} assigned rank ${newRank}`);
    console.log(`[WaitingQueue] Current queue:`, queue);

    return newRank;
};

const removeFromQueue = (driverId, database) => {
    plotDriverQueues.forEach((queue, plotKey) => {
        if (!plotKey.endsWith(`_${database}`)) return;

        const index = queue.findIndex(d => d.driver_id === driverId.toString());
        if (index === -1) return;

        queue.splice(index, 1);

        queue.forEach((d, i) => { d.rank = i + 1; });

        console.log(`[WaitingQueue] Driver #${driverId} removed from ${plotKey}. Updated queue:`, queue);
    });
};

const getQueueSnapshot = (plotKey) => {
    return plotDriverQueues.get(plotKey) || [];
};

const storeNotification = async (db, { user_type, user_id, title, message }) => {
    try {
        await db.query(
            `INSERT INTO notifications (user_type, user_id, title, message, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'unread', NOW(), NOW())`,
            [user_type, user_id, title, message]
        );
        console.log(`🔔 Notification stored → [${user_type} #${user_id}] ${title}`);
    } catch (error) {
        console.error("❌ Failed to store notification:", error.message);
    }
};

const broadcastDashboardCardsUpdate = async (tenantDb) => {
    try {
        const db = getConnection(tenantDb);

        const query = `
            SELECT
                COUNT(CASE 
                    WHEN DATE(booking_date) = CURDATE() 
                    THEN 1 
                END) AS todays_booking,

                COUNT(CASE 
                    WHEN DATE(booking_date) > CURDATE() 
                    THEN 1 
                END) AS pre_bookings,

                COUNT(CASE 
                    WHEN booking_status = 'completed' 
                    THEN 1 
                END) AS completed,

                COUNT(CASE 
                    WHEN booking_status IN ('no_show', 'arrived', 'ongoing')
                    THEN 1 
                END) AS no_show,

                COUNT(CASE 
                    WHEN booking_status = 'cancelled' 
                    THEN 1 
                END) AS cancelled,

                COUNT(CASE 
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
                    THEN 1 
                END) AS recent_jobs
            FROM bookings
        `;

        const [[counts]] = await db.query(query);

        const dashboardData = {
            todaysBooking: counts.todays_booking,
            preBookings: counts.pre_bookings,
            recentJobs: counts.recent_jobs,
            completed: counts.completed,
            noShow: counts.no_show,
            cancelled: counts.cancelled
        };

        const dbName = tenantDb.startsWith("tenant") ? tenantDb.replace("tenant", "") : tenantDb;

        console.log("Broadcasting dashboard cards update to company:", dbName);

        io.to(`dispatcher_${dbName}`).emit("dashboard-cards-update", dashboardData);
        io.to(`admin_${dbName}`).emit("dashboard-cards-update", dashboardData);
        io.to(`client_${dbName}`).emit("dashboard-cards-update", dashboardData);

        return dashboardData;
    } catch (error) {
        console.error("Error broadcasting dashboard cards:", error);
        return null;
    }
};

const broadcastUpdatedQueue = (plotId, database) => {
    const plotKey = `${plotId}_${database}`;
    const queue = getQueueSnapshot(plotKey);

    console.log(`[WaitingQueue] Broadcasting updated queue for ${plotKey}:`, queue);

    queue.forEach(({ driver_id, rank }) => {
        io.to(`dispatcher_${database}`).emit("waiting-driver-rank-updated", {
            driver_id,
            plot: plotId,
            rank
        });
        io.to(`admin_${database}`).emit("waiting-driver-rank-updated", {
            driver_id,
            plot: plotId,
            rank
        });
        io.to(`client_${database}`).emit("waiting-driver-rank-updated", {
            driver_id,
            plot: plotId,
            rank
        });
    });
};

const autoDispatchRide = async ({
    bookingId,
    tenantDb,
    currentPlotId = null,
    driverIndex = 0,
    visitedPlots = []
}) => {
    try {
        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith("tenant")
            ? tenantDb.slice("tenant".length)
            : tenantDb;

        console.log(`[AutoDispatch] Connected sockets (${driverSockets.size}):`,
            Array.from(driverSockets.entries()).map(([id, sid]) => `driver#${id}→${sid}`)
        );

        const [bookingRows] = await db.query(
            "SELECT * FROM bookings WHERE id = ?",
            [bookingId]
        );
        if (!bookingRows.length) {
            console.log(`[AutoDispatch] Booking #${bookingId} not found`);
            return;
        }
        const booking = bookingRows[0];

        if (["cancelled", "completed"].includes(booking.booking_status)) {
            console.log(`[AutoDispatch] ⏹ Already ${booking.booking_status}. Stop.`);
            return;
        }
        if (booking.booking_status === "ongoing" && booking.driver) {
            console.log(`[AutoDispatch] ⏹ Already ongoing. Stop.`);
            return;
        }

        if (!currentPlotId) {
            currentPlotId = booking.pickup_plot_id || booking.destination_plot_id;
        }
        if (!currentPlotId) {
            console.log("[AutoDispatch] No plot on booking. Stop.");
            return;
        }
        console.log(`[AutoDispatch] Plot ID = ${currentPlotId}`);

        const [allInPlot] = await db.query(
            `SELECT id, name, driving_status FROM drivers WHERE plot_id = ?`,
            [currentPlotId]
        );
        console.log(`[AutoDispatch] All drivers in plot ${currentPlotId}:`,
            allInPlot.length
                ? allInPlot.map(d => `#${d.id} ${d.name} [${d.driving_status}]`)
                : "NONE"
        );

        let [drivers] = await db.query(
            `SELECT * FROM drivers WHERE driving_status = 'idle' AND plot_id = ? ORDER BY priority_plot ASC`,
            [currentPlotId]
        );
        console.log(`[AutoDispatch] Idle drivers in plot ${currentPlotId}: ${drivers.length}`);


        if (!drivers.length && driverIndex === 0) {
            console.log(`[AutoDispatch] No idle drivers in plot. Trying nearest driver (any status) within 10km...`);

            if (booking.pickup_point && booking.pickup_point.includes(',')) {
                const parts = booking.pickup_point.split(",").map(c => parseFloat(c.trim()));
                const lat = parts[0];
                const lng = parts[1];
                console.log(`[AutoDispatch] Pickup: lat=${lat} lng=${lng}`);

                const [nearestDrivers] = await db.query(`
                    SELECT *,
                        (6371 * acos(
                            cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?))
                            + sin(radians(?)) * sin(radians(latitude))
                        )) AS distance
                    FROM drivers
                    WHERE driving_status = 'idle'
                    HAVING distance < 10
                    ORDER BY distance ASC
                    LIMIT 5
                `, [lat, lng, lat]);

                console.log(`[AutoDispatch] Nearest idle drivers within 10km: ${nearestDrivers.length}`,
                    nearestDrivers.map(d => `#${d.id} ${d.name} [${d.driving_status}] (${Number(d.distance || 0).toFixed(2)}km)`)
                );

                if (nearestDrivers.length) {
                    drivers = nearestDrivers;
                } else {
                    console.log(`[AutoDispatch] No idle drivers near pickup. Checking socket-connected drivers as last resort...`);

                    const connectedDriverIds = Array.from(driverSockets.keys()); // e.g. ["6", "7"]
                    console.log(`[AutoDispatch] Socket-connected driver IDs:`, connectedDriverIds);

                    if (connectedDriverIds.length > 0) {
                        const placeholders = connectedDriverIds.map(() => '?').join(',');
                        const [connectedDriverRows] = await db.query(
                            `SELECT * FROM drivers WHERE id IN (${placeholders}) ORDER BY id ASC`,
                            connectedDriverIds
                        );
                        console.log(`[AutoDispatch] Connected drivers from DB:`,
                            connectedDriverRows.map(d => `#${d.id} ${d.name} [${d.driving_status}]`)
                        );
                        if (connectedDriverRows.length) {
                            drivers = connectedDriverRows;
                            console.log(`[AutoDispatch] Using socket-connected drivers as fallback (status may be stale)`);
                        }
                    }
                }
            } else {
                console.log(`[AutoDispatch] pickup_point missing/invalid: "${booking.pickup_point}"`);
            }
        }

        if (!drivers.length || driverIndex >= drivers.length) {
            if (!visitedPlots.includes(String(currentPlotId))) {
                visitedPlots.push(String(currentPlotId));
            }

            const [plotRows] = await db.query(
                "SELECT backup_plots FROM plots WHERE id = ?",
                [currentPlotId]
            );
            let backupPlots = [];
            try { backupPlots = JSON.parse(plotRows[0]?.backup_plots || "[]"); } catch (e) { backupPlots = []; }
            console.log(`[AutoDispatch] Backup plots: ${JSON.stringify(backupPlots)} | Visited: ${JSON.stringify(visitedPlots)}`);

            const nextPlot = backupPlots.find(p => !visitedPlots.includes(String(p)));
            if (nextPlot) {
                console.log(`[AutoDispatch] Trying backup plot ${nextPlot}`);
                return autoDispatchRide({ bookingId, tenantDb, currentPlotId: nextPlot, driverIndex: 0, visitedPlots });
            }

            console.log("[AutoDispatch] All plots exhausted. No drivers found.");
            io.to(`dispatcher_${dbName}`).emit("auto-dispatch-failed", {
                booking_id: bookingId,
                message: "No drivers available in assigned or backup plots."
            });
            io.to(`admin_${dbName}`).emit("auto-dispatch-failed", {
                booking_id: bookingId,
                message: "No drivers available in assigned or backup plots."
            });
            return;
        }

        const driver = drivers[driverIndex];
        console.log(`[AutoDispatch] Selected: #${driver.id} "${driver.name}" [${driver.driving_status}] (index ${driverIndex})`);

        const dispatchAmount = (
            booking.booking_amount === null ||
            booking.booking_amount === undefined ||
            booking.booking_amount == 0
        ) ? (booking.offered_amount ?? null) : booking.booking_amount;

        await db.query(
            `UPDATE bookings SET driver = ?, booking_amount = ?, booking_status = 'pending_acceptance' WHERE id = ?`,
            [driver.id, dispatchAmount, bookingId]
        );
        console.log(`[AutoDispatch] Booking updated → driver=${driver.id} status=pending_acceptance`);

        const [updatedRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [bookingId]);
        const updatedBooking = updatedRows[0];

        const driverIdStr = String(driver.id).trim();
        const driverSocketId = driverSockets.get(driverIdStr);

        console.log(`[AutoDispatch] Socket lookup key: "${driverIdStr}"`);
        console.log(`[AutoDispatch] All map keys: [${Array.from(driverSockets.keys()).map(k => `"${k}"`).join(', ')}]`);
        console.log(`[AutoDispatch] Socket found: ${driverSocketId ? `${driverSocketId}` : '❌ NOT FOUND'}`);

        if (driverSocketId) {
            io.to(driverSocketId).emit("new-ride-request", {
                booking_id: updatedBooking.id,
                assignment_type: "auto_dispatch",
                message: "You have a new ride request",
                booking: updatedBooking
            });
            console.log(`[AutoDispatch] new-ride-request sent to driver #${driver.id}`);
        } else {
            console.log(`[AutoDispatch] Driver #${driver.id} not in socket map`);
        }

        dispatcherSockets.forEach((sid) => io.to(sid).emit("notification-ride", updatedBooking));
        adminSockets.forEach((sid) => io.to(sid).emit("notification-ride", updatedBooking));

        try {
            await sendNotificationToDriver(
                db, driver.id,
                "New Ride Available", "You have a new ride request",
                { booking_id: String(updatedBooking.id), type: "new_ride" }
            );
            console.log(`[AutoDispatch] FCM push sent to driver #${driver.id}`);
        } catch (notifErr) {
            console.error(`[AutoDispatch] FCM error:`, notifErr.message);
        }

        console.log(`[AutoDispatch] 30s timeout for driver #${driver.id}`);
        setTimeout(async () => {
            try {
                const [checkRows] = await db.query(
                    "SELECT booking_status, driver FROM bookings WHERE id = ?",
                    [bookingId]
                );
                if (!checkRows.length) return;

                const { booking_status: currentStatus, driver: currentDriver } = checkRows[0];
                console.log(`[AutoDispatch] Timeout check: status=${currentStatus} driver=${currentDriver}`);

                if (currentStatus === "ongoing") {
                    console.log(`[AutoDispatch] Accepted by driver #${driver.id}`);
                    return;
                }
                if (["cancelled", "completed"].includes(currentStatus)) {
                    console.log(`[AutoDispatch] ⏹ ${currentStatus}. Stop.`);
                    return;
                }
                if (currentStatus === "pending_acceptance" && String(currentDriver) === String(driver.id)) {
                    console.log(`[AutoDispatch] ⏭ No response. Trying next driver (index ${driverIndex + 1})...`);
                    await db.query(
                        `UPDATE bookings SET driver = NULL, booking_status = 'pending' WHERE id = ?`,
                        [bookingId]
                    );
                    autoDispatchRide({ bookingId, tenantDb, currentPlotId, driverIndex: driverIndex + 1, visitedPlots });
                }
            } catch (err) {
                console.error("[AutoDispatch] Timeout error:", err.message);
            }
        }, 30000);

    } catch (error) {
        console.error("[AutoDispatch] FATAL:", error.message, error.stack);
    }
};

io.use(async (socket, next) => {
    const authHeader = socket.handshake.headers.authorization;
    const driverId = socket.handshake.query.driver_id;
    const userId = socket.handshake.query.user_id;
    const adminId = socket.handshake.query.admin_id;
    const role = socket.handshake.query.role;
    const dispatcherId = socket.handshake.query.dispatcher_id;
    const clientId = socket.handshake.query.client_id;

    if (!authHeader || (role === 'driver' && !driverId) || (role === 'admin' && !adminId) ||
        (role === 'client' && !clientId) || (role === 'dispatcher' && !dispatcherId) ||
        (role === 'user' && !userId)) {
        return next(new Error("Unauthorized"));
    }

    socket.token = authHeader.split(" ")[1];
    socket.driverId = driverId;
    socket.dispatcherId = dispatcherId;
    socket.clientId = clientId;
    socket.userId = userId;
    socket.adminId = adminId;

    next();
});

io.on("connection", (socket) => {
    const role = socket.handshake.query.role;
    const driverId = socket.handshake.query.driver_id;
    const dispatcherId = socket.handshake.query.dispatcher_id;
    const userId = socket.handshake.query.user_id || socket.handshake.query.customer_id;
    const clientId = socket.handshake.query.client_id;
    const adminId = socket.handshake.query.admin_id;
    const database = socket.handshake.query.database;

    if (database) {
        socket.join(database);
        if (role) socket.join(`${role}_${database}`);
        socket.database = database;
        console.log(`Socket connected: Role=${role}, ID=${driverId || dispatcherId || userId || adminId || clientId}, DB=${database}`);
    }

    if (role === "dispatcher" && dispatcherId) dispatcherSockets.set(dispatcherId.toString(), socket.id);
    if ((role === "user" || role === "customer") && userId) userSockets.set(userId.toString(), socket.id);
    if (role === "client" && clientId) clientSockets.set(clientId.toString(), socket.id);
    if (role === "admin" && adminId) adminSockets.set(adminId.toString(), socket.id);

    // ✅ Driver connect
    if (driverId) {
        driverSockets.set(driverId.toString(), socket.id);

        (async () => {
            try {
                const db = getConnection(`tenant${database}`);

                const [rows] = await db.query(
                    `SELECT d.name, d.driving_status, d.plot_id, d.priority_plot, p.name AS plot_name 
                     FROM drivers d
                     LEFT JOIN plots p ON d.plot_id = p.id
                     WHERE d.id = ? LIMIT 1`,
                    [driverId]
                );

                console.log("Driver row:", rows);
                if (!rows.length) return;

                const driver = rows[0];

                if (driver.driving_status === "idle") {
                    const plotId = driver.plot_id;
                    const plotName = driver.plot_name || (plotId ? `Plot #${plotId}` : "N/A");

                    const plotKey = plotId ? `${plotId}_${database}` : null;
                    const rank = plotKey ? getOrAssignRank(plotKey, driverId) : "-";

                    const emitData = {
                        driver_id: driverId,
                        driverName: driver.name,
                        driver_name: driver.name,
                        plot: plotId ?? "Unassigned",
                        plot_name: plotName,
                        rank: rank
                    };

                    console.log(`Emitting waiting-driver-event to company ${database}:`, emitData);

                    io.to(`dispatcher_${database}`).emit("waiting-driver-event", emitData);
                    io.to(`admin_${database}`).emit("waiting-driver-event", emitData);
                    io.to(`client_${database}`).emit("waiting-driver-event", emitData);
                    socket.emit("waiting-driver-event", emitData);
                }

            } catch (err) {
                console.error("Driver connect waiting error:", err);
            }
        })();
    }

    socket.on("driver-location", async (data) => {
        try {
            let dataArray;
            if (typeof data === "string") {
                dataArray = JSON.parse(data);
            } else {
                dataArray = data;
            }

            const dbName = dataArray.database || socket.handshake.query.database;
            const driverIdFromData = dataArray.id || dataArray.driver_id || socket.driverId;

            if (dbName && driverIdFromData) {
                try {
                    const db = getConnection(`tenant${dbName}`);
                    const status = dataArray.driving_status || dataArray.status;
                    if (status) {
                        await db.query(
                            `UPDATE drivers SET latitude = ?, longitude = ?, driving_status = ?, updated_at = NOW() WHERE id = ?`,
                            [dataArray.latitude, dataArray.longitude, status, driverIdFromData]
                        );
                    } else {
                        await db.query(
                            `UPDATE drivers SET latitude = ?, longitude = ?, updated_at = NOW() WHERE id = ?`,
                            [dataArray.latitude, dataArray.longitude, driverIdFromData]
                        );
                    }
                } catch (dbErr) {
                    console.error("Database update error in driver-location:", dbErr.message);
                }
            }

            const response = await axios.post(
                "https://backend.cabifyit.com/api/driver/location",
                dataArray,
                {
                    headers: {
                        Authorization: `Bearer ${socket.token}`,
                        database: `${dbName}`,
                    }
                }
            );

            const driver = response.data.driver;
            if (driver) {
                io.to(dbName).emit("driver-location-update", driver);

                // ✅ Helper: fetch real plot name from tenant DB
                const getPlotName = async (plotId) => {
                    if (!plotId) return "N/A";
                    try {
                        const db = getConnection(`tenant${dbName}`);
                        const [plotRows] = await db.query(
                            "SELECT name FROM plots WHERE id = ? LIMIT 1",
                            [plotId]
                        );
                        return plotRows.length ? plotRows[0].name : `Plot #${plotId}`;
                    } catch (err) {
                        console.error("[driver-location] Failed to fetch plot name:", err.message);
                        return `Plot #${plotId}`;
                    }
                };

                if (driver.driving_status === "idle") {
                    const plotId = driver.plot_id;

                    // ✅ FIX: fetch real plot name from DB
                    const plotName = await getPlotName(plotId);

                    const plotKey = plotId ? `${plotId}_${dbName}` : null;
                    const rank = plotKey ? getOrAssignRank(plotKey, driver.id) : "-";

                    const eventData = {
                        driver_id: driver.id,
                        driverName: driver.name,
                        driver_name: driver.name,
                        plot: plotId,
                        plot_name: plotName,
                        rank: rank,
                        status: driver.driving_status,
                        latitude: driver.latitude,
                        longitude: driver.longitude
                    };

                    io.to(`dispatcher_${dbName}`).emit("waiting-driver-event", eventData);
                    io.to(`admin_${dbName}`).emit("waiting-driver-event", eventData);
                    io.to(`client_${dbName}`).emit("waiting-driver-event", eventData);
                    socket.emit("waiting-driver-event", eventData);

                } else if (driver.driving_status === "busy") {
                    const plotId = driver.plot_id;

                    // ✅ FIX: fetch real plot name from DB
                    const plotName = await getPlotName(plotId);

                    removeFromQueue(driver.id, dbName);
                    if (plotId) broadcastUpdatedQueue(plotId, dbName);

                    const eventData = {
                        driver_id: driver.id,
                        driverName: driver.name,
                        driver_name: driver.name,
                        plot: plotId,
                        plot_name: plotName,
                        rank: null,
                        status: driver.driving_status,
                        latitude: driver.latitude,
                        longitude: driver.longitude
                    };

                    io.to(`dispatcher_${dbName}`).emit("on-job-driver-event", eventData);
                    io.to(`admin_${dbName}`).emit("on-job-driver-event", eventData);
                    io.to(`client_${dbName}`).emit("on-job-driver-event", eventData);
                }
            }
        } catch (err) {
            console.error("Laravel Socket error:", err.message);
        }
    });

    socket.on("get-driver-location", async (data) => {
        try {
            var dataArray;
            if (typeof data === "string") {
                dataArray = JSON.parse(data);
            } else {
                dataArray = data;
            }
            const response = await axios.post(
                "https://backend.cabifyit.com/api/driver/get-location",
                dataArray,
                { headers: { database: `${dataArray.database}` } }
            );

            socket.emit("get-driver-location-on-user", { success: true, data: response.data });
        } catch (err) {
            console.error("Laravel Socket error", err);
        }
    });

    socket.on("disconnect", () => {
        if (driverId) {
            driverSockets.delete(driverId.toString());

            if (database) {
                (async () => {
                    try {
                        const db = getConnection(`tenant${database}`);
                        const [rows] = await db.query(
                            "SELECT plot_id FROM drivers WHERE id = ? LIMIT 1",
                            [driverId]
                        );
                        const plotId = rows[0]?.plot_id;

                        removeFromQueue(driverId, database);

                        if (plotId) {
                            broadcastUpdatedQueue(plotId, database);
                        }

                        console.log(`[WaitingQueue] Driver #${driverId} disconnected — removed from queue`);
                    } catch (err) {
                        console.error("Error removing driver from queue on disconnect:", err);
                        removeFromQueue(driverId, database);
                    }
                })();
            }
        }

        if (role === "dispatcher" && dispatcherId) {
            dispatcherSockets.delete(dispatcherId.toString());
            console.log(`Dispatcher ${dispatcherId} disconnected`);
        }
        if (role === "user" && userId) userSockets.delete(userId.toString());
        if (role === "client" && clientId) clientSockets.delete(clientId.toString());
        if (role === "admin" && adminId) {
            adminSockets.delete(adminId.toString());
            console.log(`Admin ${adminId} disconnected`);
        }
    });
});

app.use((req, res, next) => {
    const databaseHeader = req.headers['database'];
    if (databaseHeader) {
        req.tenantDb = `tenant${databaseHeader}`;
        console.log(`Using database: ${req.tenantDb}`);
    }
    next();
});

app.use(express.json());

app.use(cors({
    origin: [
        "http://localhost:5173",
        "http://localhost:5174",
        "http://localhost:5175",
        "https://clientadmin.cabifyit.com",
        "https://admin.cabifyit.com",
        "https://dispatcher.cabifyit.com"
    ],
    credentials: true,
    methods: ["GET", "POST", "PUT", "DELETE"],
    allowedHeaders: ['Content-Type', 'Authorization', 'database', 'subdomain'],
}));

async function calculatePostPaidEntries(driver, settings, db) {
    const packageDays = parseInt(settings.package_days);
    const packageAmount = parseFloat(settings.package_amount);

    const packageChangedDate = settings.package_updated_at
        ? new Date(settings.package_updated_at)
        : settings.updated_at
            ? new Date(settings.updated_at)
            : new Date(driver.created_at);

    let lastSettlementDate;
    if (driver.last_settlement_date) {
        const settlDate = new Date(driver.last_settlement_date);
        lastSettlementDate =
            settlDate >= packageChangedDate ? settlDate : packageChangedDate;
    } else {
        lastSettlementDate = packageChangedDate;
    }

    const currentDate = new Date();

    const calculationStartDate = new Date(lastSettlementDate);
    calculationStartDate.setDate(calculationStartDate.getDate() + 1);
    calculationStartDate.setHours(0, 0, 0, 0);

    const daysPassed = Math.floor(
        (currentDate - calculationStartDate) / (1000 * 60 * 60 * 24)
    );
    const completedCycles = Math.floor(daysPassed / packageDays);

    const entries = [];

    for (let i = 0; i < completedCycles; i++) {
        const cycleStartDate = new Date(calculationStartDate);
        cycleStartDate.setDate(cycleStartDate.getDate() + i * packageDays);
        const cycleEndDate = new Date(cycleStartDate);
        cycleEndDate.setDate(cycleEndDate.getDate() + packageDays - 1);

        entries.push({
            entry_number: i + 1,
            cycle_start_date: formatDate(cycleStartDate),
            cycle_end_date: formatDate(cycleEndDate),
            days_in_cycle: packageDays,
            amount: packageAmount.toFixed(2),
            status: "pending",
            description: `${packageDays} days package - ${packageAmount} Rs`,
        });
    }

    const currentCycleStart = new Date(calculationStartDate);
    currentCycleStart.setDate(
        currentCycleStart.getDate() + completedCycles * packageDays
    );
    const currentCycleEnd = new Date(currentCycleStart);
    currentCycleEnd.setDate(currentCycleEnd.getDate() + packageDays - 1);

    const daysElapsedInCycle = daysPassed % packageDays;
    const daysRemainingInCycle = packageDays - daysElapsedInCycle;

    entries.push({
        entry_number: completedCycles + 1,
        cycle_start_date: formatDate(currentCycleStart),
        cycle_end_date: formatDate(currentCycleEnd),
        days_in_cycle: packageDays,
        days_elapsed: daysElapsedInCycle,
        days_remaining: daysRemainingInCycle,
        amount: packageAmount.toFixed(2),
        status: "pending",
        description: `Current cycle - ${daysElapsedInCycle} of ${packageDays} days elapsed`,
    });

    return entries;
}

async function calculatePercentageEntries(driver, settings, db) {
    const packageDays = parseInt(settings.package_days);
    const packagePercentage = parseFloat(settings.package_percentage);

    const packageStartDate = settings.package_updated_at
        ? new Date(settings.package_updated_at)
        : settings.updated_at
            ? new Date(settings.updated_at)
            : new Date(driver.created_at);

    let lastSettlementDate;
    if (driver.last_settlement_date) {
        const settlDate = new Date(driver.last_settlement_date);
        lastSettlementDate =
            settlDate >= packageStartDate ? settlDate : packageStartDate;
    } else {
        lastSettlementDate = packageStartDate;
    }

    const currentDate = new Date();

    const calculationStartDate = new Date(lastSettlementDate);
    calculationStartDate.setDate(calculationStartDate.getDate() + 1);
    calculationStartDate.setHours(0, 0, 0, 0);

    const daysPassed = Math.floor(
        (currentDate - calculationStartDate) / (1000 * 60 * 60 * 24)
    );
    const completedCycles = Math.floor(daysPassed / packageDays);

    const entries = [];

    for (let i = 0; i < completedCycles; i++) {
        const cycleStartDate = new Date(calculationStartDate);
        cycleStartDate.setDate(cycleStartDate.getDate() + i * packageDays);

        const cycleEndDate = new Date(cycleStartDate);
        cycleEndDate.setDate(cycleEndDate.getDate() + packageDays - 1);
        cycleEndDate.setHours(23, 59, 59, 999);

        const [bookingRows] = await db.query(
            `SELECT 
                COUNT(*) as total_rides,
                COALESCE(SUM(
                    CASE 
                        WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                        WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                        WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                        ELSE 0
                    END
                ), 0) as total_rides_amount
            FROM bookings
            WHERE driver = ?
            AND booking_status = 'completed'
            AND DATE(booking_date) >= ?
            AND DATE(booking_date) <= ?`,
            [driver.id, formatDate(cycleStartDate), formatDate(cycleEndDate)]
        );

        const totalRidesAmount = parseFloat(
            bookingRows[0]?.total_rides_amount || 0
        );
        const totalRides = parseInt(bookingRows[0]?.total_rides || 0);
        const commissionAmount = (totalRidesAmount * packagePercentage) / 100;

        entries.push({
            entry_number: i + 1,
            cycle_start_date: formatDate(cycleStartDate),
            cycle_end_date: formatDate(cycleEndDate),
            days_in_cycle: packageDays,
            total_rides: totalRides,
            total_rides_amount: totalRidesAmount.toFixed(2),
            commission_percentage: packagePercentage,
            amount: commissionAmount.toFixed(2),
            status: "pending",
            description: `${packagePercentage}% of ${totalRidesAmount.toFixed(2)} Rs rides (${totalRides} rides)`,
        });
    }

    const currentCycleStart = new Date(calculationStartDate);
    currentCycleStart.setDate(
        currentCycleStart.getDate() + completedCycles * packageDays
    );

    const currentCycleEnd = new Date(currentCycleStart);
    currentCycleEnd.setDate(currentCycleEnd.getDate() + packageDays - 1);

    const daysElapsedInCycle = daysPassed % packageDays;
    const daysRemainingInCycle = packageDays - daysElapsedInCycle;

    const [currentBookingRows] = await db.query(
        `SELECT 
            COUNT(*) as total_rides,
            COALESCE(SUM(
                CASE 
                    WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                    WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                    WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                    ELSE 0
                END
            ), 0) as total_rides_amount
        FROM bookings
        WHERE driver = ?
        AND booking_status = 'completed'
        AND DATE(booking_date) >= ?
        AND DATE(booking_date) <= ?`,
        [driver.id, formatDate(currentCycleStart), formatDate(currentCycleEnd)]
    );

    const currentRidesAmount = parseFloat(
        currentBookingRows[0]?.total_rides_amount || 0
    );
    const currentTotalRides = parseInt(currentBookingRows[0]?.total_rides || 0);
    const currentCommission = (currentRidesAmount * packagePercentage) / 100;

    entries.push({
        entry_number: completedCycles + 1,
        cycle_start_date: formatDate(currentCycleStart),
        cycle_end_date: formatDate(currentCycleEnd),
        days_in_cycle: packageDays,
        days_elapsed: daysElapsedInCycle,
        days_remaining: daysRemainingInCycle,
        total_rides: currentTotalRides,
        total_rides_amount: currentRidesAmount.toFixed(2),
        commission_percentage: packagePercentage,
        amount: currentCommission.toFixed(2),
        status: "pending",
        description: `Current cycle - ${packagePercentage}% of ${currentRidesAmount.toFixed(2)} Rs rides (${currentTotalRides} rides)`,
    });

    return entries;
}

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatDateTime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

app.get("/driver/commission-entries", async (req, res) => {
    try {
        const { driver_id, page = 1, limit = 10 } = req.query;

        if (!driver_id) {
            return res
                .status(400)
                .json({ success: 0, message: "Driver ID is required" });
        }

        const databaseHeader =
            req.headers["x-database"] ||
            req.headers["database"] ||
            req.query.database;
        if (!databaseHeader) {
            return res
                .status(400)
                .json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [settingsRows] = await db.query(
            "SELECT * FROM settings ORDER BY id DESC LIMIT 1"
        );
        if (!settingsRows.length) {
            return res
                .status(404)
                .json({ success: 0, message: "Company settings not found" });
        }
        const settings = settingsRows[0];

        const [driverRows] = await db.query(
            "SELECT * FROM drivers WHERE id = ?",
            [driver_id]
        );
        if (!driverRows.length) {
            return res
                .status(404)
                .json({ success: 0, message: "Driver not found" });
        }
        const driver = driverRows[0];

        // Build entries based on package type
        let allEntries = [];
        if (settings.package_type === "packages_post_paid") {
            allEntries = await calculatePostPaidEntries(driver, settings, db);
        } else if (settings.package_type === "commission_without_topup") {
            allEntries = await calculatePercentageEntries(driver, settings, db);
        } else {
            return res
                .status(400)
                .json({ success: 0, message: "Invalid package type" });
        }

        // Filter out already settled entries
        let uncollectedEntries = allEntries;
        if (driver.last_settlement_date) {
            const lastSettlementDate = new Date(driver.last_settlement_date);
            lastSettlementDate.setHours(0, 0, 0, 0);

            uncollectedEntries = allEntries.filter((entry) => {
                const cycleEndDate = new Date(entry.cycle_end_date);
                cycleEndDate.setHours(0, 0, 0, 0);
                return cycleEndDate > lastSettlementDate;
            });
        }
        const markedEntries = uncollectedEntries.map((entry, index) => ({
            ...entry,
            is_collectible: index === 0,
            is_locked: index !== 0,
        }));

        // Pagination
        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const totalEntries = markedEntries.length;
        const totalPages = Math.ceil(totalEntries / limitNum);
        const offset = (pageNum - 1) * limitNum;
        const paginatedEntries = markedEntries.slice(offset, offset + limitNum);

        const pendingEntries = markedEntries.filter(
            (e) => e.status === "pending"
        );

        console.log("Commission Entries Success:", {
            total: totalEntries,
            page: pageNum,
            package_type: settings.package_type,
        });

        return res.json({
            success: 1,
            data: {
                driver_id: driver.id,
                driver_name: driver.name,
                driver_wallet_balance: parseFloat(
                    driver.wallet_balance || 0
                ).toFixed(2),
                package_type: settings.package_type,
                package_days: settings.package_days,
                package_amount: settings.package_amount,
                package_percentage: settings.package_percentage,
                last_settlement_date: driver.last_settlement_date,
                total_uncollected_entries: pendingEntries.length,
                total_uncollected_amount: pendingEntries
                    .reduce((sum, e) => sum + parseFloat(e.amount), 0)
                    .toFixed(2),
                commission_entries: paginatedEntries,
                pagination: {
                    total: totalEntries,
                    page: pageNum,
                    limit: limitNum,
                    total_pages: totalPages,
                    hasNext: pageNum < totalPages,
                    hasPrev: pageNum > 1,
                },
            },
        });
    } catch (error) {
        console.error("Error in commission-entries:", error);
        return res.status(500).json({ success: 0, message: error.message });
    }
});

app.post("/driver/collect-commission", async (req, res) => {
    try {
        const { driver_id } = req.body;

        if (!driver_id) {
            return res
                .status(400)
                .json({ success: 0, message: "Driver ID is required" });
        }

        const databaseHeader =
            req.headers["x-database"] ||
            req.headers["database"] ||
            req.query.database;
        if (!databaseHeader) {
            return res
                .status(400)
                .json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [settingsRows] = await db.query(
            "SELECT * FROM settings ORDER BY id DESC LIMIT 1"
        );
        if (!settingsRows.length) {
            return res
                .status(404)
                .json({ success: 0, message: "Company settings not found" });
        }
        const settings = settingsRows[0];

        const [driverRows] = await db.query(
            "SELECT * FROM drivers WHERE id = ?",
            [driver_id]
        );
        if (!driverRows.length) {
            return res
                .status(404)
                .json({ success: 0, message: "Driver not found" });
        }
        const driver = driverRows[0];

        let commissionEntries = [];
        if (settings.package_type === "packages_post_paid") {
            commissionEntries = await calculatePostPaidEntries(
                driver,
                settings,
                db
            );
        } else if (settings.package_type === "commission_without_topup") {
            commissionEntries = await calculatePercentageEntries(
                driver,
                settings,
                db
            );
        } else {
            return res
                .status(400)
                .json({ success: 0, message: "Invalid package type" });
        }

        let uncollectedEntries = commissionEntries;
        if (driver.last_settlement_date) {
            const lastSettlementDate = new Date(driver.last_settlement_date);
            lastSettlementDate.setHours(0, 0, 0, 0);
            uncollectedEntries = commissionEntries.filter((entry) => {
                const cycleEndDate = new Date(entry.cycle_end_date);
                cycleEndDate.setHours(0, 0, 0, 0);
                return cycleEndDate > lastSettlementDate;
            });
        }

        const pendingEntries = uncollectedEntries.filter(
            (e) => e.status === "pending"
        );

        if (pendingEntries.length === 0) {
            return res.json({
                success: 0,
                message: "No commission entries available to collect",
            });
        }

        const firstEntry = pendingEntries[0];
        const collectionAmount = parseFloat(firstEntry.amount);

        if (collectionAmount <= 0) {
            return res.json({
                success: 0,
                message: "No collectible amount in the first entry",
            });
        }

        const newSettlementDate = new Date(
            firstEntry.cycle_end_date + " 23:59:59"
        );
        const currentDateTime = new Date();

        await db.query(
            `UPDATE drivers SET last_settlement_date = ? WHERE id = ?`,
            [formatDateTime(newSettlementDate), driver_id]
        );

        const transactionComment = `Commission collected - ${firstEntry.description}`;
        await db.query(
            `INSERT INTO wallet_transactions 
             (user_type, user_id, type, comment, created_at, updated_at, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)`,
            [
                "driver",
                driver_id,
                "deduct",
                transactionComment,
                formatDateTime(currentDateTime),
                formatDateTime(currentDateTime),
                collectionAmount,
            ]
        );

        const [updatedDriverRows] = await db.query(
            "SELECT * FROM drivers WHERE id = ?",
            [driver_id]
        );
        const updatedDriver = updatedDriverRows[0];

        let remainingEntries = [];
        if (settings.package_type === "packages_post_paid") {
            remainingEntries = await calculatePostPaidEntries(
                updatedDriver,
                settings,
                db
            );
        } else if (settings.package_type === "commission_without_topup") {
            remainingEntries = await calculatePercentageEntries(
                updatedDriver,
                settings,
                db
            );
        }

        let remainingUncollected = remainingEntries;
        if (updatedDriver.last_settlement_date) {
            const lastDate = new Date(updatedDriver.last_settlement_date);
            lastDate.setHours(0, 0, 0, 0);
            remainingUncollected = remainingEntries.filter((e) => {
                const end = new Date(e.cycle_end_date);
                end.setHours(0, 0, 0, 0);
                return end > lastDate;
            });
        }

        const remainingPending = remainingUncollected.filter(
            (e) => e.status === "pending"
        );

        console.log("✅ Commission Collected:", {
            driver_id,
            package_type: settings.package_type,
            collected_amount: collectionAmount,
            cycle: `${firstEntry.cycle_start_date} → ${firstEntry.cycle_end_date}`,
            remaining_entries: remainingPending.length,
        });

        return res.json({
            success: 1,
            message: "Commission collected successfully",
            data: {
                driver_id: driver.id,
                driver_name: driver.name,
                package_type: settings.package_type,
                collected_entry: {
                    entry_number: firstEntry.entry_number,
                    cycle_start_date: firstEntry.cycle_start_date,
                    cycle_end_date: firstEntry.cycle_end_date,
                    amount: firstEntry.amount,
                    description: firstEntry.description,
                    // % based extra fields
                    ...(settings.package_type === "commission_without_topup" && {
                        total_rides: firstEntry.total_rides,
                        total_rides_amount: firstEntry.total_rides_amount,
                        commission_percentage: firstEntry.commission_percentage,
                    }),
                },
                collected_amount: collectionAmount.toFixed(2),
                previous_settlement_date: driver.last_settlement_date
                    ? formatDate(new Date(driver.last_settlement_date))
                    : "Not Set",
                new_settlement_date: formatDate(newSettlementDate),
                remaining_entries: remainingPending.length,
                remaining_amount: remainingPending
                    .reduce((sum, e) => sum + parseFloat(e.amount), 0)
                    .toFixed(2),
                next_collectible:
                    remainingPending.length > 0 ? remainingPending[0] : null,
                transaction_recorded: true,
            },
        });
    } catch (error) {
        console.error("Error in collect-commission:", error);
        return res.status(500).json({ success: 0, message: error.message });
    }
});

app.get("/bookings/dashboard-cards", async (req, res) => {
    try {
        const db = getConnection(req.tenantDb);

        const query = `
            SELECT
                COUNT(CASE 
                    WHEN DATE(booking_date) = CURDATE() 
                    THEN 1 
                END) AS todays_booking,

                COUNT(CASE 
                    WHEN DATE(booking_date) > CURDATE() 
                    THEN 1 
                END) AS pre_bookings,

                COUNT(CASE 
                    WHEN booking_status = 'completed' 
                    THEN 1 
                END) AS completed,

                COUNT(CASE 
                    WHEN booking_status IN ('no_show', 'arrived', 'ongoing')
                    THEN 1 
                END) AS no_show,

                COUNT(CASE 
                    WHEN booking_status = 'cancelled' 
                    THEN 1 
                END) AS cancelled,

                COUNT(CASE 
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
                    THEN 1 
                END) AS recent_jobs
            FROM bookings
        `;

        const [[counts]] = await db.query(query);

        return res.json({
            success: true,
            data: {
                todaysBooking: counts.todays_booking,
                preBookings: counts.pre_bookings,
                recentJobs: counts.recent_jobs,
                completed: counts.completed,
                noShow: counts.no_show,
                cancelled: counts.cancelled
            }
        });

    } catch (error) {
        console.error("Dashboard count error:", error);
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

app.get("/bookings", async (req, res) => {
    try {
        let { status, date, user_id, driver_id, sub_company, search, filter, page = 1, limit = 10 } = req.query;

        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const offset = (pageNum - 1) * limitNum;

        const db = getConnection(req.tenantDb);

        let baseQuery = `
            FROM bookings b
            LEFT JOIN drivers d ON b.driver = d.id
            LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
            LEFT JOIN sub_companies sc ON b.sub_company = sc.id
            WHERE 1=1
        `;
        const params = [];

        if (filter) {
            switch (filter) {
                case 'todays_booking':
                    baseQuery += ` AND DATE(b.booking_date) = CURDATE()`;
                    break;
                case 'pre_bookings':
                    baseQuery += ` AND DATE(b.booking_date) > CURDATE()`;
                    break;
                case 'completed':
                    baseQuery += ` AND b.booking_status = 'completed'`;
                    break;
                case 'no_show':
                    baseQuery += ` AND b.booking_status IN ('no_show', 'arrived', 'ongoing')`;
                    break;
                case 'cancelled':
                    baseQuery += ` AND b.booking_status = 'cancelled'`;
                    break;
                case 'recent_jobs':
                    baseQuery += ` AND b.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)`;
                    break;
            }
        }

        if (status) { baseQuery += ` AND b.booking_status = ?`; params.push(status); }
        if (date) { baseQuery += ` AND DATE(b.booking_date) = ?`; params.push(date); }
        if (user_id) { baseQuery += ` AND b.user_id = ?`; params.push(user_id); }
        if (driver_id) { baseQuery += ` AND b.driver = ?`; params.push(driver_id); }
        if (sub_company) { baseQuery += ` AND b.sub_company = ?`; params.push(sub_company); }
        if (search) {
            baseQuery += ` AND (b.booking_id LIKE ? OR b.name LIKE ? OR b.phone_no LIKE ? OR b.email LIKE ? OR d.name LIKE ? OR vt.vehicle_type_name LIKE ?)`;
            const s = `%${search}%`;
            params.push(s, s, s, s, s, s);
        }

        const dataQuery = `
            SELECT 
                b.*,
                d.id as driver_id, d.name as driver_name, d.email as driver_email,
                d.phone_no as driver_phone, d.profile_image as driver_profile_image,
                vt.id as vehicle_type_id, vt.vehicle_type_name, vt.vehicle_type_service,
                sc.id as sub_company_id, sc.name as sub_company_name, sc.email as sub_company_email
            ${baseQuery}
            ORDER BY b.booking_date DESC, b.id DESC
            LIMIT ? OFFSET ?
        `;

        const [bookings] = await db.query(dataQuery, [...params, limitNum, offset]);

        const formattedBookings = bookings.map(booking => {
            const {
                driver_id, driver_name, driver_email, driver_phone, driver_profile_image,
                vehicle_type_id, vehicle_type_name, vehicle_type_service,
                sub_company_id, sub_company_name, sub_company_email,
                ...bookingData
            } = booking;

            return {
                ...bookingData,
                driverDetail: driver_id ? { id: driver_id, name: driver_name, email: driver_email, phone_no: driver_phone, profile_image: driver_profile_image } : null,
                vehicleDetail: vehicle_type_id ? { id: vehicle_type_id, vehicle_type_name, vehicle_type_service } : null,
                subCompanyDetail: sub_company_id ? { id: sub_company_id, name: sub_company_name, email: sub_company_email } : null
            };
        });

        const countQuery = `SELECT COUNT(*) AS total ${baseQuery}`;
        const [[{ total }]] = await db.query(countQuery, params);

        return res.json({
            success: true,
            data: formattedBookings,
            pagination: {
                total, page: pageNum, limit: limitNum,
                total_pages: Math.ceil(total / limitNum),
                hasNext: pageNum * limitNum < total,
                hasPrev: pageNum > 1
            }
        });

    } catch (error) {
        console.error("Error fetching bookings:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.put("/bookings/:id/assign-driver", async (req, res) => {
    try {
        const { id } = req.params;
        const { driver_id, assignment_type } = req.body;

        if (!driver_id) {
            return res.status(400).json({ success: false, message: "Driver ID is required" });
        }

        const db = getConnection(req.tenantDb);

        const [bookingRows] = await db.query(
            "SELECT id, booking_status, booking_id, offered_amount, booking_amount, recommended_amount FROM bookings WHERE id = ?",
            [id]
        );
        if (bookingRows.length === 0) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const [driverRows] = await db.query(
            "SELECT id, name, phone_no, driving_status FROM drivers WHERE id = ?",
            [driver_id]
        );
        if (driverRows.length === 0) {
            return res.status(404).json({ success: false, message: "Driver not found" });
        }

        const isPreJob = assignment_type === "pre_job";
        const dispatcherName = req.body.dispatcher_name || "Dispatcher";
        const driverName = driverRows[0].name || "Driver";

        const newStatus = 'ongoing';

        const actionText = isPreJob
            ? `${dispatcherName} sent a pre-job request and automatically accepted for driver ${driverName}`
            : `${dispatcherName} assigned and automatically accepted for driver ${driverName}`;

        const existingAmount = bookingRows[0].booking_amount;
        const offeredAmount = bookingRows[0].offered_amount;
        const amountToSet = (existingAmount === null || existingAmount === undefined || existingAmount == 0)
            ? (offeredAmount ?? null)
            : existingAmount;

        await db.query(
            `UPDATE bookings SET driver = ?, booking_amount = ?, dispatcher_action = ?, booking_status = ? WHERE id = ?`,
            [driver_id, amountToSet, actionText, newStatus, id]
        );

        await db.query("UPDATE drivers SET driving_status = 'busy' WHERE id = ?", [driver_id]);

        const [updatedBookingRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
        const updatedBooking = updatedBookingRows[0];

        const notifTitle = isPreJob ? "Pre-Job Assigned" : "New Ride Assigned";
        const notifMessage = isPreJob
            ? `You have been assigned a pre-job ride #${updatedBooking.booking_id}. It has been automatically accepted for you.`
            : `You have been assigned a new ride #${updatedBooking.booking_id}. It has been automatically accepted for you.`;

        try {
            await sendNotificationToDriver(db, driver_id, notifTitle, notifMessage, {
                booking_id: String(id),
                type: "new_ride"
            });
            console.log("Notification sent to driver:", driverRows[0].name);
        } catch (fcmError) {
            console.error("FCM failed (non-fatal):", fcmError.message);
        }

        try {
            await storeNotification(db, {
                user_type: 'driver',
                user_id: driver_id,
                title: notifTitle,
                message: notifMessage
            });
        } catch (storeError) {
            console.error("Store notification failed (non-fatal):", storeError.message);
        }

        const driverSocketId = driverSockets.get(driver_id.toString());
        if (driverSocketId) {
            io.to(driverSocketId).emit("new-ride-request", {
                booking_id: id,
                assignment_type: "allocate_driver",
                message: notifMessage,
                booking: updatedBooking
            });
        }

        dispatcherSockets.forEach((sid) => io.to(sid).emit("notification-ride", updatedBooking));

        return res.json({
            success: true,
            message: isPreJob
                ? "Pre-job assigned and automatically accepted successfully."
                : "Driver assigned and ride accepted successfully."
        });

    } catch (error) {
        console.error("Assign driver error:", error);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/bookings/:id/start-auto-dispatch", async (req, res) => {
    try {
        const { id } = req.params;
        const { dispatcher_name } = req.body;
        const dispatcherName = dispatcher_name || "Dispatcher";

        if (!req.tenantDb) {
            console.error("[API /start-auto-dispatch] ❌ req.tenantDb is undefined — missing 'database' header");
            return res.status(400).json({
                success: false,
                message: "Missing 'database' header in request"
            });
        }

        console.log(`[API] Connected drivers at dispatch time: [${Array.from(driverSockets.keys()).join(', ')}]`);

        const db = getConnection(req.tenantDb);
        await db.query(
            "UPDATE bookings SET dispatcher_action = ? WHERE id = ?",
            [`${dispatcherName} started the auto-dispatch process for this ride`, id]
        );

        autoDispatchRide({ bookingId: id, tenantDb: req.tenantDb });

        return res.json({
            success: true,
            message: "Auto dispatch started",
            debug: {
                tenantDb: req.tenantDb,
                connected_driver_ids: Array.from(driverSockets.keys())
            }
        });

    } catch (error) {
        console.error("[API] /start-auto-dispatch error:", error.message);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.get("/debug/dispatch-check", async (req, res) => {
    try {
        const { booking_id, database } = req.query;
        if (!database) {
            return res.status(400).json({ error: "database query param required" });
        }

        const tenantDb = `tenant${database}`;
        const db = getConnection(tenantDb);

        // booking info
        let booking = null;
        if (booking_id) {
            const [bRows] = await db.query(
                "SELECT id, booking_status, driver, pickup_plot_id, destination_plot_id, pickup_point FROM bookings WHERE id = ?",
                [booking_id]
            );
            booking = bRows[0] || null;
        }

        let driversInPlot = [];
        let idleInPlot = [];
        if (booking?.pickup_plot_id) {
            const [dRows] = await db.query(
                "SELECT id, name, driving_status, plot_id, latitude, longitude FROM drivers WHERE plot_id = ?",
                [booking.pickup_plot_id]
            );
            driversInPlot = dRows;
            idleInPlot = dRows.filter(d => d.driving_status === 'idle');
        }

        const [allDrivers] = await db.query(
            "SELECT id, name, driving_status, plot_id FROM drivers ORDER BY id"
        );

        return res.json({
            socket_map: {
                total_drivers_connected: driverSockets.size,
                drivers: Array.from(driverSockets.entries()).map(([id, sid]) => ({
                    driver_id: id,
                    socket_id: sid
                })),
                dispatchers: Array.from(dispatcherSockets.entries()).map(([id, sid]) => ({ id, socket_id: sid })),
                admins: Array.from(adminSockets.entries()).map(([id, sid]) => ({ id, socket_id: sid }))
            },
            booking,
            drivers_in_booking_plot: driversInPlot,
            idle_drivers_in_plot: idleInPlot,
            all_drivers_in_db: allDrivers,
            plot_queues: Array.from(plotDriverQueues.entries()).map(([key, queue]) => ({ key, queue }))
        });

    } catch (err) {
        console.error("[Debug] dispatch-check error:", err.message);
        return res.status(500).json({ error: err.message });
    }
});

app.post("/bookings/:id/record-action", async (req, res) => {
    try {
        const { id } = req.params;
        const { action, dispatcher_name } = req.body;
        const dispatcherName = dispatcher_name || "Dispatcher";

        if (!action) return res.status(400).json({ success: false, message: "action is required" });

        const db = getConnection(req.tenantDb);
        await db.query(
            "UPDATE bookings SET dispatcher_action = ? WHERE id = ?",
            [`${dispatcherName} ${action}`, id]
        );

        return res.json({ success: true, message: "Action recorded" });
    } catch (error) {
        console.error("Record action error:", error);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/bookings/:id/set-follow-on-job", async (req, res) => {
    try {
        const { id } = req.params;
        const { follow_on_booking_id } = req.body;

        if (!follow_on_booking_id) {
            return res.status(400).json({ success: false, message: "follow_on_booking_id is required" });
        }

        if (parseInt(id) === parseInt(follow_on_booking_id)) {
            return res.status(400).json({ success: false, message: "A booking cannot be a follow-on of itself" });
        }

        const db = getConnection(req.tenantDb);

        const [job1Rows] = await db.query(
            "SELECT id, booking_id, booking_status, driver, booking_system FROM bookings WHERE id = ?",
            [id]
        );
        if (!job1Rows.length) return res.status(404).json({ success: false, message: "Job 1 not found" });

        const job1 = job1Rows[0];

        if (!job1.driver) {
            return res.status(400).json({ success: false, message: "Job 1 has no driver assigned. Assign a driver first." });
        }

        if (!['ongoing', 'arrived', 'started'].includes(job1.booking_status)) {
            return res.status(400).json({
                success: false,
                message: `Job 1 must be active (ongoing/arrived/started). Current status: ${job1.booking_status}`
            });
        }

        const [job2Rows] = await db.query(
            "SELECT id, booking_id, booking_status FROM bookings WHERE id = ?",
            [follow_on_booking_id]
        );
        if (!job2Rows.length) return res.status(404).json({ success: false, message: "Follow-on booking (Job 2) not found" });

        const job2 = job2Rows[0];

        if (!['pending', 'pending_acceptance'].includes(job2.booking_status)) {
            return res.status(400).json({
                success: false,
                message: `Job 2 must be pending. Current status: ${job2.booking_status}`
            });
        }

        const [alreadyLinked] = await db.query(
            "SELECT id FROM bookings WHERE booking_system = ?",
            [String(follow_on_booking_id)]
        );
        if (alreadyLinked.length) {
            return res.status(400).json({
                success: false,
                message: `Booking #${job2.booking_id} is already queued as a follow-on for another job`
            });
        }

        const [driverRows] = await db.query("SELECT id, name FROM drivers WHERE id = ?", [job1.driver]);
        const driverName = driverRows[0]?.name || "Driver";
        const dispatcherName = req.body.dispatcher_name || "Dispatcher";

        await db.query(
            "UPDATE bookings SET booking_system = ?, dispatcher_action = ? WHERE id = ?",
            [
                String(follow_on_booking_id),
                `${dispatcherName} linked booking #${job2.booking_id} as a follow-on job to this ride`,
                id
            ]
        );

        // ✅ Fetch full updated job2 booking to send to driver
        const [updatedJob2Rows] = await db.query("SELECT * FROM bookings WHERE id = ?", [follow_on_booking_id]);
        const updatedJob2 = updatedJob2Rows[0];

        const responseData = {
            job1_id: job1.id,
            job1_booking_id: job1.booking_id,
            job2_id: job2.id,
            job2_booking_id: job2.booking_id,
            driver_id: job1.driver,
            driver_name: driverName,
            message: `Booking #${job2.booking_id} queued as follow-on after #${job1.booking_id} for ${driverName}`
        };

        // ✅ Notify dispatcher/admin/client via socket
        dispatcherSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));
        adminSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));
        clientSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));

        // ✅ Push notification to driver
        const notifTitle = "New Follow-On Job";
        const notifMessage = `You have a new follow-on ride #${updatedJob2.booking_id} queued after your current job. Please accept or reject.`;

        try {
            await sendNotificationToDriver(db, job1.driver, notifTitle, notifMessage, {
                booking_id: String(follow_on_booking_id),
                type: "new_ride"
            });
            console.log(`[FollowOn] Push notification sent to driver #${job1.driver}`);
        } catch (fcmErr) {
            console.error("[FollowOn] FCM failed (non-fatal):", fcmErr.message);
        }

        try {
            await storeNotification(db, {
                user_type: 'driver',
                user_id: job1.driver,
                title: notifTitle,
                message: notifMessage
            });
        } catch (storeErr) {
            console.error("[FollowOn] Store notification failed (non-fatal):", storeErr.message);
        }

        const driverSocketId = driverSockets.get(job1.driver.toString());

        console.log(`[FollowOn] job1.driver = "${job1.driver}", type = ${typeof job1.driver}`);
        console.log(`[FollowOn] driverSockets keys:`, Array.from(driverSockets.keys()));
        console.log(`[FollowOn] Found socketId: ${driverSocketId}`);

        if (driverSocketId) {
            io.to(driverSocketId).emit("new-ride-request", {
                booking_id: String(follow_on_booking_id),
                assignment_type: "allocate_driver",
                message: notifMessage,
                booking: updatedJob2
            });
            console.log(`[FollowOn] Socket event 'new-ride-request' sent to driver #${job1.driver}`);
        } else {
            console.log(`[FollowOn] Driver #${job1.driver} not connected via socket — push notification sent only`);
        }

        console.log(`[FollowOn] Linked: Job #${job1.booking_id} → Job #${job2.booking_id} (Driver: ${driverName})`);

        return res.json({ success: true, message: responseData.message, data: responseData });

    } catch (error) {
        console.error("Set follow-on job error:", error);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.put("/bookings/:id/status", async (req, res) => {
    try {
        const { id } = req.params;
        let { booking_status, cancel_reason, cancelled_by } = req.body;
        let cancelled_by_actor = cancelled_by || 'admin';

        if (!booking_status) return res.status(400).json({ success: false, message: "booking_status is required" });

        const db = getConnection(req.tenantDb);
        const [bookings] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
        if (bookings.length === 0) return res.status(404).json({ success: false, message: "Booking not found" });

        const booking = bookings[0];
        let res_user = null;

        const dispatcherName = req.body.dispatcher_name || "Dispatcher";
        let updateQuery = "UPDATE bookings SET booking_status = ?";
        const params = [booking_status];

        let actionLabel = `updated the status to ${booking_status}`;
        if (booking_status === 'cancelled') actionLabel = "cancelled this ride";
        else if (booking_status === 'completed') actionLabel = "marked the ride as completed";

        updateQuery += ", dispatcher_action = ?";
        params.push(`${dispatcherName} ${actionLabel}`);

        if (booking_status === 'cancelled') {
            if (cancel_reason) { updateQuery += ", cancel_reason = ?"; params.push(cancel_reason); }
            if (cancelled_by === 'user' || cancelled_by === 'driver') {
                updateQuery += ", cancelled_by = ?"; params.push(cancelled_by);
            }
        }
        updateQuery += " WHERE id = ?";
        params.push(id);

        await db.query(updateQuery, params);

        if (booking.driver) {
            let driverStatus = null;
            if (['cancelled', 'completed', 'no_show'].includes(booking_status)) driverStatus = 'idle';
            else if (['ongoing', 'started', 'arrived'].includes(booking_status)) driverStatus = 'busy';
            if (driverStatus) {
                await db.query("UPDATE drivers SET driving_status = ? WHERE id = ?", [driverStatus, booking.driver]);
            }
        }

        if (booking_status === 'cancelled') {
            const notifTitle = "Ride Cancelled";
            const notifMessage = cancelled_by_actor === 'user'
                ? `Ride #${booking.booking_id} has been cancelled by customer`
                : `Ride #${booking.booking_id} is cancelled by Admin or Dispatcher`;

            if (booking.user_id) {
                const userNotifTitle = "Ride Cancelled";
                const userNotifMessage = cancelled_by_actor === 'user'
                    ? `Your ride #${booking.booking_id} has been successfully cancelled.`
                    : `Your ride #${booking.booking_id} has been cancelled by Admin or Dispatcher.`;
                try {
                    res_user = await sendNotificationToUser(db, booking.user_id, userNotifTitle, userNotifMessage, {
                        booking_id: String(id),
                        type: "ride_cancelled"
                    });
                    await storeNotification(db, {
                        user_type: 'rider',
                        user_id: booking.user_id,
                        title: userNotifTitle,
                        message: userNotifMessage
                    });
                    console.log("Cancel notification sent to user:", booking.user_id);
                } catch (userNotifErr) {
                    console.error("User Notification error in ride cancellation:", userNotifErr.message);
                }
            }

            if (booking.driver) {
                try {
                    await sendNotificationToDriver(db, booking.driver, notifTitle, notifMessage, {
                        booking_id: String(id),
                        type: "ride_cancelled"
                    });
                    await storeNotification(db, {
                        user_type: 'driver',
                        user_id: booking.driver,
                        title: notifTitle,
                        message: notifMessage
                    });
                    console.log("Cancel notification sent to driver:", booking.driver);
                } catch (notifErr) {
                    console.error("Notification error in ride cancellation (driver):", notifErr.message);
                }
            }

        } else if (booking.driver) {
            const [driverInfoRows] = await db.query(
                "SELECT id, name, phone_no FROM drivers WHERE id = ?",
                [booking.driver]
            );
            const driverInfoForFo = driverInfoRows[0];

            if (booking_status === 'completed') {
                const notifTitle = "Ride Completed";
                const notifMessage = `Ride #${booking.booking_id} has been marked as completed`;

                try {
                    await sendNotificationToDriver(db, booking.driver, notifTitle, notifMessage, {
                        booking_id: String(id),
                        type: "ride_completed"
                    });
                    await storeNotification(db, {
                        user_type: 'driver',
                        user_id: booking.driver,
                        title: notifTitle,
                        message: notifMessage
                    });
                    console.log("Complete notification sent to driver:", driverInfoForFo?.name);
                } catch (notifErr) {
                    console.error("Notification error in ride completion (driver):", notifErr.message);
                }

                if (booking.user_id) {
                    try {
                        res_user = await sendNotificationToUser(db, booking.user_id, notifTitle, notifMessage, {
                            booking_id: String(id),
                            type: "ride_completed"
                        });
                        await storeNotification(db, {
                            user_type: 'rider',
                            user_id: booking.user_id,
                            title: notifTitle,
                            message: notifMessage
                        });
                    } catch (err) {
                        console.error("Notification error in ride completion (user):", err.message);
                    }
                }
            } else if (['arrived', 'started'].includes(booking_status)) {
                const userNotifTitle = booking_status === 'arrived' ? "Driver Arrived" : "Ride Started";
                const userNotifMessage = booking_status === 'arrived'
                    ? `Your driver has arrived at the pickup location.`
                    : `Your ride has started. Have a safe journey!`;

                if (booking.user_id) {
                    try {
                        res_user = await sendNotificationToUser(db, booking.user_id, userNotifTitle, userNotifMessage, {
                            booking_id: String(id),
                            type: `ride_${booking_status}`
                        });
                        await storeNotification(db, {
                            user_type: 'rider',
                            user_id: booking.user_id,
                            title: userNotifTitle,
                            message: userNotifMessage
                        });
                    } catch (err) {
                        console.error(`Notification error in ride ${booking_status} (user):`, err.message);
                    }
                }
            }
        }

        let followOnPayload = null;
        let followOnEventData = null;

        if (booking.booking_system && !isNaN(parseInt(booking.booking_system))) {
            const followOnId = parseInt(booking.booking_system);
            console.log(`[FollowOn] Detected follow-on job #${followOnId} for driver #${booking.driver}`);

            try {
                const [followOnRows] = await db.query(
                    "SELECT * FROM bookings WHERE id = ?",
                    [followOnId]
                );

                if (followOnRows.length && ['pending', 'pending_acceptance'].includes(followOnRows[0].booking_status)) {
                    const followOnBooking = followOnRows[0];
                    const driverId = booking.driver;

                    await db.query(
                        `UPDATE bookings SET driver = ?, booking_status = 'pending_acceptance' WHERE id = ?`,
                        [driverId, followOnId]
                    );

                    await db.query(
                        "UPDATE bookings SET booking_system = NULL WHERE id = ?",
                        [id]
                    );

                    followOnPayload = { ...followOnBooking, driver: driverId, is_follow_on: true };

                    const foNotifTitle = "New Follow-On Job";
                    const foNotifMsg = `Your next job #${followOnBooking.booking_id} is ready. Please accept or reject.`;

                    try {
                        await sendNotificationToDriver(db, driverId, foNotifTitle, foNotifMsg, {
                            booking_id: String(followOnId),
                            type: "new_ride"
                        });
                        await storeNotification(db, {
                            user_type: 'driver',
                            user_id: driverId,
                            title: foNotifTitle,
                            message: foNotifMsg
                        });
                    } catch (notifErr) {
                        console.error("[FollowOn] Notification error:", notifErr.message);
                    }

                    const [driverInfoRows] = await db.query("SELECT name FROM drivers WHERE id = ?", [driverId]);
                    const driverInfo = driverInfoRows[0];

                    followOnEventData = {
                        booking_id: followOnId,
                        driver_id: driverId,
                        driver_name: driverInfo?.name,
                        booking: { ...followOnBooking, driver: driverId, booking_status: 'pending_acceptance' },
                        message: `Follow-on job #${followOnBooking.booking_id} sent to ${driverInfo?.name} — waiting for acceptance`
                    };

                    setTimeout(async () => {
                        try {
                            const [checkRows] = await db.query(
                                "SELECT booking_status, driver FROM bookings WHERE id = ?",
                                [followOnId]
                            );
                            if (!checkRows.length) return;

                            const { booking_status: currentStatus, driver: currentDriver } = checkRows[0];

                            console.log(`[FollowOn] Timeout check for #${followOnId}: status=${currentStatus}, driver=${currentDriver}`);

                            if (currentStatus === 'ongoing') {
                                console.log(`[FollowOn] Job #${followOnId} accepted — status is ongoing`);
                                return;
                            }

                            if (currentStatus === 'cancelled' || currentStatus === 'completed') {
                                console.log(`[FollowOn] Job #${followOnId} is ${currentStatus} — no action`);
                                return;
                            }

                            if (currentStatus === 'pending_acceptance' && currentDriver == driverId) {
                                await db.query(
                                    `UPDATE bookings SET driver = NULL, booking_status = 'pending' WHERE id = ?`,
                                    [followOnId]
                                );

                                const timeoutEvent = {
                                    booking_id: followOnId,
                                    driver_id: driverId,
                                    driver_name: driverInfo?.name,
                                    message: `Driver ${driverInfo?.name} did not respond to follow-on job #${followOnBooking.booking_id} — reset to pending`
                                };
                                dispatcherSockets.forEach((sid) => io.to(sid).emit("follow-on-job-timeout", timeoutEvent));
                                adminSockets.forEach((sid) => io.to(sid).emit("follow-on-job-timeout", timeoutEvent));
                                clientSockets.forEach((sid) => io.to(sid).emit("follow-on-job-timeout", timeoutEvent));

                                console.log(`[FollowOn] Job #${followOnId} timed out — reset to pending`);
                            }
                        } catch (timeoutErr) {
                            console.error("[FollowOn] Timeout check error:", timeoutErr.message);
                        }
                    }, 30000);

                    console.log(`[FollowOn] Job #${followOnId} dispatched to driver #${driverId}`);
                }
            } catch (foError) {
                console.error(`[FollowOn] Error dispatching follow-on job:`, foError.message);
            }
        }

        if (booking.driver) {
            const driverSocketId = driverSockets.get(booking.driver.toString());
            if (driverSocketId) {
                io.to(driverSocketId).emit("booking-status-updated", {
                    booking_id: id,
                    status: booking_status,
                    message: `Ride status updated to ${booking_status}`
                });
                if (booking_status === 'cancelled' || booking_status === 'cancel') {
                    io.to(driverSocketId).emit("booking-cancelled-event", {
                        booking_id: id,
                        booking: booking,
                        message: cancelled_by_actor === 'user'
                            ? `Ride #${booking.booking_id} has been cancelled by customer`
                            : `Ride #${booking.booking_id} is cancelled by Admin or Dispatcher`
                    });
                }
            }
        }

        if (booking.user_id) {
            const userSocketId = userSockets.get(booking.user_id.toString());
            if (userSocketId) {
                io.to(userSocketId).emit("booking-status-updated", {
                    booking_id: id,
                    status: booking_status,
                    message: `Your ride status has been updated to ${booking_status}`
                });
                if (booking_status === 'cancelled' || booking_status === 'cancel') {
                    io.to(userSocketId).emit("booking-cancelled-event", {
                        booking_id: id,
                        booking: booking,
                        message: cancelled_by_actor === 'user'
                            ? `Your ride #${booking.booking_id} has been cancelled.`
                            : `Your ride #${booking.booking_id} has been cancelled by Admin or Dispatcher.`
                    });
                }
            }
        }

        const statusUpdateData = {
            booking_id: id,
            status: booking_status,
            message: `Booking #${booking.booking_id} status updated to ${booking_status}`
        };
        dispatcherSockets.forEach((sid) => io.to(sid).emit("booking-status-updated", statusUpdateData));
        adminSockets.forEach((sid) => io.to(sid).emit("booking-status-updated", statusUpdateData));

        const [updatedBookingRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
        const updatedBooking = updatedBookingRows[0];

        const socketPayload = {
            status: booking_status,
            booking: {
                ...updatedBooking,
                cancelled_by: cancelled_by_actor === 'admin' ? 'admin' : updatedBooking.cancelled_by
            }
        };

        if (updatedBooking.user_id) {
            const userSocketId = userSockets.get(updatedBooking.user_id.toString());
            if (userSocketId) io.to(userSocketId).emit("user-ride-status-event", socketPayload);
        }
        if (updatedBooking.driver) {
            const driverSocketId = driverSockets.get(updatedBooking.driver.toString());
            if (driverSocketId) io.to(driverSocketId).emit("driver-ride-status-event", socketPayload);
        }

        if (booking_status === 'cancelled') {
            const cancelNotif = {
                booking_id: id,
                booking_reference: updatedBooking.booking_id,
                message: `Booking #${updatedBooking.booking_id} has been cancelled`,
                cancelled_by: cancelled_by_actor
            };
            dispatcherSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
            adminSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
        }

        if (followOnPayload) {
            const driverSocketId = driverSockets.get(booking.driver.toString());
            if (driverSocketId) {
                io.to(driverSocketId).emit("new-ride-request", {
                    booking_id: followOnPayload.id,
                    assignment_type: "allocate_driver",
                    message: "You have a follow-on ride request",
                    booking: followOnPayload
                });
                console.log(`[FollowOn] new-ride-request sent to driver #${booking.driver}`);
            } else {
                console.log(`[FollowOn] Driver #${booking.driver} not connected via socket`);
            }
        }

        if (followOnEventData) {
            dispatcherSockets.forEach((sid) => io.to(sid).emit("follow-on-job-sent-to-driver", followOnEventData));
            adminSockets.forEach((sid) => io.to(sid).emit("follow-on-job-sent-to-driver", followOnEventData));
            clientSockets.forEach((sid) => io.to(sid).emit("follow-on-job-sent-to-driver", followOnEventData));
        }

        await broadcastDashboardCardsUpdate(req.tenantDb);

        return res.json({ success: true, message: "Booking status updated successfully", res_user });

    } catch (error) {
        console.error("Error updating booking status:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/bookings/:id/send-confirmation-email", async (req, res) => {
    try {
        const { id } = req.params;
        const { dispatcher_name } = req.body;
        const dispatcherName = dispatcher_name || "Dispatcher";

        const db = getConnection(req.tenantDb);

        await db.query(
            "UPDATE bookings SET dispatcher_action = ? WHERE id = ?",
            [`${dispatcherName} sent a booking confirmation email to the customer`, id]
        );

        const [bookings] = await db.query(`
            SELECT 
                b.*,
                d.id as driver_id,
                d.name as driver_name,
                d.email as driver_email,
                d.phone_no as driver_phone,
                d.profile_image as driver_profile_image,
                vt.id as vehicle_type_id,
                vt.vehicle_type_name as vehicle_type_name,
                vt.vehicle_type_service as vehicle_type_service,
                sc.id as sub_company_id,
                sc.name as sub_company_name,
                sc.email as sub_company_email
            FROM bookings b
            LEFT JOIN drivers d ON b.driver = d.id
            LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
            LEFT JOIN sub_companies sc ON b.sub_company = sc.id
            WHERE b.id = ?
        `, [id]);

        if (bookings.length === 0) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = bookings[0];

        if (!booking.email) {
            return res.status(400).json({ success: false, message: "Booking does not have an email address" });
        }

        const {
            driver_id, driver_name, driver_email, driver_phone, driver_profile_image,
            vehicle_type_id, vehicle_type_name, vehicle_type_service,
            sub_company_id, sub_company_name, sub_company_email,
            ...bookingData
        } = booking;

        const formattedBooking = {
            ...bookingData,
            driverDetail: driver_id ? {
                id: driver_id,
                name: driver_name,
                email: driver_email,
                phone_no: driver_phone,
                profile_image: driver_profile_image
            } : null,
            vehicleDetail: vehicle_type_id ? {
                id: vehicle_type_id,
                vehicle_type_name: vehicle_type_name,
                vehicle_type_service: vehicle_type_service
            } : null,
            subCompanyDetail: sub_company_id ? {
                id: sub_company_id,
                name: sub_company_name,
                email: sub_company_email
            } : null
        };

        const emailHtml = getBookingConfirmationEmail(formattedBooking);

        const mailOptions = {
            from: {
                name: 'Cabifyit',
                address: process.env.MAIL_FROM_ADDRESS || 'support@cabifyit.com'
            },
            to: booking.email,
            subject: `Booking Confirmation - ${booking.booking_id}`,
            html: emailHtml
        };

        const info = await transporter.sendMail(mailOptions);

        console.log(`Email sent successfully to ${booking.email}`);
        console.log('Message ID:', info.messageId);

        return res.json({
            success: true,
            message: "Booking confirmation email sent successfully",
            data: {
                booking_id: booking.booking_id,
                email: booking.email,
                messageId: info.messageId
            }
        });

    } catch (error) {
        console.error("Error sending confirmation email:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/bookings/broadcast", async (req, res) => {
    try {
        const { booking_id, tenantDb } = req.body;
        const DB_PREFIX = "tenant";

        const finalDb = `${DB_PREFIX}${tenantDb}`;

        console.log("Using DB:", finalDb);

        const db = getConnection(finalDb);

        const [rows] = await db.query(
            "SELECT * FROM bookings WHERE id = ?",
            [booking_id]
        );

        if (!rows.length) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = rows[0];
        let sentCount = 0;

        dispatcherSockets.forEach((socketId) => {
            io.to(socketId).emit("new-booking-event", booking);
            sentCount++;
        });

        adminSockets.forEach((socketId) => {
            io.to(socketId).emit("new-booking-event", booking);
            sentCount++;
        });

        clientSockets.forEach((socketId) => {
            io.to(socketId).emit("new-booking-event", booking);
            sentCount++;
        });

        // driverSockets.forEach((socketId) => {
        //     io.to(socketId).emit("new-ride", booking);
        //     sentCount++;
        // });

        await broadcastDashboardCardsUpdate(finalDb);

        return res.json({
            success: true,
            sent_to: sentCount,
            booking
        });

    } catch (error) {
        console.error("Broadcast error:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/send-new-ride", async (req, res) => {
    try {
        const { drivers, booking, tenantDb } = req.body;
        const db = getConnection(tenantDb || req.tenantDb);
        let sentCount = 0;

        for (const driverId of drivers) {
            const isAccepted = booking.booking_status === 'ongoing';
            const title = isAccepted ? "New Ride Assigned" : "New Ride Available";
            const message = isAccepted ? `You have been assigned a new ride #${booking.booking_id}. It is already accepted.` : "You have a new ride request";

            // Send Push Notification
            try {
                await sendNotificationToDriver(db, driverId, title, message, {
                    booking_id: String(booking.id),
                    type: "new_ride"
                });
            } catch (notifErr) {
                console.error("Notification error in /send-new-ride:", notifErr.message);
            }

            const socketId = driverSockets.get(driverId.toString());
            if (socketId) {
                if (isAccepted) {
                    io.to(socketId).emit("new-ride", booking);
                    io.to(socketId).emit("booking-status-updated", {
                        booking_id: booking.id,
                        status: booking.booking_status,
                        message: message
                    });
                } else {
                    io.to(socketId).emit("new-ride-request", {
                        booking_id: booking.id,
                        message: message,
                        booking: booking
                    });
                }
                sentCount++;
            }
        }
        return res.json({ success: true, sent_to: sentCount });
    } catch (error) {
        console.error("/send-new-ride error:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/send-notification-dispatcher", (req, res) => {
    console.log("mmediate");
    const { dispatchers, booking } = req.body;
    let sentCount = 0;
    dispatchers.forEach(dispatcherId => {
        const socketId = dispatcherSockets.get(dispatcherId.toString());
        if (socketId) {
            io.to(socketId).emit("notification-ride", booking);
            sentCount++;
        }
    });
    return res.json({ success: true, sent_to: sentCount });
});

app.post("/change-cancel-ride", async (req, res) => {
    const { drivers, status, booking } = req.body;
    const db = getConnection(req.tenantDb);
    let targetUserId = booking.user_id;
    if (!targetUserId) {
        try {
            const [rows] = await db.query("SELECT user_id FROM bookings WHERE id = ?", [booking.id]);
            if (rows.length > 0) targetUserId = rows[0].user_id;
        } catch (dbErr) {
            console.error("Error fetching user_id for notification:", dbErr.message);
        }
    }

    if (targetUserId) {
        try {
            const userNotifTitle = "Ride Cancelled";
            const userNotifMessage = req.body.cancelled_by === 'user' ? `Your ride #${booking.booking_id} has been successfully cancelled.` : `Your ride #${booking.booking_id} has been cancelled by Admin or Dispatcher.`;
            await sendNotificationToUser(db, targetUserId, userNotifTitle, userNotifMessage, {
                booking_id: String(booking.id),
                type: "ride_cancelled"
            });
            await storeNotification(db, {
                user_type: 'rider',
                user_id: targetUserId,
                title: userNotifTitle,
                message: userNotifMessage
            });
            console.log("Cancel notification sent to user:", targetUserId);
        } catch (userNotifErr) {
            console.error("User Notification error in /change-cancel-ride:", userNotifErr.message);
        }
    }

    if (drivers && drivers.length > 0) {
        const driverNotifTitle = "Ride Cancelled";
        const driverNotifMessage = req.body.cancelled_by === 'user' ? `Ride #${booking.booking_id} has been cancelled by customer` : `Ride #${booking.booking_id} has been cancelled`;

        for (const driverId of drivers) {
            try {
                await sendNotificationToDriver(db, driverId, driverNotifTitle, driverNotifMessage, {
                    booking_id: String(booking.id),
                    type: "ride_cancelled"
                });
                await storeNotification(db, {
                    user_type: 'driver',
                    user_id: driverId,
                    title: driverNotifTitle,
                    message: driverNotifMessage
                });
            } catch (driverNotifErr) {
                console.error("Driver Notification error in /change-cancel-ride:", driverNotifErr.message);
            }
        }
    }

    let sentCount = 0;
    drivers.forEach(driverId => {
        const socketId = driverSockets.get(driverId.toString());
        if (socketId) {
            io.to(socketId).emit("driver-ride-status-event", { status, booking });
            sentCount++;
        }
    });

    const cancelNotif = {
        booking_id: booking.id,
        booking: booking,
        message: req.body.cancelled_by === 'user' ? `Booking #${booking.booking_id} has been cancelled by customer` : `Booking #${booking.booking_id} has been cancelled`
    };
    dispatcherSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
    adminSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
    drivers.forEach(driverId => {
        const socketId = driverSockets.get(driverId.toString());
        if (socketId) {
            io.to(socketId).emit("booking-cancelled-event", cancelNotif);
        }
    });

    if (req.tenantDb) {
        await broadcastDashboardCardsUpdate(req.tenantDb);
    }

    return res.json({ success: true, sent_to: sentCount });
});

app.post("/send-new-booking", (req, res) => {
    const { dispatchers, booking } = req.body;
    let sentCount = 0;
    dispatchers.forEach(dispatcherId => {
        const socketId = dispatcherSockets.get(dispatcherId.toString());
        if (socketId) {
            io.to(socketId).emit("new-booking-event", booking);
            sentCount++;
        }
    });
    return res.json({ success: true, sent_to: sentCount });
});

app.post("/bid-accept", async (req, res) => {
    const { driverId, booking } = req.body;
    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("bid-accept-event", booking);
    }

    if (req.tenantDb) {
        await broadcastDashboardCardsUpdate(req.tenantDb);
    }

    return res.json({ success: true });
});

app.post("/place-bid", (req, res) => {
    const { userId, bid } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("place-bid-event", bid);
    }
    return res.json({ success: true });
});

app.post("/change-ride-status", async (req, res) => {
    const { userId, status, booking } = req.body;
    if (status === "cancel_confirm_ride" || status === "cancel_ride") {
        const db = getConnection(req.tenantDb);
        const targetUserId = userId || booking.user_id;

        if (targetUserId) {
            try {
                const userNotifTitle = "Ride Cancelled";
                const userNotifMessage = status === "cancel_confirm_ride" ? `Your ride #${booking.booking_id} has been successfully cancelled.` : `Your ride #${booking.booking_id} has been cancelled.`;
                await sendNotificationToUser(db, targetUserId, userNotifTitle, userNotifMessage, {
                    booking_id: String(booking.id),
                    type: "ride_cancelled"
                });
                await storeNotification(db, {
                    user_type: 'rider',
                    user_id: targetUserId,
                    title: userNotifTitle,
                    message: userNotifMessage
                });
            } catch (err) {
                console.error("Notification error in /change-ride-status (user):", err.message);
            }
        }

        if (booking.driver) {
            try {
                await sendNotificationToDriver(db, booking.driver, "Ride Cancelled", `Ride #${booking.booking_id} has been cancelled`, {
                    booking_id: String(booking.id),
                    type: "ride_cancelled"
                });
                await storeNotification(db, {
                    user_type: 'driver',
                    user_id: booking.driver,
                    title: "Ride Cancelled",
                    message: `Ride #${booking.booking_id} has been cancelled`
                });
            } catch (err) {
                console.error("Notification error in /change-ride-status (driver):", err.message);
            }
        }
    }

    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("user-ride-status-event", { status, booking });
    }

    if (status === "cancel_confirm_ride" || status === "cancel_ride") {
        const cancelNotif = {
            booking_id: booking.id,
            message: status === "cancel_confirm_ride" ? `Booking #${booking.booking_id} has been cancelled by customer` : `Booking #${booking.booking_id} has been cancelled`
        };
        dispatcherSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
        adminSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
    }

    if (req.tenantDb) {
        await broadcastDashboardCardsUpdate(req.tenantDb);
    }

    return res.json({ success: true });
});

app.post("/user-message-notification", (req, res) => {
    const { userId, chat } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("user-message-event", chat);
    }
    return res.json({ success: true });
});

app.post("/driver-message-notification", (req, res) => {
    const { driverId, chat } = req.body;
    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("driver-message-event", chat);
    }
    return res.json({ success: true });
});

app.post("/change-driver-ride-status", async (req, res) => {
    const { driverId, status, booking } = req.body;
    if (status === "cancel_confirm_ride" || status === "cancel_ride") {
        let targetUserId = booking.user_id;
        const db = getConnection(req.tenantDb);

        if (!targetUserId) {
            try {
                const [rows] = await db.query("SELECT user_id FROM bookings WHERE id = ?", [booking.id]);
                if (rows.length > 0) {
                    targetUserId = rows[0].user_id;
                }
            } catch (dbErr) {
                console.error("Error fetching user_id for /change-driver-ride-status:", dbErr.message);
            }
        }

        if (targetUserId) {
            try {
                const userNotifTitle = "Ride Cancelled";
                const userNotifMessage = status === "cancel_confirm_ride" ? `Your ride #${booking.booking_id} has been successfully cancelled.` : `Your ride #${booking.booking_id} has been cancelled.`;
                await sendNotificationToUser(db, targetUserId, userNotifTitle, userNotifMessage, {
                    booking_id: String(booking.id),
                    type: "ride_cancelled"
                });
                await storeNotification(db, {
                    user_type: 'rider',
                    user_id: targetUserId,
                    title: userNotifTitle,
                    message: userNotifMessage
                });
                console.log("Cancel notification sent to user:", targetUserId);
            } catch (userNotifErr) {
                console.error("User Notification error in /change-driver-ride-status:", userNotifErr.message);
            }
        }
    }

    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("driver-ride-status-event", { status, booking });
    }

    if (status === "cancel_confirm_ride" || status === "cancel_ride") {
        const cancelNotif = {
            booking_id: booking.id,
            booking: booking,
            message: status === "cancel_confirm_ride" ? `Booking #${booking.booking_id} has been cancelled by customer` : `Booking #${booking.booking_id} is cancelled by Admin or Dispatcher`
        };
        dispatcherSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
        adminSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
        if (socketId) {
            io.to(socketId).emit("booking-cancelled-event", cancelNotif);
        }
    }

    if (req.tenantDb) {
        await broadcastDashboardCardsUpdate(req.tenantDb);
    }

    return res.json({ success: true });
});

app.post("/on-job-driver", async (req, res) => {
    try {
        const { clientId, driver_id, driverName } = req.body;
        console.log(`🚕 On-Job Driver Request: clientId=${clientId}, driver_id=${driver_id}, driverName=${driverName}`);

        if (!req.tenantDb) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const db = getConnection(req.tenantDb);
        let finalDriverName = driverName;
        let finalDriverId = driver_id;

        if (driver_id) {
            const [driverRows] = await db.query("SELECT id, name FROM drivers WHERE id = ?", [driver_id]);
            if (driverRows.length > 0) {
                finalDriverName = driverRows[0].name;
                finalDriverId = driverRows[0].id;
            }
        }

        const eventData = {
            driver_id: finalDriverId,
            driverName: finalDriverName,
            driver_name: finalDriverName,
            status: 'busy'
        };
        const dbName = req.headers['database'] || req.headers['x-database'] || (req.tenantDb ? req.tenantDb.replace("tenant", "") : null);

        const socketId = clientSockets.get(clientId?.toString());
        if (socketId) {
            io.to(socketId).emit("on-job-driver-event", eventData);
        }

        if (dbName) {
            io.to(`dispatcher_${dbName}`).emit("on-job-driver-event", eventData);
            io.to(`admin_${dbName}`).emit("on-job-driver-event", eventData);
            io.to(`client_${dbName}`).emit("on-job-driver-event", eventData);
        }

        await broadcastDashboardCardsUpdate(req.tenantDb);

        return res.json({ success: true });
    } catch (error) {
        console.error("On-Job Driver Error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

app.post("/waiting-driver", async (req, res) => {
    try {
        const { clientId, driver_id } = req.body;

        if (!req.tenantDb) {
            const dbHeader = req.headers['database'] || req.headers['x-database'];
            if (dbHeader) {
                req.tenantDb = `tenant${dbHeader}`;
            }
        }

        if (!req.tenantDb) {
            console.error("Waiting Driver: Missing req.tenantDb and database header");
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const db = getConnection(req.tenantDb);

        const [driverRows] = await db.query(
            `SELECT d.id, d.name, d.driving_status, d.plot_id, p.name AS plot_name, d.priority_plot, d.updated_at
             FROM drivers d
             LEFT JOIN plots p ON d.plot_id = p.id
             WHERE d.id = ? 
             LIMIT 1`,
            [driver_id]
        );

        if (!driverRows.length) {
            console.error(`Waiting Driver: Driver ${driver_id} not found in ${req.tenantDb}`);
            return res.status(404).json({ success: false, message: "Driver not found" });
        }

        const driver = driverRows[0];
        const plotId = driver.plot_id;

        // ✅ FIX: plot_name already comes from LEFT JOIN — real name like "USA"
        const plotName = driver.plot_name || (plotId ? `Plot #${plotId}` : "N/A");

        let rank = 1;
        if (plotId) {
            const [rankRows] = await db.query(
                `SELECT COUNT(*) as count 
                 FROM drivers 
                 WHERE plot_id = ? AND driving_status = ? AND (updated_at < ? OR (updated_at = ? AND id < ?))`,
                [plotId, driver.driving_status, driver.updated_at, driver.updated_at, driver.id]
            );
            rank = rankRows[0].count + 1;
        }

        const eventData = {
            driver_id: driver.id,
            driverName: driver.name,
            driver_name: driver.name,
            plot: plotId,
            plot_name: plotName,   // ✅ Real name e.g. "USA"
            rank: rank
        };

        const dbName = req.headers['database'] || req.headers['x-database'] || (req.tenantDb ? req.tenantDb.replace("tenant", "") : null);

        const socketId = clientSockets.get(clientId?.toString());
        if (socketId) {
            io.to(socketId).emit("waiting-driver-event", eventData);
        }

        if (dbName) {
            io.to(`dispatcher_${dbName}`).emit("waiting-driver-event", eventData);
            io.to(`admin_${dbName}`).emit("waiting-driver-event", eventData);
            io.to(`client_${dbName}`).emit("waiting-driver-event", eventData);

            const driverSocketId = driverSockets.get(driver_id.toString());
            if (driverSocketId) {
                io.to(driverSocketId).emit("waiting-driver-event", eventData);
            }
        }

        await broadcastDashboardCardsUpdate(req.tenantDb);

        return res.json({ success: true });
    }
    catch (error) {
        console.error("Waiting Driver Error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

app.post("/send-reminder", (req, res) => {
    const { clientId, title, description } = req.body;
    const socketId = clientSockets.get(clientId.toString());
    if (socketId) {
        io.to(socketId).emit("send-reminder", { title, description });
    }
    return res.json({ success: true });
});

app.post("/voip-webhook", async (req, res) => {
    try {
        const { token, events } = req.body;

        if (token !== process.env.VIP_WEBHOOK_TOKEN) {
            console.log("Invalid VOIP token");
            return res.status(403).json({ success: false });
        }

        if (!Array.isArray(events)) {
            return res.status(400).json({ success: false, message: "Invalid events format" });
        }

        for (const event of events) {

            const { callId, dialledNumber, extension, callerId, status, time } = event;

            const voipData = {
                callId,
                dialledNumber,
                extension,
                callerId,
                status,
                time: new Date(time * 1000),
            };

            dispatcherSockets.forEach((socketId) => {
                io.to(socketId).emit("voip-call-update", voipData);
            });

            adminSockets.forEach((socketId) => {
                io.to(socketId).emit("voip-call-update", voipData);
            });
        }

        return res.status(200).json({ success: true });
    } catch (error) {
        console.error("Webhook Error:", error);
        return res.status(500).json({ success: false });
    }
});

app.get("/contact-us", async (req, res) => {
    try {
        const db = getConnection(req.tenantDb);

        const { type, status, search, page = 1, limit = 10 } = req.query;

        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const offset = (pageNum - 1) * limitNum;

        let baseQuery = `FROM contact_us WHERE 1=1`;
        const params = [];

        if (type && ['user', 'driver'].includes(type)) {
            baseQuery += ` AND user_type = ?`;
            params.push(type);
        }

        if (status) {
            baseQuery += ` AND status = ?`;
            params.push(status);
        }

        if (search) {
            baseQuery += ` AND message LIKE ?`;
            params.push(`%${search}%`);
        }

        const dataQuery = `SELECT * ${baseQuery} ORDER BY id DESC LIMIT ? OFFSET ?`;
        const [list] = await db.query(dataQuery, [...params, limitNum, offset]);

        const countQuery = `SELECT COUNT(*) AS total ${baseQuery}`;
        const [[{ total }]] = await db.query(countQuery, params);

        return res.json({
            success: true,
            data: list,
            pagination: {
                total,
                page: pageNum,
                limit: limitNum,
                total_pages: Math.ceil(total / limitNum),
                hasNext: pageNum * limitNum < total,
                hasPrev: pageNum > 1
            }
        });

    } catch (error) {
        console.error("Contact Us fetch error:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.get("/driver/:id/riding-details", async (req, res) => {
    try {
        const { id } = req.params;
        const {
            page = 1,
            limit = 10,
            start_date,
            end_date,
            status
        } = req.query;

        const databaseHeader =
            req.headers["x-database"] ||
            req.headers["database"] ||
            req.query.database;

        if (!databaseHeader) {
            return res.status(400).json({
                success: false,
                message: "Database header is required"
            });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [driverRows] = await db.query(
            `SELECT id, name, email, phone_no, profile_image, driving_status,
                    plot_id, priority_plot, wallet_balance, last_settlement_date,
                    created_at
             FROM drivers WHERE id = ?`,
            [id]
        );

        if (!driverRows.length) {
            return res.status(404).json({
                success: false,
                message: "Driver not found"
            });
        }

        const driver = driverRows[0];

        const [revenueSummary] = await db.query(
            `SELECT
                COUNT(*) AS total_rides,

                COUNT(CASE WHEN booking_status = 'completed' THEN 1 END) AS completed_rides,
                COUNT(CASE WHEN booking_status = 'cancelled' THEN 1 END) AS cancelled_rides,
                COUNT(CASE WHEN booking_status = 'ongoing' THEN 1 END) AS ongoing_rides,
                COUNT(CASE WHEN booking_status = 'arrived' THEN 1 END) AS arrived_rides,
                COUNT(CASE WHEN booking_status = 'no_show' THEN 1 END) AS no_show_rides,
                COUNT(CASE WHEN booking_status = 'pending' THEN 1 END) AS pending_rides,

                COALESCE(SUM(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE 0
                        END
                    ELSE 0 END
                ), 0) AS total_revenue,

                COALESCE(SUM(
                    CASE
                        WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                        WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                        WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                        ELSE 0
                    END
                ), 0) AS gross_fare_all_rides,

                COALESCE(AVG(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE NULL
                        END
                    ELSE NULL END
                ), 0) AS average_fare

             FROM bookings
             WHERE driver = ?`,
            [id]
        );

        const revenue = revenueSummary[0];

        const [todayStats] = await db.query(
            `SELECT
                COUNT(*) AS today_total_rides,
                COUNT(CASE WHEN booking_status = 'completed' THEN 1 END) AS today_completed,
                COALESCE(SUM(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE 0
                        END
                    ELSE 0 END
                ), 0) AS today_revenue
             FROM bookings
             WHERE driver = ? AND DATE(booking_date) = CURDATE()`,
            [id]
        );

        const [weekStats] = await db.query(
            `SELECT
                COUNT(*) AS week_total_rides,
                COUNT(CASE WHEN booking_status = 'completed' THEN 1 END) AS week_completed,
                COALESCE(SUM(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE 0
                        END
                    ELSE 0 END
                ), 0) AS week_revenue
             FROM bookings
             WHERE driver = ? AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)`,
            [id]
        );

        const [monthStats] = await db.query(
            `SELECT
                COUNT(*) AS month_total_rides,
                COUNT(CASE WHEN booking_status = 'completed' THEN 1 END) AS month_completed,
                COALESCE(SUM(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE 0
                        END
                    ELSE 0 END
                ), 0) AS month_revenue
             FROM bookings
             WHERE driver = ?
               AND MONTH(booking_date) = MONTH(CURDATE())
               AND YEAR(booking_date) = YEAR(CURDATE())`,
            [id]
        );

        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const offset = (pageNum - 1) * limitNum;

        let rideWhereClause = `WHERE b.driver = ?`;
        const rideParams = [id];

        if (start_date) {
            rideWhereClause += ` AND DATE(b.booking_date) >= ?`;
            rideParams.push(start_date);
        }
        if (end_date) {
            rideWhereClause += ` AND DATE(b.booking_date) <= ?`;
            rideParams.push(end_date);
        }
        if (status) {
            rideWhereClause += ` AND b.booking_status = ?`;
            rideParams.push(status);
        }

        const [rides] = await db.query(
            `SELECT
                b.id,
                b.booking_id,
                b.name AS passenger_name,
                b.phone_no AS passenger_phone,
                b.email AS passenger_email,
                b.pickup_location,
                b.destination_location,
                b.booking_date,
                b.pickup_time,
                b.booking_status,
                b.booking_amount,
                b.offered_amount,
                b.recommended_amount,
                b.cancel_reason,
                b.created_at,
                b.updated_at,
                vt.vehicle_type_name,
                vt.vehicle_type_service,
                sc.name AS sub_company_name,
                CASE
                    WHEN b.booking_amount IS NOT NULL AND b.booking_amount > 0 THEN b.booking_amount
                    WHEN b.offered_amount IS NOT NULL AND b.offered_amount > 0 THEN b.offered_amount
                    WHEN b.recommended_amount IS NOT NULL AND b.recommended_amount > 0 THEN b.recommended_amount
                    ELSE 0
                END AS effective_fare
             FROM bookings b
             LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
             LEFT JOIN sub_companies sc ON b.sub_company = sc.id
             ${rideWhereClause}
             ORDER BY b.booking_date DESC, b.id DESC
             LIMIT ? OFFSET ?`,
            [...rideParams, limitNum, offset]
        );

        const [[{ total }]] = await db.query(
            `SELECT COUNT(*) AS total FROM bookings b ${rideWhereClause}`,
            rideParams
        );

        const totalPages = Math.ceil(total / limitNum);

        return res.json({
            success: true,
            data: {
                driver: {
                    id: driver.id,
                    name: driver.name,
                    email: driver.email,
                    phone_no: driver.phone_no,
                    profile_image: driver.profile_image,
                    driving_status: driver.driving_status,
                    plot_id: driver.plot_id,
                    priority_plot: driver.priority_plot,
                    wallet_balance: parseFloat(driver.wallet_balance || 0).toFixed(2),
                    last_settlement_date: driver.last_settlement_date,
                    member_since: driver.created_at
                },

                revenue_summary: {
                    total_revenue: parseFloat(revenue.total_revenue).toFixed(2),
                    gross_fare_all_rides: parseFloat(revenue.gross_fare_all_rides).toFixed(2),
                    average_fare: parseFloat(revenue.average_fare).toFixed(2),
                    today: {
                        rides: todayStats[0].today_total_rides,
                        completed: todayStats[0].today_completed,
                        revenue: parseFloat(todayStats[0].today_revenue).toFixed(2)
                    },
                    this_week: {
                        rides: weekStats[0].week_total_rides,
                        completed: weekStats[0].week_completed,
                        revenue: parseFloat(weekStats[0].week_revenue).toFixed(2)
                    },
                    this_month: {
                        rides: monthStats[0].month_total_rides,
                        completed: monthStats[0].month_completed,
                        revenue: parseFloat(monthStats[0].month_revenue).toFixed(2)
                    }
                },

                ride_statistics: {
                    total_rides: revenue.total_rides,
                    completed: revenue.completed_rides,
                    cancelled: revenue.cancelled_rides,
                    ongoing: revenue.ongoing_rides,
                    arrived: revenue.arrived_rides,
                    no_show: revenue.no_show_rides,
                    pending: revenue.pending_rides,
                    completion_rate: revenue.total_rides > 0
                        ? ((revenue.completed_rides / revenue.total_rides) * 100).toFixed(1) + "%"
                        : "0.0%"
                },

                rides: rides,

                pagination: {
                    total,
                    page: pageNum,
                    limit: limitNum,
                    total_pages: totalPages,
                    hasNext: pageNum < totalPages,
                    hasPrev: pageNum > 1
                }
            }
        });

    } catch (error) {
        console.error("Driver riding details error:", error);
        return res.status(500).json({
            success: false,
            message: error.message
        });
    }
});

app.post("/account/collect-and-email", async (req, res) => {
    try {
        const { account_id } = req.body;
        if (!account_id) {
            return res.status(400).json({ success: 0, message: "Account ID is required" });
        }

        const databaseHeader = req.headers["x-database"] || req.headers["database"];
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [accountRows] = await db.query("SELECT * FROM accounts WHERE id = ?", [account_id]);
        if (!accountRows.length) {
            return res.status(404).json({ success: 0, message: "Account not found" });
        }
        const account = accountRows[0];

        const [bookings] = await db.query(`
            SELECT id as booking_id, booking_date as date, 
                   COALESCE(booking_amount, offered_amount, recommended_amount, 0) as amount,
                   CONCAT(pickup_location, ' to ', destination_location) as route
            FROM bookings 
            WHERE account = ? AND account_payment = 'no'
        `, [account_id]);

        if (!bookings.length) {
            return res.status(400).json({ success: 0, message: "No uncollected rides found for this account" });
        }

        const totalAmount = bookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);
        const dateOfCollection = new Date().toLocaleDateString();

        const ridesTableRows = bookings.map(b => `
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: left;">#${b.booking_id}</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: left;">${new Date(b.date).toLocaleString()}</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: left;">${b.route || 'N/A'}</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: #333;">$${parseFloat(b.amount || 0).toFixed(2)}</td>
            </tr>
        `).join('');

        const htmlContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
                .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .header { background-color: #2c3e50; color: #ffffff; padding: 20px; text-align: center; }
                .header h2 { margin: 0; font-size: 24px; letter-spacing: 1px; }
                .body-content { padding: 30px; }
                .account-info { margin-bottom: 25px; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #3498db; border-radius: 4px; }
                .account-info p { margin: 5px 0; color: #555; font-size: 15px; }
                .total-amount-box { text-align: center; background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; padding: 20px; border-radius: 8px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(46, 204, 113, 0.3); }
                .total-amount-box h3 { margin: 0; font-size: 16px; font-weight: normal; text-transform: uppercase; letter-spacing: 1px; }
                .total-amount-box h1 { margin: 10px 0 0; font-size: 38px; }
                .rides-table-container { overflow-x: auto; }
                .rides-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                .rides-table th { background-color: #ecf0f1; padding: 12px 10px; text-align: left; font-size: 14px; color: #34495e; border-bottom: 2px solid #bdc3c7; }
                .rides-table td { font-size: 14px; color: #555; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #aaa; border-top: 1px solid #eee; background-color: #fafafa; }
                @media only screen and (max-width: 600px) {
                    .body-content { padding: 15px; }
                    .total-amount-box h1 { font-size: 32px; }
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="header">
                    <h2>Invoice & Ride Collection</h2>
                </div>
                
                <div class="body-content">
                    <div class="account-info">
                        <p><strong>Account Name:</strong> ${account.name}</p>
                        <p><strong>Email:</strong> ${account.email}</p>
                        ${account.company ? `<p><strong>Company:</strong> ${account.company}</p>` : ''}
                        <p><strong>Collection Date:</strong> ${dateOfCollection}</p>
                    </div>

                    <div class="total-amount-box">
                        <h3>Total Collected Amount</h3>
                        <h1>$${totalAmount.toFixed(2)}</h1>
                    </div>
                </div>

                <div class="footer">
                    <p>Thank you for choosing our services.</p>
                    <p>&copy; ${new Date().getFullYear()} Cabify IT. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        `;

        const mailOptions = {
            from: process.env.MAIL_FROM_ADDRESS || 'noreply@cabifyit.com',
            to: account.email,
            subject: 'Invoice & Ride Collection Summary',
            html: htmlContent
        };

        await transporter.sendMail(mailOptions);

        await db.query(`
            UPDATE bookings 
            SET account_payment = 'yes' 
            WHERE account = ? AND account_payment = 'no'
        `, [account_id]);

        return res.json({
            success: 1,
            message: "Email sent successfully and rides marked as collected."
        });

    } catch (err) {
        console.error("Account Collect & Email Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/driver/send-invoice', async (req, res) => {
    try {
        const { driver_id } = req.body;
        if (!driver_id) return res.status(400).json({ success: 0, message: "Driver ID is required" });

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) return res.status(400).json({ success: 0, message: "Database header is required" });

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [driverRows] = await db.query("SELECT * FROM drivers WHERE id = ?", [driver_id]);
        if (!driverRows.length) return res.status(404).json({ success: 0, message: "Driver not found" });
        const driver = driverRows[0];

        if (!driver.email) return res.status(400).json({ success: 0, message: "Driver email not found" });

        const [rides] = await db.query(`
            SELECT id, booking_id, booking_date, pickup_location, destination_location, booking_amount 
            FROM bookings 
            WHERE driver = ? AND booking_status = 'completed' 
            ORDER BY booking_date DESC LIMIT 50
        `, [driver_id]);

        let totalAmount = rides.reduce((sum, r) => sum + parseFloat(r.booking_amount || 0), 0);

        const doc = new PDFDocument({ margin: 50 });
        let buffers = [];
        doc.on('data', buffers.push.bind(buffers));
        doc.on('end', async () => {
            try {
                let pdfData = Buffer.concat(buffers);

                const mailOptions = {
                    from: process.env.MAIL_FROM_ADDRESS || 'noreply@cabifyit.com',
                    to: driver.email,
                    subject: 'Your Driver Invoice',
                    text: 'Please find attached your invoice details and completed rides.',
                    attachments: [{
                        filename: `invoice-${driver_id}.pdf`,
                        content: pdfData,
                        contentType: 'application/pdf'
                    }]
                };

                await transporter.sendMail(mailOptions);
                return res.json({
                    success: 1,
                    message: "Invoice sent successfully",
                    pdf_base64: pdfData.toString('base64')
                });
            } catch (mailErr) {
                console.error("Email Sending Error:", mailErr);
                return res.status(500).json({ success: 0, message: "Failed to send email" });
            }
        });

        doc.fontSize(20).text('Driver Invoice', { align: 'center' });
        doc.moveDown();
        doc.fontSize(12).text(`Driver Name: ${driver.name}`);
        doc.text(`Email: ${driver.email}`);
        doc.text(`Phone: ${driver.phone_no || 'N/A'}`);
        doc.text(`Date: ${new Date().toLocaleDateString()}`);
        doc.moveDown();

        doc.fontSize(14).text('Recent Completed Rides Summary', { underline: true });
        doc.moveDown();

        rides.forEach((r, idx) => {
            doc.fontSize(10).text(`${idx + 1}. Booking ID: ${r.booking_id} | Date: ${new Date(r.booking_date).toLocaleDateString()} | Amount: $${(parseFloat(r.booking_amount) || 0).toFixed(2)}`);
        });

        doc.moveDown();
        doc.fontSize(14).text(`Total Amount: $${totalAmount.toFixed(2)}`, { align: 'right' });

        doc.end();

    } catch (err) {
        console.error("Driver Invoice Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/user/send-invoice', async (req, res) => {
    try {
        const { user_id } = req.body;
        if (!user_id) return res.status(400).json({ success: 0, message: "User ID is required" });

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) return res.status(400).json({ success: 0, message: "Database header is required" });

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [userRows] = await db.query("SELECT * FROM users WHERE id = ?", [user_id]);
        if (!userRows.length) return res.status(404).json({ success: 0, message: "User not found" });
        const user = userRows[0];

        if (!user.email) return res.status(400).json({ success: 0, message: "User email not found" });

        const [rides] = await db.query(`
            SELECT id, booking_id, booking_date, pickup_location, destination_location, booking_amount 
            FROM bookings 
            WHERE user_id = ? AND booking_status = 'completed' 
            ORDER BY booking_date DESC LIMIT 50
        `, [user_id]);

        let totalAmount = rides.reduce((sum, r) => sum + parseFloat(r.booking_amount || 0), 0);

        const doc = new PDFDocument({ margin: 50 });
        let buffers = [];
        doc.on('data', buffers.push.bind(buffers));
        doc.on('end', async () => {
            try {
                let pdfData = Buffer.concat(buffers);

                const mailOptions = {
                    from: process.env.MAIL_FROM_ADDRESS || 'noreply@cabifyit.com',
                    to: user.email,
                    subject: 'Your User Invoice',
                    text: 'Please find attached your invoice details and completed rides.',
                    attachments: [{
                        filename: `invoice-${user_id}.pdf`,
                        content: pdfData,
                        contentType: 'application/pdf'
                    }]
                };

                await transporter.sendMail(mailOptions);
                return res.json({
                    success: 1,
                    message: "Invoice sent successfully",
                    pdf_base64: pdfData.toString('base64')
                });
            } catch (mailErr) {
                console.error("Email Sending Error:", mailErr);
                return res.status(500).json({ success: 0, message: "Failed to send email" });
            }
        });

        doc.fontSize(20).text('User Invoice', { align: 'center' });
        doc.moveDown();
        doc.fontSize(12).text(`User Name: ${user.name || user.first_name || 'N/A'}`);
        doc.text(`Email: ${user.email}`);
        doc.text(`Phone: ${user.phone || user.mobile || 'N/A'}`);
        doc.text(`Date: ${new Date().toLocaleDateString()}`);
        doc.moveDown();

        doc.fontSize(14).text('Recent Completed Rides Summary', { underline: true });
        doc.moveDown();

        rides.forEach((r, idx) => {
            doc.fontSize(10).text(`${idx + 1}. Booking ID: ${r.booking_id} | Date: ${new Date(r.booking_date).toLocaleDateString()} | Amount: $${(parseFloat(r.booking_amount) || 0).toFixed(2)}`);
        });

        doc.moveDown();
        doc.fontSize(14).text(`Total Amount: $${totalAmount.toFixed(2)}`, { align: 'right' });

        doc.end();

    } catch (err) {
        console.error("User Invoice Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/account/send-invoice', async (req, res) => {
    try {
        const { account_id } = req.body;
        if (!account_id) {
            return res.status(400).json({ success: 0, message: "Account ID is required" });
        }

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [accountRows] = await db.query("SELECT * FROM accounts WHERE id = ?", [account_id]);
        if (!accountRows.length) {
            return res.status(404).json({ success: 0, message: "Account not found" });
        }
        const account = accountRows[0];

        const [bookings] = await db.query(`
            SELECT 
                id as booking_id, 
                booking_id as booking_reference,
                booking_date, 
                pickup_time,
                pickup_location, 
                destination_location,
                COALESCE(booking_amount, offered_amount, recommended_amount, 0) as amount,
                booking_status,
                name as passenger_name,
                phone_no as passenger_phone
            FROM bookings 
            WHERE account = ? 
            ORDER BY booking_date DESC, id DESC
        `, [account_id]);

        if (!bookings.length) {
            return res.status(400).json({ success: 0, message: "No bookings found for this account" });
        }

        const totalAmount = bookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);
        const completedBookings = bookings.filter(b => b.booking_status === 'completed');
        const completedAmount = completedBookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);

        const doc = new PDFDocument({ margin: 50 });
        let buffers = [];
        doc.on('data', buffers.push.bind(buffers));
        doc.on('end', async () => {
            try {
                let pdfData = Buffer.concat(buffers);

                if (account.email) {
                    try {
                        const mailOptions = {
                            from: process.env.MAIL_FROM_ADDRESS || 'noreply@cabifyit.com',
                            to: account.email,
                            subject: `Invoice for Account - ${account.name || account.company}`,
                            text: 'Please find attached your invoice with all booking details.',
                            attachments: [{
                                filename: `account-invoice-${account_id}.pdf`,
                                content: pdfData,
                                contentType: 'application/pdf'
                            }]
                        };

                        await transporter.sendMail(mailOptions);
                        console.log(`Invoice email sent to account: ${account.email}`);
                    } catch (emailErr) {
                        console.error("Email sending failed:", emailErr.message);
                    }
                }
                return res.json({
                    success: 1,
                    message: "Account invoice sent successfully",
                    account_id: account_id,
                    account_name: account.name || account.company,
                    email: account.email,
                    total_bookings: bookings.length,
                    total_amount: totalAmount.toFixed(2),
                    completed_bookings: completedBookings.length,
                    completed_amount: completedAmount.toFixed(2),
                    pdf_base64: pdfData.toString('base64')
                });

            } catch (err) {
                console.error("Account Invoice Error:", err);
                return res.status(500).json({ success: 0, message: "Failed to generate PDF" });
            }
        });

        doc.fontSize(20).text('Account Invoice', { align: 'center' });
        doc.moveDown();

        doc.fontSize(14).text('Account Information', { underline: true });
        doc.fontSize(12);
        doc.text(`Account Name: ${account.name || 'N/A'}`);
        if (account.company) doc.text(`Company: ${account.company}`);
        doc.text(`Email: ${account.email}`);
        if (account.phone) doc.text(`Phone: ${account.phone}`);
        doc.text(`Invoice Date: ${new Date().toLocaleDateString()}`);
        doc.moveDown();

        doc.fontSize(14).text('Summary', { underline: true });
        doc.fontSize(12);
        doc.text(`Total Bookings: ${bookings.length}`);
        doc.text(`Completed Bookings: ${completedBookings.length}`);
        doc.text(`Total Amount: $${totalAmount.toFixed(2)}`);
        doc.text(`Completed Amount: $${completedAmount.toFixed(2)}`);
        doc.moveDown();

        doc.fontSize(14).text('Booking Details', { underline: true });
        doc.moveDown();

        bookings.forEach((booking, idx) => {
            doc.fontSize(10);
            doc.text(`${idx + 1}. Booking ID: ${booking.booking_reference}`);
            doc.text(`   Date: ${new Date(booking.booking_date).toLocaleDateString()} ${booking.pickup_time || ''}`);
            doc.text(`   Route: ${booking.pickup_location} -> ${booking.destination_location}`);
            doc.text(`   Passenger: ${booking.passenger_name} (${booking.passenger_phone})`);
            doc.text(`   Status: ${booking.booking_status}`);
            doc.text(`   Amount: $${parseFloat(booking.amount || 0).toFixed(2)}`);
            doc.moveDown(0.5);
        });

        doc.moveDown();
        doc.fontSize(10).text('This is a computer-generated invoice.', { align: 'center' });
        doc.text(`Generated on: ${new Date().toLocaleString()}`, { align: 'center' });

        doc.end();

    } catch (err) {
        console.error("Account Invoice Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/sub-company/send-invoice', async (req, res) => {
    try {
        const { sub_company_id } = req.body;
        if (!sub_company_id) {
            return res.status(400).json({ success: 0, message: "Sub Company ID is required" });
        }

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [subCompanyRows] = await db.query("SELECT * FROM sub_companies WHERE id = ?", [sub_company_id]);
        if (!subCompanyRows.length) {
            return res.status(404).json({ success: 0, message: "Sub company not found" });
        }
        const subCompany = subCompanyRows[0];

        const [bookings] = await db.query(`
            SELECT 
                id as booking_id, 
                booking_id as booking_reference,
                booking_date, 
                pickup_time,
                pickup_location, 
                destination_location,
                COALESCE(booking_amount, offered_amount, recommended_amount, 0) as amount,
                booking_status,
                name as passenger_name,
                phone_no as passenger_phone
            FROM bookings 
            WHERE sub_company = ? 
            ORDER BY booking_date DESC, id DESC
        `, [sub_company_id]);

        if (!bookings.length) {
            return res.status(400).json({ success: 0, message: "No bookings found for this sub company" });
        }

        const totalAmount = bookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);
        const completedBookings = bookings.filter(b => b.booking_status === 'completed');
        const completedAmount = completedBookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);

        const doc = new PDFDocument({ margin: 50 });
        let buffers = [];
        doc.on('data', buffers.push.bind(buffers));
        doc.on('end', async () => {
            try {
                let pdfData = Buffer.concat(buffers);

                if (subCompany.email) {
                    try {
                        const mailOptions = {
                            from: process.env.MAIL_FROM_ADDRESS || 'noreply@cabifyit.com',
                            to: subCompany.email,
                            subject: `Invoice for Sub Company - ${subCompany.name || 'N/A'}`,
                            text: 'Please find attached your invoice with all booking details.',
                            attachments: [{
                                filename: `sub-company-invoice-${sub_company_id}.pdf`,
                                content: pdfData,
                                contentType: 'application/pdf'
                            }]
                        };

                        await transporter.sendMail(mailOptions);
                        console.log(`Invoice email sent to sub company: ${subCompany.email}`);
                    } catch (emailErr) {
                        console.error("Email sending failed:", emailErr.message);
                    }
                }
                return res.json({
                    success: 1,
                    message: "Sub company invoice sent successfully",
                    sub_company_id: sub_company_id,
                    sub_company_name: subCompany.name,
                    email: subCompany.email,
                    total_bookings: bookings.length,
                    total_amount: totalAmount.toFixed(2),
                    completed_bookings: completedBookings.length,
                    completed_amount: completedAmount.toFixed(2),
                    pdf_base64: pdfData.toString('base64')
                });

            } catch (err) {
                console.error("Sub Company Invoice Error:", err);
                return res.status(500).json({ success: 0, message: "Failed to generate PDF" });
            }
        });

        doc.fontSize(20).text('Sub Company Invoice', { align: 'center' });
        doc.moveDown();

        doc.fontSize(14).text('Sub Company Information', { underline: true });
        doc.fontSize(12);
        doc.text(`Company Name: ${subCompany.name || 'N/A'}`);
        doc.text(`Email: ${subCompany.email || 'N/A'}`);
        if (subCompany.phone) doc.text(`Phone: ${subCompany.phone}`);
        if (subCompany.address) doc.text(`Address: ${subCompany.address}`);
        doc.text(`Invoice Date: ${new Date().toLocaleDateString()}`);
        doc.moveDown();

        doc.fontSize(14).text('Summary', { underline: true });
        doc.fontSize(12);
        doc.text(`Total Bookings: ${bookings.length}`);
        doc.text(`Completed Bookings: ${completedBookings.length}`);
        doc.text(`Total Amount: $${totalAmount.toFixed(2)}`);
        doc.text(`Completed Amount: $${completedAmount.toFixed(2)}`);
        doc.moveDown();

        doc.fontSize(14).text('Booking Details', { underline: true });
        doc.moveDown();

        bookings.forEach((booking, idx) => {
            doc.fontSize(10);
            doc.text(`${idx + 1}. Booking ID: ${booking.booking_reference}`);
            doc.text(`   Date: ${new Date(booking.booking_date).toLocaleDateString()} ${booking.pickup_time || ''}`);
            doc.text(`   Route: ${booking.pickup_location} -> ${booking.destination_location}`);
            doc.text(`   Passenger: ${booking.passenger_name} (${booking.passenger_phone || 'N/A'})`);
            doc.text(`   Status: ${booking.booking_status}`);
            doc.text(`   Amount: $${parseFloat(booking.amount || 0).toFixed(2)}`);
            doc.moveDown(0.5);
        });

        doc.moveDown();
        doc.fontSize(10).text('This is a computer-generated invoice.', { align: 'center' });
        doc.text(`Generated on: ${new Date().toLocaleString()}`, { align: 'center' });

        doc.end();

    } catch (err) {
        console.error("Sub Company Invoice Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/driver/send-package-history', async (req, res) => {
    try {
        const { driver_id } = req.body;
        if (!driver_id) return res.status(400).json({ success: 0, message: "Driver ID is required" });

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) return res.status(400).json({ success: 0, message: "Database header is required" });

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [settingsRows] = await db.query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
        if (!settingsRows.length) return res.status(404).json({ success: 0, message: "Company settings not found" });
        const settings = settingsRows[0];

        const [driverRows] = await db.query("SELECT * FROM drivers WHERE id = ?", [driver_id]);
        if (!driverRows.length) return res.status(404).json({ success: 0, message: "Driver not found" });
        const driver = driverRows[0];

        if (!driver.email) return res.status(400).json({ success: 0, message: "Driver email not found" });

        const packageTypeMapping = {
            "per_ride_commission_top_up": "Per Ride Commission (Top Up)",
            "packages_top_up": "Packages (Top Up)",
            "commission_without_topup": "Commission without Top Up Settled Later",
            "packages_post_paid": "Packages Post Paid"
        };
        const packageTypeFormatted = packageTypeMapping[settings.package_type] || settings.package_type;

        let allEntries = [];
        if (settings.package_type === "packages_post_paid") {
            allEntries = await calculatePostPaidEntries(driver, settings, db);
        } else if (settings.package_type === "commission_without_topup") {
            allEntries = await calculatePercentageEntries(driver, settings, db);
        }

        const totalAmount = allEntries.reduce((sum, e) => sum + parseFloat(e.amount || 0), 0);

        const entriesHtml = allEntries.length > 0 ? allEntries.map((e, idx) => `
            <tr>
                <td>${idx + 1}</td>
                <td>${e.cycle_start_date} to ${e.cycle_end_date}</td>
                <td>${e.description}</td>
                <td>$${(parseFloat(e.amount) || 0).toFixed(2)}</td>
            </tr>
        `).join('') : '<tr><td colspan="4" style="text-align:center;">No history available</td></tr>';

        const htmlContent = `
            <h2>Driver Package History</h2>
            <p><strong>Driver Name:</strong> ${driver.name || 'N/A'}</p>
            <p><strong>Email:</strong> ${driver.email}</p>
            <p><strong>Phone:</strong> ${driver.phone_no || 'N/A'}</p>
            <p><strong>Package Type:</strong> ${packageTypeFormatted}</p>
            <p><strong>Date:</strong> ${new Date().toLocaleDateString()}</p>
            <h3>Package Details Summary</h3>
            <table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">
                <tr>
                    <th>#</th>
                    <th>Period</th>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
                ${entriesHtml}
            </table>
            <h3>Total Package Amount: $${totalAmount.toFixed(2)}</h3>
        `;

        const mailOptions = {
            from: process.env.MAIL_FROM_ADDRESS || 'noreply@cabifyit.com',
            to: driver.email,
            subject: 'Your Driver Package History',
            html: htmlContent
        };

        try {
            await transporter.sendMail(mailOptions);
            return res.json({
                success: 1,
                message: "Package history sent successfully",
                package_type: packageTypeFormatted,
                data: allEntries
            });
        } catch (mailErr) {
            console.error("Email Sending Error:", mailErr);
            return res.status(500).json({ success: 0, message: "Failed to send email" });
        }

    } catch (err) {
        console.error("Package History Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

server.listen(3001, "0.0.0.0", () => {
    console.log("🚀 Socket server running on port 3001");
});