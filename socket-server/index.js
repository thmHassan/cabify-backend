const path = require("path");
require("dotenv").config({
    path: path.join(__dirname, "../.env"),
});

const express = require("express");
const cors = require("cors")
const http = require("http");
const { Server } = require("socket.io");
const axios = require("axios");
const { getConnection } = require("./db")
const transporter = require("./utils/Emailconfig");
const { sendNotificationToDriver } = require("./utils/FCMService");

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

        console.log("Broadcasting dashboard cards update:", dashboardData);

        dispatcherSockets.forEach((socketId) => {
            io.to(socketId).emit("dashboard-cards-update", dashboardData);
        });

        adminSockets.forEach((socketId) => {
            io.to(socketId).emit("dashboard-cards-update", dashboardData);
        });

        clientSockets.forEach((socketId) => {
            io.to(socketId).emit("dashboard-cards-update", dashboardData);
        });

        return dashboardData;
    } catch (error) {
        console.error("❌ Error broadcasting dashboard cards:", error);
        return null;
    }
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

        const [bookingRows] = await db.query(
            "SELECT * FROM bookings WHERE id = ?",
            [bookingId]
        );

        if (!bookingRows.length) return;
        const booking = bookingRows[0];

        if (booking.driver_response === "accepted") {
            console.log("Ride already accepted. Stop dispatch.");
            return;
        }

        if (!currentPlotId) {
            currentPlotId = booking.pickup_plot_id || booking.destination_plot_id;
        }

        if (!currentPlotId) {
            console.log("No plot assigned to booking.");
            return;
        }

        if (visitedPlots.includes(currentPlotId)) {
            console.log("All plots tried. No driver accepted.");
            io.emit("auto-dispatch-failed", {
                booking_id: bookingId,
                message: "No drivers accepted the ride."
            });
            return;
        }

        visitedPlots.push(currentPlotId);

        const [drivers] = await db.query(
            `SELECT * FROM drivers
             WHERE driving_status = 'idle'
             AND plot_id = ?
             ORDER BY priority_plot ASC`,
            [currentPlotId]
        );

        if (!drivers.length) {
            console.log("No drivers in plot:", currentPlotId);

            const [plotRows] = await db.query(
                "SELECT backup_plots FROM plots WHERE id = ?",
                [currentPlotId]
            );

            const backupPlots = JSON.parse(plotRows[0]?.backup_plots || "[]");

            for (let backupPlot of backupPlots) {
                await autoDispatchRide({
                    bookingId,
                    tenantDb,
                    currentPlotId: backupPlot,
                    driverIndex: 0,
                    visitedPlots
                });
                return;
            }

            io.emit("auto-dispatch-failed", {
                booking_id: bookingId,
                message: "No drivers available in any plot."
            });

            return;
        }

        if (driverIndex >= drivers.length) {
            console.log("➡ All drivers in this plot tried. Moving to backup.");

            const [plotRows] = await db.query(
                "SELECT backup_plots FROM plots WHERE id = ?",
                [currentPlotId]
            );

            const backupPlots = JSON.parse(plotRows[0]?.backup_plots || "[]");

            for (let backupPlot of backupPlots) {
                await autoDispatchRide({
                    bookingId,
                    tenantDb,
                    currentPlotId: backupPlot,
                    driverIndex: 0,
                    visitedPlots
                });
                return;
            }

            io.emit("auto-dispatch-failed", {
                booking_id: bookingId,
                message: "All drivers rejected the ride."
            });

            return;
        }

        const driver = drivers[driverIndex];

        console.log("Sending ride to:", driver.name);

        await db.query(
            `UPDATE bookings SET driver = ? WHERE id = ?`,
            [driver.id, bookingId]
        );

        const driverSocketId = driverSockets.get(driver.id.toString());

        if (driverSocketId) {
            io.to(driverSocketId).emit("new-ride-request", {
                booking_id: booking.id,
                message: "You have a new ride request",
                booking
            });
        }

        setTimeout(async () => {
            const [updatedRows] = await db.query(
                "SELECT booking_status FROM bookings WHERE id = ?",
                [bookingId]
            );

            if (!updatedRows.length) return;

            const response = updatedRows[0].driver_response;

            if (response === "accepted") {
                console.log("Accepted by:", driver.name);
                io.emit("job-accepted-by-driver", {
                    booking_id: bookingId,
                    driver_id: driver.id,
                    message: `${driver.name} accepted the ride`
                });
                return;
            }

            console.log("Timeout or rejected. Trying next driver.");

            await db.query(
                `UPDATE bookings SET driver = NULL WHERE id = ?`,
                [bookingId]
            );

            autoDispatchRide({
                bookingId,
                tenantDb,
                currentPlotId,
                driverIndex: driverIndex + 1,
                visitedPlots
            });

        }, 30000);

    } catch (error) {
        console.error("Auto Dispatch Error:", error.message);
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
    const userId = socket.handshake.query.user_id;
    const clientId = socket.handshake.query.client_id;
    const adminId = socket.handshake.query.admin_id;

    if (role === "dispatcher" && dispatcherId) {
        dispatcherSockets.set(dispatcherId.toString(), socket.id);
        console.log(`✅ Dispatcher ${dispatcherId} connected`);
    }
    if (role === "user" && userId) {
        userSockets.set(userId.toString(), socket.id);
    }
    if (role === "client" && clientId) {
        clientSockets.set(clientId.toString(), socket.id);
    }
    if (role === "admin" && adminId) {
        adminSockets.set(adminId.toString(), socket.id);
        console.log(`✅ Admin ${adminId} connected`);
    }
    if (driverId) {
        driverSockets.set(driverId.toString(), socket.id);
    }

    socket.on("driver-location", async (data) => {
        try {
            var dataArray;
            console.log("dataArray")
            console.log(dataArray)
            if (typeof data === "string") {
                dataArray = JSON.parse(data);
            } else {
                dataArray = data;
            }
            const response = await axios.post(
                "https://backend.cabifyit.com/api/driver/location",
                dataArray,
                {
                    headers: {
                        Authorization: `Bearer ${socket.token}`,
                        database: `${dataArray.database}`,
                    }
                }
            );
            socket.broadcast.emit("driver-location-update", response.data.driver);
        } catch (err) {
            console.error("Laravel Socket error", err);
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
                {
                    headers: {
                        database: `${dataArray.database}`,
                    }
                }
            );

            const location = response.data;

            socket.emit("get-driver-location-on-user", {
                success: true,
                data: location,
            });
        } catch (err) {
            console.error("Laravel Socket error", err);
        }
    });

    socket.on("disconnect", () => {
        if (driverId) {
            driverSockets.delete(driverId.toString());
        }
        if (role === "dispatcher" && dispatcherId) {
            dispatcherSockets.delete(dispatcherId.toString());
            console.log(`❌ Dispatcher ${dispatcherId} disconnected`);
        }
        if (role === "user" && userId) {
            userSockets.delete(userId.toString());
        }
        if (role === "client" && clientId) {
            clientSockets.delete(clientId.toString());
        }
        if (role === "admin" && adminId) {
            adminSockets.delete(adminId.toString());
            console.log(`❌ Admin ${adminId} disconnected`);
        }
    });
});

