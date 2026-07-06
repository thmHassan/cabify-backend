#!/usr/bin/env node

const { execFile } = require("node:child_process");
const fs = require("node:fs/promises");
const { promisify } = require("node:util");
const { io } = require("socket.io-client");

const execFileAsync = promisify(execFile);

const intEnv = (name, fallback, min = 0) => {
    const value = Number.parseInt(process.env[name], 10);
    return Number.isNaN(value) || value < min ? fallback : value;
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const url = process.env.SOCKET_STRESS_URL || "http://127.0.0.1:3001";
const tenants = (process.env.SOCKET_STRESS_TENANTS || "alpha31")
    .split(",")
    .map((tenant) => tenant.trim())
    .filter(Boolean);
const totalDrivers = intEnv("SOCKET_STRESS_DRIVERS", 500, 1);
const totalDispatchers = intEnv("SOCKET_STRESS_DISPATCHERS", 30, 0);
const totalCustomers = intEnv("SOCKET_STRESS_CUSTOMERS", 270, 0);
const idOffset = intEnv("SOCKET_STRESS_ID_OFFSET", 1_000_000, 0);
const gpsEvents = intEnv("SOCKET_STRESS_GPS_EVENTS", totalDrivers * 2, 0);
const batchSize = intEnv("SOCKET_STRESS_BATCH", 100, 1);
const batchDelayMs = intEnv("SOCKET_STRESS_BATCH_DELAY_MS", 250, 0);
const holdMs = intEnv("SOCKET_STRESS_HOLD_MS", 30_000, 1_000);
const healthIntervalMs = intEnv("SOCKET_STRESS_HEALTH_INTERVAL_MS", 10_000, 1_000);
const connectTimeoutMs = intEnv("SOCKET_STRESS_CONNECT_TIMEOUT_MS", 10_000, 1_000);
const connectSettleMs = intEnv("SOCKET_STRESS_CONNECT_SETTLE_MS", 3_000, 0);
const maxConnectErrors = intEnv("SOCKET_STRESS_MAX_CONNECT_ERRORS", 0, 0);
const token = process.env.SOCKET_STRESS_TOKEN || "stress-token";
const healthUrl = process.env.SOCKET_STRESS_HEALTH_URL || `${url.replace(/\/$/, '')}/socket-health`;
const healthToken = process.env.SOCKET_STRESS_HEALTH_TOKEN || process.env.NODE_INTERNAL_SECRET || "";
const healthCommand = process.env.SOCKET_STRESS_HEALTH_COMMAND || "";
const readyFile = process.env.SOCKET_STRESS_READY_FILE || "";
const startFile = process.env.SOCKET_STRESS_START_FILE || "";
const startWaitMs = intEnv("SOCKET_STRESS_START_WAIT_MS", 120_000, 0);

const sockets = [];
const stats = {
    connected: 0,
    connectErrors: 0,
    disconnects: 0,
    gpsSent: 0,
    driverLocationUpdates: 0,
    healthErrors: 0,
};
const healthSamples = [];

const tenantForIndex = (index) => tenants[index % tenants.length];

const makeSocket = ({ role, id, database }) => {
    const socket = io(url, {
        transports: ["websocket"],
        forceNew: true,
        reconnection: false,
        timeout: connectTimeoutMs,
        extraHeaders: {
            Authorization: `Bearer ${token}`,
        },
        query: {
            role,
            database,
            driver_id: role === "driver" ? id : undefined,
            dispatcher_id: role === "dispatcher" ? id : undefined,
            user_id: role === "user" ? id : undefined,
        },
    });

    socket.on("connect", () => {
        stats.connected += 1;
    });
    socket.on("connect_error", (error) => {
        stats.connectErrors += 1;
        if (stats.connectErrors <= 10) {
            console.error(`[connect_error] ${role}:${id}:${database} ${error.message}`);
        }
    });
    socket.on("disconnect", () => {
        stats.disconnects += 1;
    });
    socket.on("driver-location-update", () => {
        stats.driverLocationUpdates += 1;
    });

    sockets.push({ socket, role, id, database });
};

const createSockets = async () => {
    const all = [];
    for (let index = 0; index < totalDrivers; index += 1) {
        all.push({ role: "driver", id: String(idOffset + index + 1), database: tenantForIndex(index) });
    }
    for (let index = 0; index < totalDispatchers; index += 1) {
        all.push({ role: "dispatcher", id: String(idOffset + index + 1), database: tenantForIndex(index) });
    }
    for (let index = 0; index < totalCustomers; index += 1) {
        all.push({ role: "user", id: String(idOffset + index + 1), database: tenantForIndex(index) });
    }

    for (let offset = 0; offset < all.length; offset += batchSize) {
        all.slice(offset, offset + batchSize).forEach(makeSocket);
        await sleep(batchDelayMs);
        console.log(`created=${Math.min(offset + batchSize, all.length)}/${all.length} connected=${stats.connected} errors=${stats.connectErrors}`);
    }
};

const waitForConnectionsToSettle = async () => {
    const expected = totalDrivers + totalDispatchers + totalCustomers;
    const deadline = Date.now() + connectSettleMs;

    while (
        connectSettleMs > 0
        && stats.connected + stats.connectErrors < expected
        && Date.now() < deadline
    ) {
        await sleep(500);
    }
};

const markReady = async () => {
    if (!readyFile) return;
    await fs.writeFile(readyFile, JSON.stringify({
        readyAt: new Date().toISOString(),
        connected: stats.connected,
        connectErrors: stats.connectErrors,
        expected: totalDrivers + totalDispatchers + totalCustomers,
    }, null, 2));
};

const waitForStartSignal = async () => {
    if (!startFile) return;
    const deadline = Date.now() + startWaitMs;

    while (Date.now() < deadline) {
        try {
            await fs.access(startFile);
            return;
        } catch (_) {
            await sleep(500);
        }
    }

    throw new Error(`Timed out waiting for start file: ${startFile}`);
};

const emitGps = async () => {
    const drivers = sockets.filter((entry) => entry.role === "driver");
    if (drivers.length === 0 || gpsEvents === 0) return;

    for (let index = 0; index < gpsEvents; index += 1) {
        const driver = drivers[index % drivers.length];
        const step = index / drivers.length;
        driver.socket.emit("driver-location", {
            id: driver.id,
            driver_id: driver.id,
            database: driver.database,
            latitude: 25.2048 + (Number(driver.id) % 100) * 0.0001 + step * 0.00001,
            longitude: 55.2708 + (Number(driver.id) % 100) * 0.0001 + step * 0.00001,
            driving_status: index % 10 === 0 ? "busy" : "idle",
            online_status: "online",
        });
        stats.gpsSent += 1;
        if (index > 0 && index % 1000 === 0) {
            await sleep(10);
        }
    }
};

const shutdown = async () => {
    sockets.forEach(({ socket }) => socket.disconnect());
    await sleep(1000);
};

const fetchHealth = async (label) => {
    if (
        (!healthCommand && !healthUrl)
        || (!healthCommand && healthUrl.toLowerCase() === "none")
    ) return;
    try {
        const body = healthCommand
            ? JSON.parse((await execFileAsync("/bin/sh", ["-lc", healthCommand], { maxBuffer: 1024 * 1024 })).stdout)
            : await (async () => {
                const response = await fetch(healthUrl, {
                    headers: healthToken ? { Authorization: `Bearer ${healthToken}` } : {},
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })();
        const sample = {
            label,
            sockets: body.sockets,
            gps: body.gps,
            db: body.db?.pools?.map((pool) => ({
                dbName: pool.dbName,
                isCentral: pool.isCentral,
                total: pool.total,
                active: pool.active,
                queued: pool.queued,
                idleMs: pool.idleMs,
            })),
            dbCounters: body.db?.counters,
            queueBroadcast: body.queueBroadcast,
            liveGpsBroadcast: body.liveGpsBroadcast,
            runtime: {
                driverLocationPersistInFlight: body.runtime?.driverLocationPersistInFlight,
                driverLocationPendingPersist: body.runtime?.driverLocationPendingPersist,
                driverLocationQueuedPersist: body.runtime?.driverLocationQueuedPersist,
                driverDisconnectTimers: body.runtime?.driverDisconnectTimers,
                knownDriverRuntimeKeys: body.runtime?.knownDriverRuntimeKeys,
            },
        };
        healthSamples.push(sample);
        console.log(`[health:${label}] ${JSON.stringify(sample)}`);
    } catch (error) {
        stats.healthErrors += 1;
        console.error(`[health:${label}] ${error.message}`);
    }
};

const main = async () => {
    console.log(JSON.stringify({
        url,
        healthUrl,
        healthCommand: healthCommand ? "<set>" : "",
        tenants,
        totalDrivers,
        totalDispatchers,
        totalCustomers,
        idOffset,
        gpsEvents,
        batchSize,
        batchDelayMs,
        holdMs,
        healthIntervalMs,
        connectTimeoutMs,
        connectSettleMs,
        maxConnectErrors,
        readyFile: readyFile ? "<set>" : "",
        startFile: startFile ? "<set>" : "",
        startWaitMs,
    }));

    await fetchHealth("before");
    await createSockets();
    await waitForConnectionsToSettle();
    await fetchHealth("after-connect");
    await markReady();
    await waitForStartSignal();
    await emitGps();
    await fetchHealth("after-gps");
    console.log(`holding=${holdMs}ms connected=${stats.connected} errors=${stats.connectErrors} gpsSent=${stats.gpsSent}`);
    const holdStartedAt = Date.now();
    while (Date.now() - holdStartedAt < holdMs) {
        await sleep(Math.min(healthIntervalMs, holdMs - (Date.now() - holdStartedAt)));
        await fetchHealth("hold");
    }
    await shutdown();
    await fetchHealth("after-disconnect");

    console.log(JSON.stringify({ healthSamples }, null, 2));
    console.log(JSON.stringify(stats, null, 2));
    if (stats.connectErrors > maxConnectErrors) {
        process.exitCode = 1;
    }
};

process.on("SIGINT", () => {
    shutdown().then(() => process.exit(130));
});

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
