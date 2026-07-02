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
            data: bookings[0],
            message: "Booking fetched successfully"
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
app.get("/wallet/transactions", async (req, res) => {
    try {
        const { user_id, user_type, page = 1, limit = 10, type } = req.query;

        console.log("Wallet Transactions Request:", { user_id, user_type, page, limit });

        if (!user_id) {
            return res.status(400).json({ success: 0, message: 'user_id is required' });
        }

        if (!user_type) {
            return res.status(400).json({ success: 0, message: 'user_type is required (driver/user/etc)' });
        }

        const databaseHeader = req.headers['x-database'] || req.headers['database'] || req.query.database;
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: 'Database header is required' });
        }

        const tenantDb = `tenant${databaseHeader}`;
        const db = getConnection(tenantDb);

        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const offset = (pageNum - 1) * limitNum;

        let whereClause = 'WHERE user_id = ? AND user_type = ?';
        const params = [user_id, user_type];

        if (type) {
            whereClause += ' AND type = ?';
            params.push(type);
        }

        const countQuery = `SELECT COUNT(*) as total FROM wallet_transactions ${whereClause}`;
        const [[{ total }]] = await db.query(countQuery, params);

        const dataQuery = `
            SELECT * FROM wallet_transactions 
            ${whereClause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        `;
        const [transactions] = await db.query(dataQuery, [...params, limitNum, offset]);

        const balanceQuery = `
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'add' THEN amount ELSE 0 END), 0) as total_added,
                COALESCE(SUM(CASE WHEN type = 'deduct' THEN amount ELSE 0 END), 0) as total_deducted
            FROM wallet_transactions 
            WHERE user_id = ? AND user_type = ?
        `;
        const [[balance]] = await db.query(balanceQuery, [user_id, user_type]);

        const currentBalance = parseFloat(balance.total_added) - parseFloat(balance.total_deducted);

        console.log("Wallet Transactions Success:", { total, page: pageNum });

        return res.json({
            success: 1,
            data: {
                user_id: parseInt(user_id),
                user_type,
                current_balance: currentBalance.toFixed(2),
                total_added: parseFloat(balance.total_added).toFixed(2),
                total_deducted: parseFloat(balance.total_deducted).toFixed(2),
                transactions,
                pagination: {
                    total,
                    page: pageNum,
                    limit: limitNum,
                    total_pages: Math.ceil(total / limitNum),
                    hasNext: pageNum * limitNum < total,
                    hasPrev: pageNum > 1
                }
            }
        });

    } catch (error) {
        console.error('Error in wallet transactions:', error);
        return res.status(500).json({ success: 0, message: error.message });
    }
})


// app.post("/bookings/:id/set-follow-on-job", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const { follow_on_booking_id } = req.body;

//         if (!follow_on_booking_id) {
//             return res.status(400).json({ success: false, message: "follow_on_booking_id is required" });
//         }

//         if (parseInt(id) === parseInt(follow_on_booking_id)) {
//             return res.status(400).json({ success: false, message: "A booking cannot be a follow-on of itself" });
//         }

//         const db = getConnection(req.tenantDb);

//         const [job1Rows] = await db.query(
//             "SELECT id, booking_id, booking_status, driver, booking_system FROM bookings WHERE id = ?",
//             [id]
//         );
//         if (!job1Rows.length) return res.status(404).json({ success: false, message: "Job 1 not found" });

//         const job1 = job1Rows[0];

//         if (!job1.driver) {
//             return res.status(400).json({ success: false, message: "Job 1 has no driver assigned. Assign a driver first." });
//         }

//         if (!['ongoing', 'arrived', 'started'].includes(job1.booking_status)) {
//             return res.status(400).json({
//                 success: false,
//                 message: `Job 1 must be active (ongoing/arrived/started). Current status: ${job1.booking_status}`
//             });
//         }

//         const [job2Rows] = await db.query(
//             "SELECT id, booking_id, booking_status FROM bookings WHERE id = ?",
//             [follow_on_booking_id]
//         );
//         if (!job2Rows.length) return res.status(404).json({ success: false, message: "Follow-on booking (Job 2) not found" });

//         const job2 = job2Rows[0];