app.use((req, res, next) => {
    const databaseHeader = req.headers['database'];
    if (databaseHeader) {
        req.tenantDb = `tenant${databaseHeader}`;
        console.log(`📂 Using database: ${req.tenantDb}`);
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
        : (settings.updated_at ? new Date(settings.updated_at) : new Date(driver.created_at));

    let lastSettlementDate;
    if (driver.last_settlement_date) {
        const settlDate = new Date(driver.last_settlement_date);
        lastSettlementDate = settlDate >= packageChangedDate ? settlDate : packageChangedDate;
    } else {
        lastSettlementDate = packageChangedDate;
    }

    const currentDate = new Date();

    const calculationStartDate = new Date(lastSettlementDate);
    calculationStartDate.setDate(calculationStartDate.getDate() + 1);
    calculationStartDate.setHours(0, 0, 0, 0);

    const daysPassed = Math.floor((currentDate - calculationStartDate) / (1000 * 60 * 60 * 24));
    const completedCycles = Math.floor(daysPassed / packageDays);

    const entries = [];

    for (let i = 0; i < completedCycles; i++) {
        const cycleStartDate = new Date(calculationStartDate);
        cycleStartDate.setDate(cycleStartDate.getDate() + (i * packageDays));
        const cycleEndDate = new Date(cycleStartDate);
        cycleEndDate.setDate(cycleEndDate.getDate() + packageDays - 1);

        entries.push({
            entry_number: i + 1,
            cycle_start_date: formatDate(cycleStartDate),
            cycle_end_date: formatDate(cycleEndDate),
            days_in_cycle: packageDays,
            amount: packageAmount.toFixed(2),
            status: 'pending',
            description: `${packageDays} days package - ${packageAmount} Rs`
        });
    }

    const currentCycleStart = new Date(calculationStartDate);
    currentCycleStart.setDate(currentCycleStart.getDate() + (completedCycles * packageDays));
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
        status: 'pending',
        description: `Current cycle - ${daysElapsedInCycle} of ${packageDays} days elapsed`
    });

    return entries;
}

