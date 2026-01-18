const express = require("express");
const http = require("http");
const { Server } = require("socket.io");
const axios = require("axios");

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

io.use(async (socket, next) => {
    const authHeader = socket.handshake.headers.authorization;
    const driverId = socket.handshake.query.driver_id;
    const userId = socket.handshake.query.user_id;
    const role = socket.handshake.query.role;
    const dispatcherId = socket.handshake.query.dispatcher_id;

    if (!authHeader || (role === 'driver' && !driverId) || (role === 'dispatcher' && !dispatcherId) || (role === 'user' && !userId)) {
        return next(new Error("Unauthorized"));
    }
    socket.token = authHeader.split(" ")[1];
    socket.driverId = driverId;
    socket.dispatcherId = dispatcherId;
    socket.userId = userId;

    next();
});


io.on("connection", (socket) => {
    // console.log("Driver connected:", socket.id);

    const role = socket.handshake.query.role;
    const driverId = socket.handshake.query.driver_id;
    const dispatcherId = socket.handshake.query.dispatcher_id;
    const userId = socket.handshake.query.user_id;

    if (role === "dispatcher" && dispatcherId) {
        dispatcherSockets.set(dispatcherId.toString(), socket.id);
        console.log(`Dispatcher ${dispatcherId} connected with socket ${socket.id}`);
    }
    if (role === "user" && userId) {
        userSockets.set(userId.toString(), socket.id);
        console.log(`user ${userId} connected with socket ${socket.id}`);
    }

    if (driverId) {
        driverSockets.set(driverId.toString(), socket.id);
        console.log(`Driver ${driverId} connected with socket ${socket.id}`);
    }

    // Event call when from Flutter to Update location for driver
    socket.on("driver-location", async (data) => {
        //Send to Laravel (store in DB)
        try {
            await axios.post(
                "https://backend.cabifyit.com/api/driver/location",
                data,
                {
                    headers: {
                        Authorization: `Bearer ${socket.token}`,
                    }
                }
            );
        } catch (err) {
            console.error("Laravel error", err.message);
        }
        // Broadcast to React users
        socket.broadcast.emit("driver-location-update", data);
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
        console.log("Disconnected:", socket.id);
    });
});

app.use((req, res, next) => {
    if (req.headers.authorization !== `Bearer ${process.env.NODE_INTERNAL_SECRET}`) {
        return res.status(401).json({ error: "Unauthorized" });
    }
    next();
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
        io.to(socketId).emit("user-ride-status-event", status, booking);
    }
    return res.json({
        success: true,
    });
});

app.post("/user-message-notification", (req, res) => {
    const { userId, booking } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("user-message-event", booking);
    }
    return res.json({
        success: true,
    });
});

app.post("/driver-message-notification", (req, res) => {
    const { driverId, booking } = req.body;
    const socketId = driverSockets.get(driverId.toString());
    if (socketId) {
        io.to(socketId).emit("driver-message-event", booking);
    }
    return res.json({
        success: true,
    });
});

app.post("/change-driver-ride-status", (req, res) => {
    const { userId, status, booking } = req.body;
    const socketId = userSockets.get(userId.toString());
    if (socketId) {
        io.to(socketId).emit("driver-ride-status-event", status, booking);
    }
    return res.json({
        success: true,
    });
});

server.listen(3001, () => {
    console.log("Socket server running on 3001");
});