//         if (!['pending', 'pending_acceptance'].includes(job2.booking_status)) {
//             return res.status(400).json({
//                 success: false,
//                 message: `Job 2 must be pending. Current status: ${job2.booking_status}`
//             });
//         }

//         const [alreadyLinked] = await db.query(
//             "SELECT id FROM bookings WHERE booking_system = ?",
//             [String(follow_on_booking_id)]
//         );
//         if (alreadyLinked.length) {
//             return res.status(400).json({
//                 success: false,
//                 message: `Booking #${job2.booking_id} is already queued as a follow-on for another job`
//             });
//         }

//         const [driverRows] = await db.query("SELECT id, name FROM drivers WHERE id = ?", [job1.driver]);
//         const driverName = driverRows[0]?.name || "Driver";

//         const dispatcherName = req.body.dispatcher_name || "Dispatcher";

//         await db.query(
//             "UPDATE bookings SET booking_system = ?, dispatcher_action = ? WHERE id = ?",
//             [
//                 String(follow_on_booking_id),
//                 `${dispatcherName} linked booking #${job2.booking_id} as a follow-on job to this ride`,
//                 id
//             ]
//         );

//         const responseData = {
//             job1_id: job1.id,
//             job1_booking_id: job1.booking_id,
//             job2_id: job2.id,
//             job2_booking_id: job2.booking_id,
//             driver_id: job1.driver,
//             driver_name: driverName,
//             message: `Booking #${job2.booking_id} queued as follow-on after #${job1.booking_id} for ${driverName}`
//         };

//         dispatcherSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));
//         adminSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));
//         clientSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));

//         console.log(`Follow-on linked: Job #${job1.booking_id} → Job #${job2.booking_id} (Driver: ${driverName})`);

//         return res.json({ success: true, message: responseData.message, data: responseData });

//     } catch (error) {
//         console.error("Set follow-on job error:", error);
//         return res.status(500).json({ success: false, message: error.message });
//     }
// });

// app.put("/bookings/:id/status", async (req, res) => {
//     try {
//         const { id } = req.params;
//         let { booking_status, cancel_reason, cancelled_by } = req.body;
//         let cancelled_by_actor = cancelled_by || 'admin';

//         if (!booking_status) return res.status(400).json({ success: false, message: "booking_status is required" });

//         const db = getConnection(req.tenantDb);
//         const [bookings] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
//         if (bookings.length === 0) return res.status(404).json({ success: false, message: "Booking not found" });

//         const booking = bookings[0];
//         let res_user = null;

//         const dispatcherName = req.body.dispatcher_name || "Dispatcher";
//         let updateQuery = "UPDATE bookings SET booking_status = ?";
//         const params = [booking_status];

//         let actionLabel = `updated the status to ${booking_status}`;
//         if (booking_status === 'cancelled') actionLabel = "cancelled this ride";
//         else if (booking_status === 'completed') actionLabel = "marked the ride as completed";

//         updateQuery += ", dispatcher_action = ?";
//         params.push(`${dispatcherName} ${actionLabel}`);

//         if (booking_status === 'cancelled') {
//             if (cancel_reason) { updateQuery += ", cancel_reason = ?"; params.push(cancel_reason); }
//             if (cancelled_by === 'user' || cancelled_by === 'driver') {
//                 updateQuery += ", cancelled_by = ?"; params.push(cancelled_by);
//             }
//         }
//         updateQuery += " WHERE id = ?";
//         params.push(id);

//         await db.query(updateQuery, params);

//         if (booking.driver) {
//             let driverStatus = null;
//             if (['cancelled', 'completed', 'no_show'].includes(booking_status)) driverStatus = 'idle';
//             else if (['ongoing', 'started', 'arrived'].includes(booking_status)) driverStatus = 'busy';
//             if (driverStatus) {
//                 await db.query("UPDATE drivers SET driving_status = ? WHERE id = ?", [driverStatus, booking.driver]);
//             }
//         }

//         if (booking_status === 'cancelled') {
//             const notifTitle = "Ride Cancelled";
//             const notifMessage = cancelled_by_actor === 'user' ? `Ride #${booking.booking_id} has been cancelled by customer` : `Ride #${booking.booking_id} is cancelled by Admin or Dispatcher`;