async function calculatePercentageEntries(driver, settings, db) {
    const packageDays = parseInt(settings.package_days);
    const packagePercentage = parseFloat(settings.package_percentage);

    const packageStartDate = settings.package_updated_at
        ? new Date(settings.package_updated_at)
        : (settings.updated_at ? new Date(settings.updated_at) : new Date(driver.created_at));

    let lastSettlementDate;
    if (driver.last_settlement_date) {
        const settlDate = new Date(driver.last_settlement_date);
        lastSettlementDate = settlDate >= packageStartDate ? settlDate : packageStartDate;
    } else {
        lastSettlementDate = packageStartDate;
    }

    const currentDate = new Date();

    const calculationStartDate = new Date(lastSettlementDate);
    calculationStartDate.setDate(calculationStartDate.getDate() + 1);
    calculationStartDate.setHours(0, 0, 0, 0);

    const daysPassed = Math.floor((currentDate - calculationStartDate) / (1000 * 60 * 60 * 24));
    const completedCycles = Math.floor(daysPassed / packageDays);

    const entries = [];

    for (let i = 0; i < completedCycles; i++) {
        const cycleStartDate = new Date(calculationStartDate);
        cycleStartDate.setDate(cycleStartDate.getDate() + (i * packageDays));

        const cycleEndDate = new Date(cycleStartDate);
        cycleEndDate.setDate(cycleEndDate.getDate() + packageDays - 1);
        cycleEndDate.setHours(23, 59, 59, 999);

        const [bookingRows] = await db.query(`
            SELECT 
                COUNT(*) as total_rides,
                COALESCE(SUM(
                    CASE 
                        WHEN booking_amount IS NOT NULL AND booking_amount > 0 
                            THEN booking_amount
                        WHEN offered_amount IS NOT NULL AND offered_amount > 0 
                            THEN offered_amount
                        WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 
                            THEN recommended_amount
                        ELSE 0
                    END
                ), 0) as total_rides_amount
            FROM bookings
            WHERE driver = ?
            AND booking_status = 'completed'
            AND DATE(booking_date) >= ?
            AND DATE(booking_date) <= ?
        `, [
            driver.id,
            formatDate(cycleStartDate),
            formatDate(cycleEndDate)
        ]);

        const totalRidesAmount = parseFloat(bookingRows[0]?.total_rides_amount || 0);
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
            status: 'pending',
            description: `${packagePercentage}% of ${totalRidesAmount.toFixed(2)} Rs rides (${totalRides} rides)`
        });
    }

    const currentCycleStart = new Date(calculationStartDate);
    currentCycleStart.setDate(currentCycleStart.getDate() + (completedCycles * packageDays));

    const currentCycleEnd = new Date(currentCycleStart);
    currentCycleEnd.setDate(currentCycleEnd.getDate() + packageDays - 1);

    const daysElapsedInCycle = daysPassed % packageDays;
    const daysRemainingInCycle = packageDays - daysElapsedInCycle;

    const [currentBookingRows] = await db.query(`
        SELECT 
            COUNT(*) as total_rides,
            COALESCE(SUM(
                CASE 
                    WHEN booking_amount IS NOT NULL AND booking_amount > 0 
                        THEN booking_amount
                    WHEN offered_amount IS NOT NULL AND offered_amount > 0 
                        THEN offered_amount
                    WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 
                        THEN recommended_amount
                    ELSE 0
                END
            ), 0) as total_rides_amount
        FROM bookings
        WHERE driver = ?
        AND booking_status = 'completed'
        AND DATE(booking_date) >= ?
        AND DATE(booking_date) <= ?
    `, [
        driver.id,
        formatDate(currentCycleStart),
        formatDate(currentCycleEnd)
    ]);

    const currentRidesAmount = parseFloat(currentBookingRows[0]?.total_rides_amount || 0);
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
        status: 'pending',
        description: `Current cycle - ${packagePercentage}% of ${currentRidesAmount.toFixed(2)} Rs rides (${currentTotalRides} rides)`
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

        console.log("Commission Entries Request:", { driver_id, page, limit });

        if (!driver_id) {
            return res.status(400).json({ success: 0, message: 'Driver ID is required' });
        }

        const databaseHeader = req.headers['x-database'] || req.headers['database'] || req.query.database;
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: 'Database header is required' });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [settingsRows] = await db.query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
        if (!settingsRows.length) {
            return res.status(404).json({ success: 0, message: 'Company settings not found' });
        }
        const settings = settingsRows[0];

        const [driverRows] = await db.query("SELECT * FROM drivers WHERE id = ?", [driver_id]);
        if (!driverRows.length) {
            return res.status(404).json({ success: 0, message: 'Driver not found' });
        }
        const driver = driverRows[0];

        let allEntries = [];
        if (settings.package_type === 'packages_post_paid') {
            allEntries = await calculatePostPaidEntries(driver, settings, db);
        } else if (settings.package_type === 'commission_without_topup') {
            allEntries = await calculatePercentageEntries(driver, settings, db);
        } else {
            return res.status(400).json({ success: 0, message: 'Invalid package type' });
        }

        let uncollectedEntries = allEntries;
        if (driver.last_settlement_date) {
            const lastSettlementDate = new Date(driver.last_settlement_date);
            lastSettlementDate.setHours(0, 0, 0, 0);

            uncollectedEntries = allEntries.filter(entry => {
                const cycleEndDate = new Date(entry.cycle_end_date);
                cycleEndDate.setHours(0, 0, 0, 0);
                return cycleEndDate > lastSettlementDate;
            });
        }

        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const totalEntries = uncollectedEntries.length;
        const totalPages = Math.ceil(totalEntries / limitNum);
        const offset = (pageNum - 1) * limitNum;
        const paginatedEntries = uncollectedEntries.slice(offset, offset + limitNum);

        const pendingEntries = uncollectedEntries.filter(e => e.status === 'pending');

        console.log("Commission Entries Success:", { total: totalEntries, page: pageNum });

        return res.json({
            success: 1,
            data: {
                driver_id: driver.id,
                driver_name: driver.name,
                driver_wallet_balance: parseFloat(driver.wallet_balance || 0).toFixed(2),
                package_type: settings.package_type,
                package_days: settings.package_days,
                package_amount: settings.package_amount,
                package_percentage: settings.package_percentage,
                last_settlement_date: driver.last_settlement_date,
                total_uncollected_entries: pendingEntries.length,
                total_uncollected_amount: pendingEntries.reduce((sum, e) => sum + parseFloat(e.amount), 0).toFixed(2),
                commission_entries: paginatedEntries,
                pagination: {
                    total: totalEntries,
                    page: pageNum,
                    limit: limitNum,
                    total_pages: totalPages,
                    hasNext: pageNum < totalPages,
                    hasPrev: pageNum > 1
                }
            }
        });

    } catch (error) {
        console.error('Error in commission-entries:', error);
        return res.status(500).json({ success: 0, message: error.message });
    }
});

