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

// app.put("/bookings/:id/driver-response", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const { driver_id, response } = req.body;

//         if (!driver_id || !response) {
//             return res.status(400).json({
//                 success: false,
//                 message: "Driver ID and response are required"
//             });
//         }

//         if (!["accepted", "rejected"].includes(response)) {
//             return res.status(400).json({
//                 success: false,
//                 message: "Response must be accepted or rejected"
//             });
//         }

//         const db = getConnection(req.tenantDb);

//         const [bookingRows] = await db.query(
//             "SELECT id FROM bookings WHERE id = ? AND driver = ?",
//             [id, driver_id]
//         );

//         if (bookingRows.length === 0) {
//             return res.status(404).json({
//                 success: false,
//                 message: "Booking not found or driver mismatch"
//             });
//         }

//         if (response === "accepted") {

//             await db.query(
//                 `UPDATE bookings 
//                  SET booking_status = 'started',
//                      driver_response = 'accepted'
//                  WHERE id = ?`,
//                 [id]
//             );

//             await db.query(
//                 "UPDATE drivers SET driving_status = 'busy' WHERE id = ?",
//                 [driver_id]
//             );

//             io.emit("job-accepted-by-driver", {
//                 booking_id: id,
//                 driver_id
//             });

//             return res.json({
//                 success: true,
//                 message: "Job accepted successfully"
//             });

//         } else {

//             await db.query(
//                 `UPDATE bookings 
//                  SET driver = NULL,
//                      booking_status = 'pending',
//                      driver_response = 'rejected'
//                  WHERE id = ?`,
//                 [id]
//             );

//             await db.query(
//                 "UPDATE drivers SET driving_status = 'idle' WHERE id = ?",
//                 [driver_id]
//             );

//             io.emit("job-rejected-by-driver", {
//                 booking_id: id,
//                 driver_id
//             });

//             return res.json({
//                 success: true,
//                 message: "Job rejected successfully"
//             });
//         }

//     } catch (error) {
//         console.error("Driver response error:", error);
//         return res.status(500).json({
//             success: false,
//             message: "Something went wrong"
//         });
//     }
// });

// app.put("/bookings/:id/assign-driver", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const { driver_id } = req.body;

//         if (!driver_id) {
//             return res.status(400).json({
//                 success: false,
//                 message: "driver_id is required"
//             });
//         }

//         const db = getConnection(req.tenantDb);

//         const [bookings] = await db.query(
//             "SELECT * FROM bookings WHERE id = ?",
//             [id]
//         );

//         if (bookings.length === 0) {
//             return res.status(404).json({
//                 success: false,
//                 message: "Booking not found"
//             });
//         }

//         const booking = bookings[0];
//         const oldDriverId = booking.driver;

//         const [drivers] = await db.query(
//             "SELECT * FROM drivers WHERE id = ?",
//             [driver_id]
//         );

//         if (drivers.length === 0) {
//             return res.status(404).json({
//                 success: false,
//                 message: "Driver not found"
//             });
//         }

//         await db.query(
//             "UPDATE bookings SET driver = ?, booking_status = 'ongoing' WHERE id = ?",
//             [driver_id, id]
//         );

//         if (oldDriverId && oldDriverId !== null) {
//             await db.query(
//                 "UPDATE drivers SET driving_status = 'idle' WHERE id = ?",
//                 [oldDriverId]
//             );

//             const oldDriverSocketId = driverSockets.get(oldDriverId.toString());
//             if (oldDriverSocketId) {
//                 io.to(oldDriverSocketId).emit("driver-unassigned-event", {
//                     booking_id: booking.id,
//                     message: "You have been unassigned from this booking"
//                 });
//             }
//         }

//         await db.query(
//             "UPDATE drivers SET driving_status = 'busy' WHERE id = ?",
//             [driver_id]
//         );