//             if (booking.user_id) {
//                 const userNotifTitle = "Ride Cancelled";
//                 const userNotifMessage = cancelled_by_actor === 'user' ? `Your ride #${booking.booking_id} has been successfully cancelled.` : `Your ride #${booking.booking_id} has been cancelled by Admin or Dispatcher.`;
//                 try {
//                     res_user = await sendNotificationToUser(db, booking.user_id, userNotifTitle, userNotifMessage, {
//                         booking_id: String(id),
//                         type: "ride_cancelled"
//                     });
//                     await storeNotification(db, {
//                         user_type: 'rider',
//                         user_id: booking.user_id,
//                         title: userNotifTitle,
//                         message: userNotifMessage
//                     });
//                     console.log("Cancel notification sent to user:", booking.user_id);
//                 } catch (userNotifErr) {
//                     console.error("User Notification error in ride cancellation:", userNotifErr.message);
//                 }
//             }

//             if (booking.driver) {
//                 try {
//                     await sendNotificationToDriver(db, booking.driver, notifTitle, notifMessage, {
//                         booking_id: String(id),
//                         type: "ride_cancelled"
//                     });
//                     await storeNotification(db, {
//                         user_type: 'driver',
//                         user_id: booking.driver,
//                         title: notifTitle,
//                         message: notifMessage
//                     });
//                     console.log("Cancel notification sent to driver:", booking.driver);
//                 } catch (notifErr) {
//                     console.error("Notification error in ride cancellation (driver):", notifErr.message);
//                 }
//             }

//         } else if (booking.driver) {
//             const [driverInfoRows] = await db.query(
//                 "SELECT id, name, phone_no FROM drivers WHERE id = ?",
//                 [booking.driver]
//             );
//             const driverInfoForFo = driverInfoRows[0];

//             if (booking_status === 'completed') {
//                 const notifTitle = "Ride Completed";
//                 const notifMessage = `Ride #${booking.booking_id} has been marked as completed`;

//                 try {
//                     await sendNotificationToDriver(db, booking.driver, notifTitle, notifMessage, {
//                         booking_id: String(id),
//                         type: "ride_completed"
//                     });
//                     await storeNotification(db, {
//                         user_type: 'driver',
//                         user_id: booking.driver,
//                         title: notifTitle,
//                         message: notifMessage
//                     });
//                     console.log("Complete notification sent to driver:", driverInfoForFo?.name);
//                 } catch (notifErr) {
//                     console.error("Notification error in ride completion (driver):", notifErr.message);
//                 }

//                 // Push notification to User
//                 if (booking.user_id) {
//                     try {
//                         res_user = await sendNotificationToUser(db, booking.user_id, notifTitle, notifMessage, {
//                             booking_id: String(id),
//                             type: "ride_completed"
//                         });
//                         await storeNotification(db, {
//                             user_type: 'rider',
//                             user_id: booking.user_id,
//                             title: notifTitle,
//                             message: notifMessage
//                         });
//                     } catch (err) {
//                         console.error("Notification error in ride completion (user):", err.message);
//                     }
//                 }
//             } else if (['arrived', 'started'].includes(booking_status)) {
//                 const userNotifTitle = booking_status === 'arrived' ? "Driver Arrived" : "Ride Started";
//                 const userNotifMessage = booking_status === 'arrived' ? `Your driver has arrived at the pickup location.` : `Your ride has started. Have a safe journey!`;

//                 if (booking.user_id) {
//                     try {
//                         res_user = await sendNotificationToUser(db, booking.user_id, userNotifTitle, userNotifMessage, {
//                             booking_id: String(id),
//                             type: `ride_${booking_status}`
//                         });
//                         await storeNotification(db, {
//                             user_type: 'rider',
//                             user_id: booking.user_id,
//                             title: userNotifTitle,
//                             message: userNotifMessage
//                         });
//                     } catch (err) {
//                         console.error(`Notification error in ride ${booking_status} (user):`, err.message);
//                     }
//                 }
//             }
//         }

//         let followOnPayload = null;
//         let followOnEventData = null;
//         if (booking.follow_on_job_id) {
//             const followOnId = booking.follow_on_job_id;
//             console.log(`Follow-on job detected: #${followOnId} — sending to driver #${booking.driver}`);