app.post("/driver/collect-commission", async (req, res) => {
    try {
        const { driver_id } = req.body;

        console.log("Collect Commission Request:", { driver_id });

        if (!driver_id) {
            return res.status(400).json({ success: 0, message: 'Driver ID is required' });
        }

        const databaseHeader = req.headers['x-database'] || req.headers['database'] || req.query.database;
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: 'Database header is required' });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const [settingsRows] = await db.query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
        if (!settingsRows.length) {
            return res.status(404).json({ success: 0, message: 'Company settings not found' });
        }
        const settings = settingsRows[0];

        const [driverRows] = await db.query("SELECT * FROM drivers WHERE id = ?", [driver_id]);
        if (!driverRows.length) {
            return res.status(404).json({ success: 0, message: 'Driver not found' });
        }
        const driver = driverRows[0];

        let commissionEntries = [];
        if (settings.package_type === 'packages_post_paid') {
            commissionEntries = await calculatePostPaidEntries(driver, settings, db);
        } else if (settings.package_type === 'commission_without_topup') {
            commissionEntries = await calculatePercentageEntries(driver, settings, db);
        } else {
            return res.status(400).json({ success: 0, message: 'Invalid package type' });
        }

        if (commissionEntries.length === 0) {
            return res.json({ success: 0, message: 'No commission entries available to collect' });
        }

        const firstEntry = commissionEntries[0];

        const collectionAmount = parseFloat(firstEntry.amount);
        const newSettlementDate = new Date(firstEntry.cycle_end_date + ' 23:59:59');
        const currentDateTime = new Date();

        await db.query(`
            UPDATE drivers 
            SET last_settlement_date = ?
            WHERE id = ?
        `, [formatDateTime(newSettlementDate), driver_id]);

        const transactionComment = settings.package_type === 'packages_post_paid'
            ? `Commission collected - ${firstEntry.description}`
            : `Commission collected - ${firstEntry.description}`;

        await db.query(`
            INSERT INTO wallet_transactions 
            (user_type, user_id, type, comment, created_at, updated_at, amount)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        `, [
            'driver',
            driver_id,
            'add',
            transactionComment,
            formatDateTime(currentDateTime),
            formatDateTime(currentDateTime),
            collectionAmount
        ]);

        const [updatedDriverRows] = await db.query("SELECT * FROM drivers WHERE id = ?", [driver_id]);
        const updatedDriver = updatedDriverRows[0];

        let remainingEntries = [];
        if (settings.package_type === 'packages_post_paid') {
            remainingEntries = await calculatePostPaidEntries(updatedDriver, settings, db);
        } else if (settings.package_type === 'commission_without_topup') {
            remainingEntries = await calculatePercentageEntries(updatedDriver, settings, db);
        }

        console.log("Commission Collected Successfully:", { driver_id, collected: collectionAmount });

        return res.json({
            success: 1,
            message: 'Commission collected successfully',
            data: {
                driver_id: driver.id,
                driver_name: driver.name,
                collected_entry: {
                    entry_number: firstEntry.entry_number,
                    cycle_start_date: firstEntry.cycle_start_date,
                    cycle_end_date: firstEntry.cycle_end_date,
                    amount: firstEntry.amount,
                    description: firstEntry.description
                },
                collected_amount: collectionAmount.toFixed(2),
                previous_settlement_date: driver.last_settlement_date ? formatDate(new Date(driver.last_settlement_date)) : 'Not Set',
                new_settlement_date: formatDate(newSettlementDate),
                remaining_entries: remainingEntries.filter(e => e.status === 'pending').length,
                remaining_amount: remainingEntries.filter(e => e.status === 'pending').reduce((sum, e) => sum + parseFloat(e.amount), 0).toFixed(2),
                next_entries: remainingEntries,
                transaction_recorded: true
            }
        });

    } catch (error) {
        console.error('Error in collect-commission:', error);
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

        if (status) {
            baseQuery += ` AND b.booking_status = ?`;
            params.push(status);
        }
        if (date) {
            baseQuery += ` AND DATE(b.booking_date) = ?`;
            params.push(date);
        }
        if (user_id) {
            baseQuery += ` AND b.user_id = ?`;
            params.push(user_id);
        }
        if (driver_id) {
            baseQuery += ` AND b.driver = ?`;
            params.push(driver_id);
        }
        if (sub_company) {
            baseQuery += ` AND b.sub_company = ?`;
            params.push(sub_company);
        }
        if (search) {
            baseQuery += ` AND (
                b.booking_id LIKE ? OR 
                b.name LIKE ? OR 
                b.phone_no LIKE ? OR 
                b.email LIKE ? OR
                d.name LIKE ? OR
                vt.vehicle_type_name LIKE ?
            )`;
            const searchParam = `%${search}%`;
            params.push(searchParam, searchParam, searchParam, searchParam, searchParam, searchParam);
        }

        const dataQuery = `
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
        });

        const countQuery = `SELECT COUNT(*) AS total ${baseQuery}`;
        const [[{ total }]] = await db.query(countQuery, params);

        return res.json({
            success: true,
            data: formattedBookings,
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
        console.error("Error fetching bookings:", error);
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

app.get("/bookings/:id", async (req, res) => {
    try {
        const { id } = req.params;
        const db = getConnection(req.tenantDb);

        const query = `SELECT * FROM bookings WHERE id = ?`;
        const [bookings] = await db.query(query, [id]);

        if (bookings.length === 0) {
            return res.status(404).json({
                success: false,
                message: "Booking not found"
            });
        }

        return res.json({
            success: true,
            data: bookings[0]
        });

    } catch (error) {
        console.error("Error fetching booking:", error);
        return res.status(500).json({
            success: false,
            error: error.message
        });
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
            "SELECT id, booking_status, booking_id FROM bookings WHERE id = ?",
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

        await db.query(
            `UPDATE bookings SET driver = ? WHERE id = ?`,
            [driver_id, id]
        );

        const isPreJob = assignment_type === "pre_job";
        const notifTitle = isPreJob ? "Pre-Job Assigned" : "New Ride Assigned";
        const notifMessage = isPreJob
            ? `You have a pre-job assigned for ride #${bookingRows[0].booking_id}. Please accept or reject.`
            : `You have been assigned a new ride #${bookingRows[0].booking_id}. Please accept or reject.`;

        const driverSocketId = driverSockets.get(driver_id.toString());
        if (driverSocketId) {
            io.to(driverSocketId).emit("job-assignment-request", {
                booking_id: id,
                assignment_type: isPreJob ? "pre_job" : "allocate_driver",
                message: notifMessage,
            });
        }

        try {
            await sendNotificationToDriver(db, driver_id, notifTitle, notifMessage, { booking_id: String(id) });
            console.log("✅ Notification sent to driver:", driverRows[0].name);
        } catch (fcmError) {
            console.error("⚠️ FCM failed (non-fatal):", fcmError.message);
        }

        try {
            await storeNotification(db, {
                user_type: 'driver',
                user_id: driver_id,
                title: notifTitle,
                message: notifMessage
            });
        } catch (storeError) {
            console.error("⚠️ Store notification failed (non-fatal):", storeError.message);
        }

        return res.json({
            success: true,
            message: isPreJob
                ? "Pre-job sent successfully. Waiting for driver response."
                : "Driver assigned successfully. Waiting for driver response."
        });

    } catch (error) {
        console.error("❌ Assign driver error:", error);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/bookings/:id/start-auto-dispatch", async (req, res) => {
    try {
        const { id } = req.params;

        autoDispatchRide({
            bookingId: id,
            tenantDb: req.tenantDb
        });

        return res.json({
            success: true,
            message: "Auto dispatch started"
        });

    } catch (error) {
        return res.status(500).json({
            success: false,
            message: error.message
        });
    }
});

