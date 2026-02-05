const express = require("express");
const { getConnection } = require("../db");

const router = express.Router()

router.post("/bookings/notify", async (req, res) => {
    try {
        const { booking } = req.body;

        console.log(`🔔 New booking notification received: ${booking.booking_id}`);

        // Broadcast to all dispatchers
        let sentCount = 0;
        dispatcherSockets.forEach((socketId) => {
            io.to(socketId).emit("new-booking-event", booking);
            sentCount++;
        });

        // Broadcast to admin sockets
        adminSockets.forEach((socketId) => {
            io.to(socketId).emit("new-booking-event", booking);
            sentCount++;
        });

        // Also emit to bookings-list-update for real-time list updates
        dispatcherSockets.forEach((socketId) => {
            io.to(socketId).emit("bookings-list-update", {
                action: 'new',
                booking: booking
            });
        });

        adminSockets.forEach((socketId) => {
            io.to(socketId).emit("bookings-list-update", {
                action: 'new',
                booking: booking
            });
        });

        console.log(`✅ Booking ${booking.booking_id} broadcasted to ${sentCount} clients`);

        return res.json({
            success: true,
            message: 'Booking broadcasted successfully',
            sent_to: sentCount
        });

    } catch (error) {
        console.error("Error broadcasting booking:", error);
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

router.get("/bookings", async (req, res) => {
    try {
        const { status, date, user_id, driver_id, page = 1, limit = 10 } = req.query;
        const offset = (page - 1) * limit;

        const db = getConnection(req.tenantDb);

        let query = `SELECT * FROM bookings WHERE 1=1`;
        const queryParams = [];

        if (status) {
            query += ` AND booking_status = ?`;
            queryParams.push(status);
        }
        if (date) {
            query += ` AND DATE(booking_date) = ?`;
            queryParams.push(date);
        }
        if (user_id) {
            query += ` AND user_id = ?`;
            queryParams.push(user_id);
        }
        if (driver_id) {
            query += ` AND driver = ?`;
            queryParams.push(driver_id);
        }

        query += ` ORDER BY booking_date DESC, id DESC LIMIT ? OFFSET ?`;
        queryParams.push(parseInt(limit), parseInt(offset));

        const [bookings] = await db.query(query, queryParams);

        let countQuery = `SELECT COUNT(*) as total FROM bookings WHERE 1=1`;
        const countParams = [];

        if (status) {
            countQuery += ` AND booking_status = ?`;
            countParams.push(status);
        }
        if (date) {
            countQuery += ` AND DATE(booking_date) = ?`;
            countParams.push(date);
        }
        if (user_id) {
            countQuery += ` AND user_id = ?`;
            countParams.push(user_id);
        }
        if (driver_id) {
            countQuery += ` AND driver = ?`;
            countParams.push(driver_id);
        }

        const [countResult] = await db.query(countQuery, countParams);
        const total = countResult[0].total;

        dispatcherSockets.forEach((socketId) => {
            io.to(socketId).emit("bookings-list-update", {
                bookings: bookings,
                total: total,
                page: parseInt(page),
                limit: parseInt(limit)
            });
        });

        return res.json({
            success: true,
            data: bookings,
            pagination: {
                total: total,
                page: parseInt(page),
                limit: parseInt(limit),
                total_pages: Math.ceil(total / limit)
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

router.get("/bookings/:id", async (req, res) => {
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

router.post("/bookings/broadcast", async (req, res) => {
    try {
        const { booking_id, tenantDb } = req.body;
        const DB_PREFIX = "tenant";

        const finalDb = `${DB_PREFIX}${tenantDb}`;

        console.log("📂 Using DB:", finalDb);

        const db = getConnection(finalDb);


        const [rows] = await db.query(
            "SELECT * FROM bookings WHERE id = ?",
            [booking_id]
        );

        if (!rows.length) {
            return res.status(404).json({
                success: false,
                message: "Booking not found"
            });
        }

        const booking = rows[0];
        let sentCount = 0;

        console.log("📢 Broadcasting booking:", booking.id);
        console.log("Dispatchers:", dispatcherSockets.size);
        console.log("Admins:", adminSockets.size);
        console.log("Clients:", clientSockets.size);

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

        return res.json({
            success: true,
            sent_to: sentCount,
            booking
        });

    } catch (error) {
        console.error("❌ Broadcast error:", error);
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

app.get("/bookings/:id/driver-location", async (req, res) => {
    try {
        const { id } = req.params;
        const db = getConnection(req.tenantDb);

        const [bookings] = await db.query(`
            SELECT 
                b.id as booking_id,
                b.booking_id as booking_reference,
                d.id as driver_id,
                d.name as driver_name,
                d.latitude as driver_latitude,
                d.longitude as driver_longitude,
                d.driving_status as driver_status,
                d.updated_at as last_location_update
            FROM bookings b
            LEFT JOIN drivers d ON b.driver = d.id
            WHERE b.id = ?
        `, [id]);

        if (bookings.length === 0) {
            return res.status(404).json({
                success: false,
                message: "Booking not found"
            });
        }

        const booking = bookings[0];

        if (!booking.driver_id) {
            return res.status(400).json({
                success: false,
                message: "No driver assigned to this booking"
            });
        }

        return res.json({
            success: true,
            data: {
                booking_id: booking.booking_id,
                booking_reference: booking.booking_reference,
                driver: {
                    id: booking.driver_id,
                    name: booking.driver_name,
                    latitude: booking.driver_latitude,
                    longitude: booking.driver_longitude,
                    status: booking.driver_status,
                    last_update: booking.last_location_update
                }
            }
        });

    } catch (error) {
        console.error("Error fetching driver location:", error);
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

module.exports = router;