//             try {
//                 const [followOnRows] = await db.query(
//                     "SELECT * FROM bookings WHERE id = ?",
//                     [followOnId]
//                 );

//                 if (followOnRows.length && ['pending', 'pending_acceptance'].includes(followOnRows[0].booking_status)) {
//                     const followOnBooking = followOnRows[0];
//                     const driverId = booking.driver;
//                     await db.query(
//                         `UPDATE bookings 
//                                  SET driver = ?, booking_status = 'pending_acceptance', driver_response = 'pending'
//                                  WHERE id = ?`,
//                         [driverId, followOnId]
//                     );

//                     followOnPayload = { ...followOnBooking, driver: driverId, is_follow_on: true };

//                     const foNotifTitle = "New Follow-On Job";
//                     const foNotifMsg = `Your next job #${followOnBooking.booking_id} is ready. Please accept or reject.`;
//                     try {
//                         await sendNotificationToDriver(db, driverId, foNotifTitle, foNotifMsg, {
//                             booking_id: String(followOnId),
//                             type: "new_ride"
//                         });
//                         await storeNotification(db, {
//                             user_type: 'driver',
//                             user_id: driverId,
//                             title: foNotifTitle,
//                             message: foNotifMsg
//                         });
//                     } catch (notifErr) {
//                         console.error("Notification error in follow-on dispatch:", notifErr.message);
//                     }

//                     const [driverInfoRows] = await db.query("SELECT name FROM drivers WHERE id = ?", [driverId]);
//                     const driverInfo = driverInfoRows[0];

//                     followOnEventData = {
//                         booking_id: followOnId,
//                         driver_id: driverId,
//                         driver_name: driverInfo?.name,
//                         booking: { ...followOnBooking, driver: driverId, booking_status: 'pending_acceptance' },
//                         message: `Follow-on job #${followOnBooking.booking_id} sent to ${driverInfo?.name} — waiting for acceptance`
//                     };

//                     setTimeout(async () => {
//                         try {
//                             const [checkRows] = await db.query(
//                                 "SELECT booking_status, driver_response FROM bookings WHERE id = ?",
//                                 [followOnId]
//                             );
//                             if (!checkRows.length) return;

//                             const { booking_status: currentStatus, driver_response } = checkRows[0];

//                             if (currentStatus === 'pending_acceptance' && driver_response !== 'accepted') {
//                                 await db.query(
//                                     `UPDATE bookings 
//                                              SET driver = NULL, booking_status = 'pending', driver_response = NULL 
//                                              WHERE id = ?`,
//                                     [followOnId]
//                                 );

//                                 const timeoutEvent = {
//                                     booking_id: followOnId,
//                                     driver_id: driverId,
//                                     driver_name: driverInfo?.name,
//                                     message: `Driver ${driverInfo?.name} did not respond to follow-on job #${followOnBooking.booking_id} — reset to pending`
//                                 };
//                                 dispatcherSockets.forEach((sid) => io.to(sid).emit("follow-on-job-timeout", timeoutEvent));
//                                 adminSockets.forEach((sid) => io.to(sid).emit("follow-on-job-timeout", timeoutEvent));
//                                 clientSockets.forEach((sid) => io.to(sid).emit("follow-on-job-timeout", timeoutEvent));

//                                 console.log(`⏱ Follow-on job #${followOnId} timed out — reset to pending`);
//                             }
//                         } catch (timeoutErr) {
//                             console.error("Follow-on timeout check error:", timeoutErr.message);
//                         }
//                     }, 30000);

//                     console.log(`Follow-on job #${followOnId} sent to driver #${driverId}`);
//                 }
//             } catch (foError) {
//                 console.error(`Error dispatching follow-on job:`, foError.message);
//             }
//         }