app.post("/driver/accept-ride", async (req, res) => {
    try {
        const { ride_id } = req.body;
        const db = getConnection(req.tenantDb);

        const [updatedBookings] = await db.query(`
            SELECT 
                b.*,
                d.id as driver_id,
                d.name as driver_name,
                d.email as driver_email,
                d.phone_no as driver_phone,
                d.profile_image as driver_profile_image,
                vt.id as vehicle_type_id,
                vt.vehicle_type_name,
                vt.vehicle_type_service,
                sc.id as sub_company_id,
                sc.name as sub_company_name,
                sc.email as sub_company_email
            FROM bookings b
            LEFT JOIN drivers d ON b.driver = d.id  
            LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
            LEFT JOIN sub_companies sc ON b.sub_company = sc.id
            WHERE b.id = ?
        `, [ride_id]);

        if (!updatedBookings.length) {
            return res.status(404).json({ success: 0, message: "Ride not found" });
        }

        const updatedBooking = updatedBookings[0];

        const {
            driver_id, driver_name, driver_email, driver_phone, driver_profile_image,
            vehicle_type_id, vehicle_type_name, vehicle_type_service,
            sub_company_id, sub_company_name, sub_company_email,
            ...bookingData
        } = updatedBooking;

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
                vehicle_type_name,
                vehicle_type_service
            } : null,
            subCompanyDetail: sub_company_id ? {
                id: sub_company_id,
                name: sub_company_name,
                email: sub_company_email
            } : null
        };

        const eventData = {
            booking_id: ride_id,
            driver_id,
            driver_name,
            driver_profile_image,
            booking: formattedBooking,
            message: `${driver_name} accepted the ride`
        };

        dispatcherSockets.forEach((socketId) => io.to(socketId).emit("job-accepted-by-driver", eventData));
        adminSockets.forEach((socketId) => io.to(socketId).emit("job-accepted-by-driver", eventData));
        clientSockets.forEach((socketId) => io.to(socketId).emit("job-accepted-by-driver", eventData));

        const clientId = req.headers["database"];
        const socketId = clientSockets.get(clientId?.toString());
        if (socketId) {
            io.to(socketId).emit("on-job-driver-event", driver_name);
        }

        await broadcastDashboardCardsUpdate(req.tenantDb);

        return res.json({ success: 1, message: "Ride accepted successfully", data: formattedBooking });

    } catch (error) {
        console.error("Accept Ride Error:", error);
        return res.status(500).json({ success: 0, message: "Something went wrong" });
    }
});