//         const [updatedBookings] = await db.query(`
//             SELECT 
//                 b.*,
//                 d.id as driver_id,
//                 d.name as driver_name,
//                 d.email as driver_email,
//                 d.phone_no as driver_phone,
//                 d.profile_image as driver_profile_image,
//                 vt.id as vehicle_type_id,
//                 vt.vehicle_type_name as vehicle_type_name,
//                 vt.vehicle_type_service as vehicle_type_service,
//                 sc.id as sub_company_id,
//                 sc.name as sub_company_name,
//                 sc.email as sub_company_email
//             FROM bookings b
//             LEFT JOIN drivers d ON b.driver = d.id
//             LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
//             LEFT JOIN sub_companies sc ON b.sub_company = sc.id
//             WHERE b.id = ?
//         `, [id]);

//         const updatedBooking = updatedBookings[0];
//         const {
//             driver_id: dId, driver_name, driver_email, driver_phone, driver_profile_image,
//             vehicle_type_id, vehicle_type_name, vehicle_type_service,
//             sub_company_id, sub_company_name, sub_company_email,
//             ...bookingData
//         } = updatedBooking;

//         const formattedBooking = {
//             ...bookingData,
//             driverDetail: dId ? {
//                 id: dId,
//                 name: driver_name,
//                 email: driver_email,
//                 phone_no: driver_phone,
//                 profile_image: driver_profile_image
//             } : null,
//             vehicleDetail: vehicle_type_id ? {
//                 id: vehicle_type_id,
//                 vehicle_type_name: vehicle_type_name,
//                 vehicle_type_service: vehicle_type_service
//             } : null,
//             subCompanyDetail: sub_company_id ? {
//                 id: sub_company_id,
//                 name: sub_company_name,
//                 email: sub_company_email
//             } : null
//         };

//         const newDriverSocketId = driverSockets.get(driver_id.toString());
//         if (newDriverSocketId) {
//             io.to(newDriverSocketId).emit("new-ride", formattedBooking);
//         }

//         if (booking.user_id) {
//             const userSocketId = userSockets.get(booking.user_id.toString());
//             if (userSocketId) {
//                 const message = oldDriverId
//                     ? "Your driver has been changed"
//                     : "Driver has been assigned to your booking";

//                 io.to(userSocketId).emit("driver-changed-event", {
//                     booking: formattedBooking,
//                     message: message
//                 });
//             }
//         }

//         // Broadcast dashboard cards update after driver assignment
//         await broadcastDashboardCardsUpdate(req.tenantDb);

//         const responseMessage = oldDriverId
//             ? "Driver changed successfully"
//             : "Driver assigned successfully";

//         return res.json({
//             success: true,
//             message: responseMessage,
//             data: formattedBooking
//         });

//     } catch (error) {
//         console.error("Error assigning driver:", error);
//         return res.status(500).json({
//             success: false,
//             error: error.message
//         });
//     }
// });

// app.post("/send-reminder", async (req, res) => {
//     try {
//         const { clientId, title, description } = req.body;

//         if (!clientId || !title || !description) {
//             return res.status(400).json({
//                 success: false,
//                 message: "clientId, title, and description are required"
//             });
//         }

//         const DB_PREFIX = "tenant";
//         const finalDb = `${DB_PREFIX}${clientId}`;

//         const db = getConnection(finalDb);

//         const [result] = await db.query(
//             `INSERT INTO notifications (user_type, user_id, title, message, status, created_at, updated_at) 
//              VALUES (?, ?, ?, ?, ?, NOW(), NOW())`,
//             ['company', null, title, description, 'unread']
//         );

//         console.log(`Notification stored in database with ID: ${result.insertId}`);

//         const socketId = clientSockets.get(clientId.toString());
//         if (socketId) {
//             io.to(socketId).emit("send-reminder", {
//                 id: result.insertId,
//                 title,
//                 description,
//                 created_at: new Date()
//             });
//             console.log(`Socket notification sent to client: ${clientId}`);
//         } else {
//             console.log(`Client ${clientId} is not connected via socket`);
//         }

