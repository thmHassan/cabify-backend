const express = require("express");
const cors = require("cors")
const http = require("http");
const { Server } = require("socket.io");
const axios = require("axios");
const { getConnection } = require("./db")
const transporter = require("./utils/Emailconfig");
const { getBookingConfirmationEmail } = require("./utils/Emailtemplate");
// const router = require("./router/router");

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
    next();
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

// app.use("/", router)

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
                    WHEN booking_status = 'no_show' 
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

        // Dashboard card filters
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
                    baseQuery += ` AND b.booking_status = 'no_show'`;
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

        // Format the response to include nested objects
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

        // Count query
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
            return res.status(404).json({
                success: false,
                message: "Booking not found"
            });
        }

        const booking = bookings[0];

        if (!booking.email) {
            return res.status(400).json({
                success: false,
                message: "Booking does not have an email address"
            });
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

        // Send email
        const info = await transporter.sendMail(mailOptions);

        console.log(`✅ Email sent successfully to ${booking.email}`);
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
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

app.put("/bookings/:id/status", async (req, res) => {
    try {
        const { id } = req.params;
        const { booking_status, cancel_reason, cancelled_by } = req.body;

        if (!booking_status) {
            return res.status(400).json({
                success: false,
                message: "booking_status is required"
            });
        }

        const db = getConnection(req.tenantDb);

        const [bookings] = await db.query(
            "SELECT * FROM bookings WHERE id = ?",
            [id]
        );

        if (bookings.length === 0) {
            return res.status(404).json({
                success: false,
                message: "Booking not found"
            });
        }

        const booking = bookings[0];

        let updateQuery = "UPDATE bookings SET booking_status = ?";
        const params = [booking_status];

        if (booking_status === 'cancelled') {
            if (cancel_reason) {
                updateQuery += ", cancel_reason = ?";
                params.push(cancel_reason);
            }
            if (cancelled_by) {
                updateQuery += ", cancelled_by = ?";
                params.push(cancelled_by);
            }
        }

        updateQuery += " WHERE id = ?";
        params.push(id);

        await db.query(updateQuery, params);

        if (booking.driver) {
            let driverStatus = null;

            if (booking_status === 'cancelled' || booking_status === 'completed' || booking_status === 'no_show') {
                driverStatus = 'idle';
            } else if (booking_status === 'ongoing' || booking_status === 'started' || booking_status === 'arrived') {
                driverStatus = 'busy';
            }

            if (driverStatus) {
                await db.query(
                    "UPDATE drivers SET driving_status = ? WHERE id = ?",
                    [driverStatus, booking.driver]
                );
            }
        }

        const [updatedBookings] = await db.query(`
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
                vehicle_type_name: vehicle_type_name,
                vehicle_type_service: vehicle_type_service
            } : null,
            subCompanyDetail: sub_company_id ? {
                id: sub_company_id,
                name: sub_company_name,
                email: sub_company_email
            } : null
        };

        // Emit socket events based on status change
        if (booking.user_id) {
            const userSocketId = userSockets.get(booking.user_id.toString());
            if (userSocketId) {
                io.to(userSocketId).emit("user-ride-status-event", {
                    status: booking_status,
                    booking: formattedBooking
                });
            }
        }

        if (booking.driver) {
            const driverSocketId = driverSockets.get(booking.driver.toString());
            if (driverSocketId) {
                io.to(driverSocketId).emit("driver-ride-status-event", {
                    status: booking_status,
                    booking: formattedBooking
                });
            }
        }

        return res.json({
            success: true,
            message: "Booking status updated successfully",
            data: formattedBooking
        });

    } catch (error) {
        console.error("Error updating booking status:", error);
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

app.post("/bookings/broadcast", async (req, res) => {
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
    return res.json({
        success: true,
        sent_to: sentCount
    });
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
    return res.json({
        success: true,
        sent_to: sentCount
    });
});

app.post("/bid-accept", (req, res) => {
    const { driverId, booking } = req.body;
    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("bid-accept-event", booking);
    }
    return res.json({
        success: true,
    });
});

app.post("/place-bid", (req, res) => {
    const { userId, bid } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("place-bid-event", bid);
    }
    return res.json({
        success: true,
    });
});

app.post("/change-ride-status", (req, res) => {
    const { userId, status, booking } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("user-ride-status-event", { status, booking });
    }
    return res.json({
        success: true,
    });
});

app.post("/user-message-notification", (req, res) => {
    const { userId, chat } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("user-message-event", chat);
    }
    return res.json({
        success: true,
    });
});

app.post("/driver-message-notification", (req, res) => {
    const { driverId, chat } = req.body;
    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("driver-message-event", chat);
    }
    return res.json({
        success: true,
    });
});

app.post("/change-driver-ride-status", (req, res) => {
    const { driverId, status, booking } = req.body;
    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("driver-ride-status-event", { status, booking });
    }
    return res.json({
        success: true,
    });
});

app.post("/on-job-driver", (req, res) => {
    const { clientId, driverName } = req.body;
    const socketId = clientSockets.get(clientId.toString());
    if (socketId) {
        io.to(socketId).emit("on-job-driver-event", driverName);
    }
    return res.json({
        success: true,
    });
});

app.post("/waiting-driver", (req, res) => {
    const { clientId, driverName, plot } = req.body;
    const socketId = clientSockets.get(clientId.toString());
    if (socketId) {
        io.to(socketId).emit("waiting-driver-event", { driverName, plot });
    }
    return res.json({
        success: true,
    });
});

app.post("/send-reminder", (req, res) => {
    const { clientId, title, description } = req.body;
    const socketId = clientSockets.get(clientId.toString());
    if (socketId) {
        io.to(socketId).emit("send-reminder", { title, description });
    }
    return res.json({
        success: true,
    });
});

server.listen(3001, "0.0.0.0", () => {
    console.log("🚀 Socket server running on port 3001");
});