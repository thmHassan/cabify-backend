#!/usr/bin/env node

const { spawn } = require("node:child_process");
const fs = require("node:fs/promises");
const os = require("node:os");
const path = require("node:path");

const intEnv = (name, fallback, min = 0) => {
    const value = Number.parseInt(process.env[name], 10);
    return Number.isNaN(value) || value < min ? fallback : value;
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const shards = intEnv("SOCKET_STRESS_SHARDS", 5, 1);
const totalDrivers = intEnv("SOCKET_STRESS_DRIVERS", 5000, 0);
const totalDispatchers = intEnv("SOCKET_STRESS_DISPATCHERS", 20, 0);
const totalCustomers = intEnv("SOCKET_STRESS_CUSTOMERS", 300, 0);
const totalGpsEvents = intEnv("SOCKET_STRESS_GPS_EVENTS", totalDrivers, 0);
const idOffset = intEnv("SOCKET_STRESS_ID_OFFSET", 10_000_000, 0);
const launchDelayMs = intEnv("SOCKET_STRESS_SHARD_LAUNCH_DELAY_MS", 5000, 0);
const readyTimeoutMs = intEnv("SOCKET_STRESS_SHARD_READY_TIMEOUT_MS", 180_000, 1000);
const startWaitMs = intEnv("SOCKET_STRESS_START_WAIT_MS", readyTimeoutMs + (launchDelayMs * shards) + 60_000, 0);
const maxConnectErrors = intEnv("SOCKET_STRESS_MAX_CONNECT_ERRORS", 0, 0);
const workDir = path.resolve(__dirname, "..");
const runDir = path.join(os.tmpdir(), `cabify-socket-stress-${Date.now()}`);

const splitCount = (total, shard) => {
    const base = Math.floor(total / shards);
    const remainder = total % shards;
    return base + (shard < remainder ? 1 : 0);
};

const readJson = async (file) => JSON.parse(await fs.readFile(file, "utf8"));

const waitForReadyFiles = async (readyFiles) => {
    const deadline = Date.now() + readyTimeoutMs;
    const remaining = new Set(readyFiles);

    while (remaining.size > 0 && Date.now() < deadline) {
        for (const file of [...remaining]) {
            try {
                await fs.access(file);
                remaining.delete(file);
            } catch (_) {
                // keep waiting
            }
        }
        if (remaining.size > 0) {
            await sleep(500);
        }
    }

    if (remaining.size > 0) {
        throw new Error(`Timed out waiting for shard ready files: ${[...remaining].join(", ")}`);
    }
};

const tail = (text, lines = 30) => text.trim().split(/\r?\n/).slice(-lines).join("\n");

const main = async () => {
    await fs.mkdir(runDir, { recursive: true });

    const children = [];
    const readyFiles = [];
    const startFile = path.join(runDir, "start");

    console.log(JSON.stringify({
        shards,
        totalDrivers,
        totalDispatchers,
        totalCustomers,
        totalGpsEvents,
        idOffset,
        launchDelayMs,
        readyTimeoutMs,
        startWaitMs,
        runDir,
    }));

    for (let shard = 0; shard < shards; shard += 1) {
        const readyFile = path.join(runDir, `ready-${shard}.json`);
        readyFiles.push(readyFile);

        const env = {
            ...process.env,
            SOCKET_STRESS_HEALTH_URL: process.env.SOCKET_STRESS_HEALTH_URL || "none",
            SOCKET_STRESS_ID_OFFSET: String(idOffset + shard * 100_000),
            SOCKET_STRESS_DRIVERS: String(splitCount(totalDrivers, shard)),
            SOCKET_STRESS_DISPATCHERS: String(splitCount(totalDispatchers, shard)),
            SOCKET_STRESS_CUSTOMERS: String(splitCount(totalCustomers, shard)),
            SOCKET_STRESS_GPS_EVENTS: String(splitCount(totalGpsEvents, shard)),
            SOCKET_STRESS_READY_FILE: readyFile,
            SOCKET_STRESS_START_FILE: startFile,
            SOCKET_STRESS_START_WAIT_MS: String(startWaitMs),
            SOCKET_STRESS_MAX_CONNECT_ERRORS: String(maxConnectErrors),
        };

        const logFile = path.join(runDir, `shard-${shard}.log`);
        const out = await fs.open(logFile, "w");
        const child = spawn(process.execPath, [path.join(__dirname, "socket-scale.js")], {
            cwd: workDir,
            env,
            stdio: ["ignore", out.fd, out.fd],
        });

        children.push({ shard, child, logFile, out });
        console.log(`started shard=${shard} pid=${child.pid}`);
        await sleep(launchDelayMs);
    }

    await waitForReadyFiles(readyFiles);
    const ready = await Promise.all(readyFiles.map(readJson));
    console.log(JSON.stringify({ phase: "ready", ready }, null, 2));

    await fs.writeFile(startFile, new Date().toISOString());
    console.log("start signal written");

    const results = await Promise.all(children.map(({ child }) => new Promise((resolve) => {
        child.on("exit", (code, signal) => resolve({ code, signal }));
    })));

    await Promise.all(children.map(({ out }) => out.close()));

    let failed = false;
    const readyTotals = ready.reduce((totals, item) => ({
        connected: totals.connected + (item.connected || 0),
        connectErrors: totals.connectErrors + (item.connectErrors || 0),
        expected: totals.expected + (item.expected || 0),
    }), { connected: 0, connectErrors: 0, expected: 0 });
    for (const { shard, logFile } of children) {
        const log = await fs.readFile(logFile, "utf8");
        console.log(`[shard:${shard}]`);
        console.log(tail(log, 24));
    }

    results.forEach((result, index) => {
        if (result.code !== 0) {
            failed = true;
            console.error(`shard ${index} failed`, result);
        }
    });

    console.log(JSON.stringify({ results, readyTotals, maxConnectErrors, runDir }, null, 2));

    if (failed) {
        process.exitCode = 1;
    }
};

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