//         return res.json({
//             success: true,
//             message: "Reminder sent and stored successfully",
//             data: {
//                 id: result.insertId,
//                 title,
//                 description
//             }
//         });

//     } catch (error) {
//         console.error("Error in send-reminder:", error);
//         return res.status(500).json({
//             success: false,
//             error: error.message
//         });
//     }
// });

// app.get("/notifications", async (req, res) => {
//     try {
//         const tenantDb = req.tenantDb;
//         const { status, page = 1, limit = 10 } = req.query;

//         if (!tenantDb) {
//             return res.status(400).json({
//                 success: false,
//                 message: "Database header is required"
//             });
//         }

//         const pageNum = Math.max(parseInt(page) || 1, 1);
//         const limitNum = Math.max(parseInt(limit) || 10, 1);
//         const offset = (pageNum - 1) * limitNum;

//         const db = getConnection(tenantDb);

//         let query = "SELECT * FROM notifications WHERE 1=1";
//         const params = [];

//         if (status) {
//             query += " AND status = ?";
//             params.push(status);
//         }

//         query += " ORDER BY created_at DESC LIMIT ? OFFSET ?";
//         params.push(limitNum, offset);

//         const [notifications] = await db.query(query, params);

//         let countQuery = "SELECT COUNT(*) as total FROM notifications WHERE 1=1";
//         const countParams = [];

//         if (status) {
//             countQuery += " AND status = ?";
//             countParams.push(status);
//         }

//         const [[{ total }]] = await db.query(countQuery, countParams);

//         return res.json({
//             success: true,
//             data: notifications,
//             pagination: {
//                 total,
//                 page: pageNum,
//                 limit: limitNum,
//                 total_pages: Math.ceil(total / limitNum),
//                 hasNext: pageNum * limitNum < total,
//                 hasPrev: pageNum > 1
//             }
//         });

//     } catch (error) {
//         console.error("Error fetching notifications:", error);
//         return res.status(500).json({
//             success: false,
//             error: error.message
//         });
//     }
// });

// app.delete("/notifications/:id", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const tenantDb = req.tenantDb;

//         if (!tenantDb) {
//             return res.status(400).json({
//                 success: false,
//                 message: "Database header is required"
//             });
//         }

//         const db = getConnection(tenantDb);

//         const [notifications] = await db.query(
//             "SELECT * FROM notifications WHERE id = ?",
//             [id]
//         );

//         if (notifications.length === 0) {
//             return res.status(404).json({
//                 success: false,
//                 message: "Notification not found"
//             });
//         }

//         await db.query("DELETE FROM notifications WHERE id = ?", [id]);

//         console.log(`Notification ${id} deleted successfully`);

//         return res.json({
//             success: true,
//             message: "Notification deleted successfully"
//         });

//     } catch (error) {
//         console.error("Error deleting notification:", error);
//         return res.status(500).json({
//             success: false,
//             error: error.message
//         });
//     }
// });

// app.put("/notifications/:id/mark-read", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const tenantDb = req.tenantDb;

//         if (!tenantDb) {
//             return res.status(400).json({
//                 success: false,
//                 message: "Database header is required"
//             });
//         }

//         const db = getConnection(tenantDb);

//         const [notifications] = await db.query(
//             "SELECT * FROM notifications WHERE id = ?",
//             [id]
//         );

//         if (notifications.length === 0) {
//             return res.status(404).json({
//                 success: false,
//                 message: "Notification not found"
//             });
//         }

//         await db.query(
//             "UPDATE notifications SET status = 'read', updated_at = NOW() WHERE id = ?",
//             [id]
//         );

//         console.log(`Notification ${id} marked as read`);

//         return res.json({
//             success: true,
//             message: "Notification marked as read"
//         });

//     } catch (error) {
//         console.error("rror marking notification as read:", error);
//         return res.status(500).json({
//             success: false,
//             error: error.message
//         });
//     }
// });

module.exports = router;
