const express = require("express");
const http = require("http");
const { Server } = require("socket.io");
const axios = require("axios");
const db = require("./db"); 

const app = express();
app.use(express.json());
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
    }
    if (role === "user" && userId) {
        userSockets.set(userId.toString(), socket.id);
    }
    if (role === "client" && clientId) {
        clientSockets.set(clientId.toString(), socket.id);
    }
    if (role === "admin" && adminId) {
        adminSockets.set(adminId.toString(), socket.id);
    }
    if (driverId) {
        driverSockets.set(driverId.toString(), socket.id);
    }

    // Event call when from Flutter to Update location for driver
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
            // Broadcast to React users
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
        }
        if (role === "user" && userId) {
            userSockets.delete(userId.toString());
        }
        if (role === "client" && clientId) {
            clientSockets.delete(clientId.toString());
        }
    });
});

app.use((req, res, next) => {
    // if (req.headers.authorization !== `Bearer INTERNAL_NODE_SECRET`) {
    //     return res.status(401).json({ error: "Unauthorized" });
    // }
    next();
});

app.get("/api/bookings", async (req, res) => {
    try {
        const { status, date, user_id, driver_id, page = 1, limit = 10 } = req.query;
        const offset = (page - 1) * limit;

        let query = `
            SELECT 
                cb.*,
                u.name as user_name,
                u.email as user_email,
                u.phone_no as user_phone,
                d.name as driver_name,
                d.phone_no as driver_phone
            FROM company_bookings cb
            LEFT JOIN company_users u ON cb.user_id = u.id
            LEFT JOIN company_drivers d ON cb.driver = d.id
            WHERE 1=1
        `;
        
        const queryParams = [];

        // Add filters
        if (status) {
            query += ` AND cb.booking_status = ?`;
            queryParams.push(status);
        }
        if (date) {
            query += ` AND DATE(cb.booking_date) = ?`;
            queryParams.push(date);
        }
        if (user_id) {
            query += ` AND cb.user_id = ?`;
            queryParams.push(user_id);
        }
        if (driver_id) {
            query += ` AND cb.driver = ?`;
            queryParams.push(driver_id);
        }

        // Add ordering and pagination
        query += ` ORDER BY cb.booking_date DESC, cb.id DESC LIMIT ? OFFSET ?`;
        queryParams.push(parseInt(limit), parseInt(offset));

        // Execute query
        const [bookings] = await db.query(query, queryParams);

        // Get total count
        let countQuery = `SELECT COUNT(*) as total FROM company_bookings cb WHERE 1=1`;
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

        // Emit to connected sockets (dispatchers/admins)
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

app.get("/api/bookings/:id", async (req, res) => {
    try {
        const { id } = req.params;

        const query = `
            SELECT 
                cb.*,
                u.name as user_name,
                u.email as user_email,
                u.phone_no as user_phone,
                d.name as driver_name,
                d.phone_no as driver_phone,
                d.vehicle_no as driver_vehicle_no,
                vt.name as vehicle_type_name
            FROM company_bookings cb
            LEFT JOIN company_users u ON cb.user_id = u.id
            LEFT JOIN company_drivers d ON cb.driver = d.id
            LEFT JOIN company_vehicle_types vt ON cb.vehicle = vt.id
            WHERE cb.id = ?
        `;

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

app.post("/api/bookings/broadcast", async (req, res) => {
    try {
        const { booking_id } = req.body;

        // Fetch booking details from database
        const query = `
            SELECT 
                cb.*,
                u.name as user_name,
                u.email as user_email,
                d.name as driver_name
            FROM company_bookings cb
            LEFT JOIN company_users u ON cb.user_id = u.id
            LEFT JOIN company_drivers d ON cb.driver = d.id
            WHERE cb.id = ?
        `;

        const [bookings] = await db.query(query, [booking_id]);

        if (bookings.length === 0) {
            return res.status(404).json({
                success: false,
                message: "Booking not found"
            });
        }

        const booking = bookings[0];

        // Broadcast to all dispatchers
        let sentCount = 0;
        dispatcherSockets.forEach((socketId) => {
            io.to(socketId).emit("new-booking-event", booking);
            sentCount++;
        });

        // Also broadcast to admin sockets
        adminSockets.forEach((socketId) => {
            io.to(socketId).emit("new-booking-event", booking);
            sentCount++;
        });

        return res.json({
            success: true,
            sent_to: sentCount,
            booking: booking
        });

    } catch (error) {
        console.error("Error broadcasting booking:", error);
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

app.post("/change-ride-status", (req, res) => {
    const { userId, status, booking } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("user-ride-status-event", {status, booking});
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
        io.to(socketId).emit("driver-ride-status-event", {status, booking});
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
        io.to(socketId).emit("waiting-driver-event", {driverName, plot});
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
    console.log("Socket server running on 3001");
});