//         if (booking.driver) {
//             const driverSocketId = driverSockets.get(booking.driver.toString());
//             if (driverSocketId) {
//                 io.to(driverSocketId).emit("booking-status-updated", {
//                     booking_id: id, status: booking_status,
//                     message: `Ride status updated to ${booking_status}`
//                 });
//                 if (booking_status === 'cancelled' || booking_status === 'cancel') {
//                     io.to(driverSocketId).emit("booking-cancelled-event", {
//                         booking_id: id, booking: booking,
//                         message: cancelled_by_actor === 'user' ? `Ride #${booking.booking_id} has been cancelled by customer` : `Ride #${booking.booking_id} is cancelled by Admin or Dispatcher`
//                     });
//                 }
//             }
//         }
//         if (booking.user_id) {
//             const userSocketId = userSockets.get(booking.user_id.toString());
//             if (userSocketId) {
//                 io.to(userSocketId).emit("booking-status-updated", {
//                     booking_id: id, status: booking_status,
//                     message: `Your ride status has been updated to ${booking_status}`
//                 });
//                 if (booking_status === 'cancelled' || booking_status === 'cancel') {
//                     io.to(userSocketId).emit("booking-cancelled-event", {
//                         booking_id: id, booking: booking,
//                         message: cancelled_by_actor === 'user' ? `Your ride #${booking.booking_id} has been cancelled.` : `Your ride #${booking.booking_id} has been cancelled by Admin or Dispatcher.`
//                     });
//                 }
//             }
//         }

//         const statusUpdateData = {
//             booking_id: id, status: booking_status,
//             message: `Booking #${booking.booking_id} status updated to ${booking_status}`
//         };
//         dispatcherSockets.forEach((sid) => io.to(sid).emit("booking-status-updated", statusUpdateData));
//         adminSockets.forEach((sid) => io.to(sid).emit("booking-status-updated", statusUpdateData));

//         const [updatedBookingRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
//         const updatedBooking = updatedBookingRows[0];

//         const socketPayload = {
//             status: booking_status,
//             booking: { ...updatedBooking, cancelled_by: cancelled_by_actor === 'admin' ? 'admin' : updatedBooking.cancelled_by }
//         };
//         if (updatedBooking.user_id) {
//             const userSocketId = userSockets.get(updatedBooking.user_id.toString());
//             if (userSocketId) io.to(userSocketId).emit("user-ride-status-event", socketPayload);
//         }
//         if (updatedBooking.driver) {
//             const driverSocketId = driverSockets.get(updatedBooking.driver.toString());
//             if (driverSocketId) io.to(driverSocketId).emit("driver-ride-status-event", socketPayload);
//         }

//         if (booking_status === 'cancelled') {
//             const cancelNotif = {
//                 booking_id: id, booking_reference: updatedBooking.booking_id,
//                 message: `Booking #${updatedBooking.booking_id} has been cancelled`,
//                 cancelled_by: cancelled_by_actor
//             };
//             dispatcherSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
//             adminSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
//         }

//         if (followOnPayload) {
//             const driverSocketId = driverSockets.get(booking.driver.toString());
//             if (driverSocketId) {
//                 io.to(driverSocketId).emit("new-ride", followOnPayload);
//                 io.to(driverSocketId).emit("new-ride-request", {
//                     booking_id: followOnPayload.id,
//                     message: "You have a follow-on ride request",
//                     booking: followOnPayload
//                 });
//             }
//         }
//         if (followOnEventData) {
//             dispatcherSockets.forEach((sid) => io.to(sid).emit("follow-on-job-sent-to-driver", followOnEventData));
//             adminSockets.forEach((sid) => io.to(sid).emit("follow-on-job-sent-to-driver", followOnEventData));
//             clientSockets.forEach((sid) => io.to(sid).emit("follow-on-job-sent-to-driver", followOnEventData));
//         }

//         await broadcastDashboardCardsUpdate(req.tenantDb);

//         return res.json({ success: true, message: "Booking status updated successfully", res_user });

//     } catch (error) {
//         console.error("Error updating booking status:", error);
//         return res.status(500).json({ success: false, error: error.message });
//     }
// });

// app.post("/bookings/:id/set-follow-on-job", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const { follow_on_booking_id } = req.body;

//         if (!follow_on_booking_id) {
//             return res.status(400).json({ success: false, message: "follow_on_booking_id is required" });
//         }

//         if (parseInt(id) === parseInt(follow_on_booking_id)) {
//             return res.status(400).json({ success: false, message: "A booking cannot be a follow-on of itself" });
//         }

//         const db = getConnection(req.tenantDb);

//         const [job1Rows] = await db.query(
//             "SELECT id, booking_id, booking_status, driver, follow_on_job_id FROM bookings WHERE id = ?",
//             [id]
//         );
//         if (!job1Rows.length) return res.status(404).json({ success: false, message: "Job 1 not found" });

