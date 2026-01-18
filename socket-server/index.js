const express = require("express");
const http = require("http");
const { Server } = require("socket.io");
const axios = require("axios");

const app = express();
const server = http.createServer(app);

const io = new Server(server, {
    cors: {
        origin: "*"
    }
});

io.on("connection", (socket) => {
    console.log("Driver connected:", socket.id);

    socket.on("driver-location", async (data) => {
        // data = { driver_id, lat, lng }

        // 🔥 Send to Laravel (store in DB)
        try {
            await axios.post(
                "http://127.0.0.1:8000/api/driver/location",
                data,
                {
                    headers: {
                        "Authorization": "Bearer YOUR_INTERNAL_TOKEN"
                    }
                }
            );
        } catch (err) {
            console.error("Laravel error", err.message);
        }

        // 🔥 Broadcast to React users
        socket.broadcast.emit("driver-location-update", data);
    });

    socket.on("disconnect", () => {
        console.log("Disconnected:", socket.id);
    });
});

server.listen(3001, () => {
    console.log("Socket server running on 3001");
});