app.post("/driver/cancel-ride", async (req, res) => {
    try {
        const { ride_id, cancel_reason } = req.body;

        const db = getConnection(req.tenantDb);

        const [bookings] = await db.query(`
            SELECT 
                b.*,
                d.id as driver_id,
                d.name as driver_name,
                d.profile_image as driver_profile_image
            FROM bookings b
            LEFT JOIN drivers d ON b.driver = d.id
            WHERE b.id = ?
        `, [ride_id]);

        if (!bookings.length) {
            return res.status(404).json({ success: 0, message: "Ride not found" });
        }

        const { driver_id, driver_name, driver_profile_image } = bookings[0];

        const eventData = {
            booking_id: ride_id,
            driver_id,
            driver_name,
            driver_profile_image,
            cancel_reason: cancel_reason || "",
            booking: {
                id: bookings[0].id,
                booking_id: bookings[0].booking_id,
                pickup_location: bookings[0].pickup_location,
                destination_location: bookings[0].destination_location,
                booking_date: bookings[0].booking_date,
                pickup_time: bookings[0].pickup_time,
                booking_status: "cancelled",
            },
            message: `${driver_name} cancelled the ride`
        };

        dispatcherSockets.forEach((socketId) => io.to(socketId).emit("job-cancelled-by-driver", eventData));
        adminSockets.forEach((socketId) => io.to(socketId).emit("job-cancelled-by-driver", eventData));
        clientSockets.forEach((socketId) => io.to(socketId).emit("job-cancelled-by-driver", eventData));

        await broadcastDashboardCardsUpdate(req.tenantDb);

        return res.json({ success: 1, message: "Cancel event broadcasted" });

    } catch (error) {
        console.error("Cancel Ride Error:", error);
        return res.status(500).json({ success: 0, message: "Something went wrong" });
    }
});