//         const job1 = job1Rows[0];

//         if (!job1.driver) {
//             return res.status(400).json({ success: false, message: "Job 1 has no driver assigned. Assign a driver first." });
//         }

//         if (!['ongoing', 'arrived', 'started'].includes(job1.booking_status)) {
//             return res.status(400).json({
//                 success: false,
//                 message: `Job 1 must be active (ongoing/arrived/started). Current status: ${job1.booking_status}`
//             });
//         }

//         const [job2Rows] = await db.query(
//             "SELECT id, booking_id, booking_status FROM bookings WHERE id = ?",
//             [follow_on_booking_id]
//         );
//         if (!job2Rows.length) return res.status(404).json({ success: false, message: "Follow-on booking (Job 2) not found" });

//         const job2 = job2Rows[0];

//         if (!['pending', 'pending_acceptance'].includes(job2.booking_status)) {
//             return res.status(400).json({
//                 success: false,
//                 message: `Job 2 must be pending. Current status: ${job2.booking_status}`
//             });
//         }

//         const [alreadyLinked] = await db.query(
//             "SELECT id FROM bookings WHERE follow_on_job_id = ?",
//             [follow_on_booking_id]
//         );
//         if (alreadyLinked.length) {
//             return res.status(400).json({
//                 success: false,
//                 message: `Booking #${job2.booking_id} is already queued as a follow-on for another job`
//             });
//         }

//         const [driverRows] = await db.query("SELECT id, name FROM drivers WHERE id = ?", [job1.driver]);
//         const driverName = driverRows[0]?.name || "Driver";

//         const dispatcherName = req.body.dispatcher_name || "Dispatcher";
//         await db.query(
//             "UPDATE bookings SET follow_on_job_id = ?, dispatcher_action = ? WHERE id = ?",
//             [follow_on_booking_id, `${dispatcherName} linked booking #${job2.booking_id} as a follow-on job to this ride`, id]
//         );

//         const responseData = {
//             job1_id: job1.id,
//             job1_booking_id: job1.booking_id,
//             job2_id: job2.id,
//             job2_booking_id: job2.booking_id,
//             driver_id: job1.driver,
//             driver_name: driverName,
//             message: `Booking #${job2.booking_id} queued as follow-on after #${job1.booking_id} for ${driverName}`
//         };

//         dispatcherSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));
//         adminSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));
//         clientSockets.forEach((sid) => io.to(sid).emit("follow-on-job-linked", responseData));

//         console.log(`Follow-on linked: Job #${job1.booking_id} → Job #${job2.booking_id} (Driver: ${driverName})`);

//         return res.json({ success: true, message: responseData.message, data: responseData });

//     } catch (error) {
//         console.error("Set follow-on job error:", error);
//         return res.status(500).json({ success: false, message: error.message });
//     }
// });

// app.get("/bookings/:id", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const db = getConnection(req.tenantDb);
//         const [bookings] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);

//         if (bookings.length === 0) {
//             return res.status(404).json({ success: false, message: "Booking not found" });
//         }

//         return res.json({ success: true, data: bookings[0] });
//     } catch (error) {
//         console.error("Error fetching booking:", error);
//         return res.status(500).json({ success: false, error: error.message });
//     }
// });

// app.post("/bookings/:id/follow-driver", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const db = getConnection(req.tenantDb);

//         const [bookings] = await db.query(`
//             SELECT b.*,
//                 d.id as driver_id, d.name as driver_name, d.email as driver_email,
//                 d.phone_no as driver_phone, d.profile_image as driver_profile_image,
//                 d.latitude as driver_latitude, d.longitude as driver_longitude,
//                 d.driving_status as driver_status
//             FROM bookings b
//             LEFT JOIN drivers d ON b.driver = d.id
//             WHERE b.id = ?
//         `, [id]);

//         if (bookings.length === 0) return res.status(404).json({ success: false, message: "Booking not found" });

//         const booking = bookings[0];
//         if (!booking.driver) return res.status(400).json({ success: false, message: "No driver assigned to this booking" });

