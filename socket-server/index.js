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
const clientSockets = new Map();
const adminSockets = new Map();

io.use(async (socket, next) => {

    console.log("enter socket 1");

    const authHeader = socket.handshake.headers.authorization;
    // const database = socket.handshake.query.database;
    const driverId = socket.handshake.query.driver_id;
    const userId = socket.handshake.query.user_id;
    const adminId = socket.handshake.query.admin_id;
    const role = socket.handshake.query.role;
    const dispatcherId = socket.handshake.query.dispatcher_id;
    const clientId = socket.handshake.query.client_id;
    if (!authHeader || (role === 'driver' && !driverId) || (role === 'admin' && !adminId) || (role === 'client' && !clientId) || (role === 'dispatcher' && !dispatcherId) || (role === 'user' && !userId)) {
        return next(new Error("Unauthorized"));
    }
    socket.token = authHeader.split(" ")[1];
    socket.driverId = driverId;
    socket.dispatcherId = dispatcherId;
    socket.clientId = clientId;
    socket.userId = userId;
    socket.adminId = adminId;
    // socket.database = database;

    next();
});


io.on("connection", (socket) => {
console.log("enter socket 2");
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
        //Send to Laravel (store in DB)
        try {
            var dataArray;
            if (typeof data === "string") {
                dataArray = JSON.parse(data);
            }
            else{
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
            socket.broadcast.emit("driver-location-update", response.driver);
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

app.post("/send-new-ride", (req, res) => {
    console.log("send-ride");
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

app.post("/send-reminder", (req, res) => {
    console.log("send reminder")

    const { clientId, title, description } = req.body;
    
    console.log("clientId" , clientId)
    console.log("title" , title)
    console.log("description" , description)
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