app.post("/bookings/:id/send-confirmation-email", async (req, res) => {
    try {
        const { id } = req.params;
        const db = getConnection(req.tenantDb);

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

app.put("/bookings/:id/status", async (req, res) => {
    try {
        const { id } = req.params;
        const { booking_status, cancel_reason, cancelled_by } = req.body;

        if (!booking_status) return res.status(400).json({ success: false, message: "booking_status is required" });

        const db = getConnection(req.tenantDb);
        const [bookings] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
        if (bookings.length === 0) return res.status(404).json({ success: false, message: "Booking not found" });

        const booking = bookings[0];

        let updateQuery = "UPDATE bookings SET booking_status = ?";
        const params = [booking_status];

        if (booking_status === 'cancelled') {
            if (cancel_reason) { updateQuery += ", cancel_reason = ?"; params.push(cancel_reason); }
            if (cancelled_by) { updateQuery += ", cancelled_by = ?"; params.push(cancelled_by); }
        }
        updateQuery += " WHERE id = ?";
        params.push(id);

        await db.query(updateQuery, params);

        // Update driver status
        if (booking.driver) {
            let driverStatus = null;
            if (['cancelled', 'completed', 'no_show'].includes(booking_status)) driverStatus = 'idle';
            else if (['ongoing', 'started', 'arrived'].includes(booking_status)) driverStatus = 'busy';
            if (driverStatus) {
                await db.query("UPDATE drivers SET driving_status = ? WHERE id = ?", [driverStatus, booking.driver]);
            }
        }

        // Send FCM notification + store in notifications table
        if (booking.driver) {
            const [driverInfo] = await db.query(
                "SELECT id, name, phone_no FROM drivers WHERE id = ?",
                [booking.driver]
            );

            if (booking_status === 'cancelled') {
                const cancelledByText = cancelled_by === 'user' ? 'Rider' : 'Admin';
                const notifTitle = "Ride Cancelled";
                const notifMessage = `Ride #${booking.booking_id} has been cancelled by ${cancelledByText}`;

                console.log("Booking ID:", booking.booking_id);
                console.log("Driver Name:", driverInfo[0]?.name);
                console.log("Cancelled By:", cancelledByText);

                // Send FCM push
                await sendNotificationToDriver(db, booking.driver, notifTitle, notifMessage, { booking_id: String(id) });
                console.log("✅ Cancel notification sent to driver:", driverInfo[0]?.name);

                // ✅ Store notification in notifications table
                await storeNotification(db, {
                    user_type: 'driver',
                    user_id: booking.driver,
                    title: notifTitle,
                    message: notifMessage
                });

            } else if (booking_status === 'completed') {
                const notifTitle = "Ride Completed";
                const notifMessage = `Ride #${booking.booking_id} has been marked as completed`;

                console.log("Booking ID:", booking.booking_id);
                console.log("Driver Name:", driverInfo[0]?.name);
                console.log("Status:", "Completed");

                await sendNotificationToDriver(db, booking.driver, notifTitle, notifMessage, { booking_id: String(id) });
                console.log("✅ Complete notification sent to driver:", driverInfo[0]?.name);

                await storeNotification(db, {
                    user_type: 'driver',
                    user_id: booking.driver,
                    title: notifTitle,
                    message: notifMessage
                });
            }
        }

        await broadcastDashboardCardsUpdate(req.tenantDb);
        
        return res.json({ success: true, message: "Booking status updated successfully" });

    } catch (error) {
        console.error("❌ Error updating booking status:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/bookings/:id/follow-driver", async (req, res) => {
    try {
        const { id } = req.params;
        const db = getConnection(req.tenantDb);

        const [bookings] = await db.query(`
            SELECT 
                b.*,
                d.id as driver_id,
                d.name as driver_name,
                d.email as driver_email,
                d.phone_no as driver_phone,
                d.profile_image as driver_profile_image,
                d.latitude as driver_latitude,
                d.longitude as driver_longitude,
                d.driving_status as driver_status
            FROM bookings b
            LEFT JOIN drivers d ON b.driver = d.id
            WHERE b.id = ?
        `, [id]);

        if (bookings.length === 0) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = bookings[0];

        if (!booking.driver) {
            return res.status(400).json({ success: false, message: "No driver assigned to this booking" });
        }

        const driverInfo = {
            id: booking.driver_id,
            name: booking.driver_name,
            email: booking.driver_email,
            phone_no: booking.driver_phone,
            profile_image: booking.driver_profile_image,
            latitude: booking.driver_latitude,
            longitude: booking.driver_longitude,
            status: booking.driver_status
        };

        const driverSocketId = driverSockets.get(booking.driver.toString());
        if (driverSocketId) {
            io.to(driverSocketId).emit("start-location-tracking", {
                booking_id: booking.id,
                message: "Location tracking started for this booking"
            });
        }

        if (booking.dispatcher_id) {
            const dispatcherSocketId = dispatcherSockets.get(booking.dispatcher_id.toString());
            if (dispatcherSocketId) {
                io.to(dispatcherSocketId).emit("driver-location-tracking-started", {
                    booking_id: booking.id,
                    driver: driverInfo
                });
            }
        }

        adminSockets.forEach((socketId) => {
            io.to(socketId).emit("driver-location-tracking-started", {
                booking_id: booking.id,
                driver: driverInfo
            });
        });

        return res.json({
            success: true,
            message: "Driver location tracking started",
            data: {
                booking_id: booking.id,
                driver: driverInfo
            }
        });

    } catch (error) {
        console.error("Error starting driver tracking:", error);
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

app.post("/send-new-ride", (req, res) => {
    const { drivers, booking } = req.body;
    let sentCount = 0;
    drivers.forEach(driverId => {
        const socketId = driverSockets.get(driverId.toString());
        if (socketId) {
            io.to(socketId).emit("new-ride", booking);
            sentCount++;
        }
    });
    return res.json({ success: true, sent_to: sentCount });
});

app.post("/send-notification-dispatcher", (req, res) => {
    const { drivers, booking } = req.body;
    let sentCount = 0;
    drivers.forEach(driverId => {
        const socketId = driverSockets.get(driverId.toString());
        if (socketId) {
            io.to(socketId).emit("notification-ride", booking);
            sentCount++;
        }
    });
    return res.json({ success: true, sent_to: sentCount });
});

app.post("/change-cancel-ride", (req, res) => {
    const { drivers, status, booking } = req.body;
    let sentCount = 0;
    drivers.forEach(driverId => {
        const socketId = driverSockets.get(driverId.toString());
        if (socketId) {
            io.to(socketId).emit("driver-ride-status-event", { status, booking });
            sentCount++;
        }
    });
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

app.post("/bid-accept", (req, res) => {
    const { driverId, booking } = req.body;
    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("bid-accept-event", booking);
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

app.post("/change-ride-status", (req, res) => {
    const { userId, status, booking } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("user-ride-status-event", { status, booking });
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

app.post("/change-driver-ride-status", (req, res) => {
    const { driverId, status, booking } = req.body;
    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("driver-ride-status-event", { status, booking });
    }
    return res.json({ success: true });
});

app.post("/on-job-driver", (req, res) => {
    const { clientId, driverName } = req.body;
    const socketId = clientSockets.get(clientId.toString());
    if (socketId) {
        io.to(socketId).emit("on-job-driver-event", driverName);
    }
    return res.json({ success: true });
});

app.post("/waiting-driver", (req, res) => {
    const { clientId, driverName, plot } = req.body;
    const socketId = clientSockets.get(clientId.toString());
    if (socketId) {
        io.to(socketId).emit("waiting-driver-event", { driverName, plot });
    }
    return res.json({ success: true });
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
            console.log("❌ Invalid VOIP token");
            return res.status(403).json({ success: false });
        }

        if (!Array.isArray(events)) {
            return res.status(400).json({ success: false, message: "Invalid events format" });
        }

        for (const event of events) {
            console.log("📞 VOIP EVENT RECEIVED:", event);

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
        console.error("❌ Webhook Error:", error);
        return res.status(500).json({ success: false });
    }
});

server.listen(3001, "0.0.0.0", () => {
    console.log("🚀 Socket server running on port 3001");
});