//         const driverInfo = {
//             id: booking.driver_id, name: booking.driver_name, email: booking.driver_email,
//             phone_no: booking.driver_phone, profile_image: booking.driver_profile_image,
//             latitude: booking.driver_latitude, longitude: booking.driver_longitude,
//             status: booking.driver_status
//         };

//         const driverSocketId = driverSockets.get(booking.driver.toString());
//         if (driverSocketId) {
//             io.to(driverSocketId).emit("start-location-tracking", {
//                 booking_id: booking.id,
//                 message: "Location tracking started for this booking"
//             });
//         }

//         if (booking.dispatcher_id) {
//             const dispatcherSocketId = dispatcherSockets.get(booking.dispatcher_id.toString());
//             if (dispatcherSocketId) {
//                 io.to(dispatcherSocketId).emit("driver-location-tracking-started", { booking_id: booking.id, driver: driverInfo });
//             }
//         }

//         adminSockets.forEach((socketId) => {
//             io.to(socketId).emit("driver-location-tracking-started", { booking_id: booking.id, driver: driverInfo });
//         });

//         return res.json({
//             success: true,
//             message: "Driver location tracking started",
//             data: { booking_id: booking.id, driver: driverInfo }
//         });

//     } catch (error) {
//         console.error("Error starting driver tracking:", error);
//         return res.status(500).json({ success: false, error: error.message });
//     }
// });

// app.delete("/bookings/:id/remove-follow-on-job", async (req, res) => {
//     try {
//         const { id } = req.params;
//         const db = getConnection(req.tenantDb);

//         const [rows] = await db.query(
//             "SELECT id, booking_id, follow_on_job_id FROM bookings WHERE id = ?", [id]
//         );
//         if (!rows.length) return res.status(404).json({ success: false, message: "Booking not found" });
//         if (!rows[0].follow_on_job_id) return res.status(400).json({ success: false, message: "No follow-on job linked" });

//         await db.query("UPDATE bookings SET follow_on_job_id = NULL WHERE id = ?", [id]);

//         dispatcherSockets.forEach((sid) => io.to(sid).emit("follow-on-job-removed", { booking_id: parseInt(id) }));
//         adminSockets.forEach((sid) => io.to(sid).emit("follow-on-job-removed", { booking_id: parseInt(id) }));

//         return res.json({ success: true, message: "Follow-on job unlinked successfully" });
//     } catch (error) {
//         console.error("Remove follow-on job error:", error);
//         return res.status(500).json({ success: false, message: error.message });
//     }
// });

// app.post("/driver/accept-ride", async (req, res) => {
//     try {
//         const { ride_id } = req.body;
//         const db = getConnection(req.tenantDb);

//         await db.query(
//             `UPDATE bookings SET booking_status = 'ongoing' WHERE id = ?`,
//             [ride_id]
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
//                 vt.vehicle_type_name,
//                 vt.vehicle_type_service,
//                 sc.id as sub_company_id,
//                 sc.name as sub_company_name,
//                 sc.email as sub_company_email
//             FROM bookings b
//             LEFT JOIN drivers d ON b.driver = d.id  
//             LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
//             LEFT JOIN sub_companies sc ON b.sub_company = sc.id
//             WHERE b.id = ?
//         `, [ride_id]);

//         if (!updatedBookings.length) {
//             return res.status(404).json({ success: 0, message: "Ride not found" });
//         }

//         const updatedBooking = updatedBookings[0];

//         const {
//             driver_id, driver_name, driver_email, driver_phone, driver_profile_image,
//             vehicle_type_id, vehicle_type_name, vehicle_type_service,
//             sub_company_id, sub_company_name, sub_company_email,
//             ...bookingData
//         } = updatedBooking;

//         const formattedBooking = {
//             ...bookingData,
//             driverDetail: driver_id ? {
//                 id: driver_id,
//                 name: driver_name,
//                 email: driver_email,
//                 phone_no: driver_phone,
//                 profile_image: driver_profile_image
//             } : null,
//             vehicleDetail: vehicle_type_id ? {
//                 id: vehicle_type_id,
//                 vehicle_type_name,
//                 vehicle_type_service
//             } : null,
//             subCompanyDetail: sub_company_id ? {
//                 id: sub_company_id,
//                 name: sub_company_name,
//                 email: sub_company_email
//             } : null
//         };

//         const eventData = {
//             booking_id: ride_id,
//             driver_id,
//             driver_name,
//             driver_profile_image,
//             booking: formattedBooking,
//             message: `${driver_name} accepted the ride`
//         };

//         const clientId = req.headers["database"];
//         const socketId = clientSockets.get(clientId?.toString());
//         if (socketId) {
//             io.to(socketId).emit("on-job-driver-event", driver_name);
//         }

//         dispatcherSockets.forEach((socketId) => io.to(socketId).emit("job-accepted-by-driver", eventData));
//         adminSockets.forEach((socketId) => io.to(socketId).emit("job-accepted-by-driver", eventData));
//         clientSockets.forEach((socketId) => io.to(socketId).emit("job-accepted-by-driver", eventData));

//         await broadcastDashboardCardsUpdate(req.tenantDb);

//         return res.json({ success: 1, message: "Ride accepted successfully", data: formattedBooking });

//     } catch (error) {
//         console.error("Accept Ride Error:", error);
//         return res.status(500).json({ success: 0, message: "Something went wrong" });
//     }
// });

// app.post("/driver/cancel-ride", async (req, res) => {
//     try {
//         const { ride_id, cancel_reason } = req.body;

//         const db = getConnection(req.tenantDb);

//         await db.query(
//             `UPDATE bookings SET driver = NULL, booking_status = 'pending' WHERE id = ?`,
//             [ride_id]
//         );

//         const [bookings] = await db.query(`
//             SELECT 
//                 b.*,
//                 d.id as driver_id,
//                 d.name as driver_name,
//                 d.profile_image as driver_profile_image
//             FROM bookings b
//             LEFT JOIN drivers d ON b.driver = d.id
//             WHERE b.id = ?
//         `, [ride_id]);

//         if (!bookings.length) {
//             return res.status(404).json({ success: 0, message: "Ride not found" });
//         }

//         const { driver_id, driver_name, driver_profile_image } = bookings[0];

//         const eventData = {
//             booking_id: ride_id,
//             driver_id,
//             driver_name,
//             driver_profile_image,
//             cancel_reason: cancel_reason || "",
//             booking: {
//                 id: bookings[0].id,
//                 booking_id: bookings[0].booking_id,
//                 pickup_location: bookings[0].pickup_location,
//                 destination_location: bookings[0].destination_location,
//                 booking_date: bookings[0].booking_date,
//                 pickup_time: bookings[0].pickup_time,
//                 booking_status: "cancelled",
//             },
//             message: `${driver_name} cancelled the ride`
//         };

//         if (bookingForUser && bookingForUser[0].user_id) {
//             try {
//                 const userNotifTitle = "Ride Cancelled";
//                 const userNotifMessage = `Your ride #${bookingForUser[0].booking_id} has been cancelled by the driver and is being re-dispatched.`;
//                 await sendNotificationToUser(db, bookingForUser[0].user_id, userNotifTitle, userNotifMessage, {
//                     booking_id: String(ride_id),
//                     type: "ride_cancelled"
//                 });
//                 await storeNotification(db, {
//                     user_type: 'rider',
//                     user_id: bookingForUser[0].user_id,
//                     title: userNotifTitle,
//                     message: userNotifMessage
//                 });
//             } catch (userNotifErr) {
//                 console.error("User Notification error in /driver/cancel-ride:", userNotifErr.message);
//             }
//         }

//         dispatcherSockets.forEach((socketId) => io.to(socketId).emit("job-cancelled-by-driver", eventData));
//         adminSockets.forEach((socketId) => io.to(socketId).emit("job-cancelled-by-driver", eventData));
//         clientSockets.forEach((socketId) => io.to(socketId).emit("job-cancelled-by-driver", eventData));

//         const cancelNotif = { booking_id: ride_id, message: eventData.message };
//         dispatcherSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));
//         adminSockets.forEach((sid) => io.to(sid).emit("booking-cancelled-event", cancelNotif));

//         await broadcastDashboardCardsUpdate(req.tenantDb);
//         return res.json({ success: 1, message: "Cancel event broadcasted" });

//     } catch (error) {
//         console.error("Cancel Ride Error:", error);
//         return res.status(500).json({ success: 0, message: "Something went wrong" });
//     }
// });
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
