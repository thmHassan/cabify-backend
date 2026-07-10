const path = require("path");
require("dotenv").config({
    path: path.join(__dirname, "../.env"),
});

const express = require("express");
const cors = require("cors");
const http = require("http");
const { Server } = require("socket.io");
const PDFDocument = require('pdfkit');
const axios = require("axios");
const { getConnection, getPoolStats } = require("./db")
const { createLatestPerKeyCoalescer } = require("./locationPersistCoalescer")
const transporter = require("./utils/Emailconfig");
const { getMailFrom } = require("./utils/Emailconfig");
const { sendToDevice, sendNotificationToDriver, sendNotificationToUser } = require("./utils/FCMService");
const { getBookingConfirmationEmail } = require("./utils/Emailtemplate");
const { PLOT_DISPATCH_ACTIVE_PREFIX } = require("./plotDispatchService");
const { createWaitingQueueService, pointInPolygon } = require("./waitingQueueService");
const {
    normalizeDistanceUnit,
    buildDistanceDisplayFieldsFromKm,
    buildDistanceDisplayFieldsFromMeters,
    driverRankSupported,
} = require("./dispatchPayloadHelpers");

const app = express();

const SOCKET_API_CORS = {
    origin: '*',
    methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Authorization', 'Content-Type', 'database'],
    optionsSuccessStatus: 204,
};

app.use(cors(SOCKET_API_CORS));
app.options(/.*/, cors(SOCKET_API_CORS));

app.use((req, _res, next) => {
    if (typeof req.url === "string" && req.url.startsWith("/socket-api")) {
        req.url = req.url.replace(/^\/socket-api/, '') || "/";
    }
    next();
});

const server = http.createServer(app);
const SOCKET_PING_INTERVAL_MS = Number(process.env.SOCKET_PING_INTERVAL_MS || 25000);
const SOCKET_PING_TIMEOUT_MS = Number(process.env.SOCKET_PING_TIMEOUT_MS || 60000);
const SOCKET_SERVER_PORT = Number(process.env.SOCKET_SERVER_PORT || process.env.PORT || 3001);

const io = new Server(server, {
    pingInterval: SOCKET_PING_INTERVAL_MS,
    pingTimeout: SOCKET_PING_TIMEOUT_MS,
    cors: {
        origin: "*"
    }
});

const driverSockets = new Map();
const userSockets = new Map();
const dispatcherSockets = new Map();
const clientSockets = new Map();
const adminSockets = new Map();

const formatDateInTimezone = (date = new Date(), timeZone = 'UTC') => {
    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });

    const parts = Object.fromEntries(
        formatter
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value])
    );

    return `${parts.year}-${parts.month}-${parts.day}`;
};

const sqlDateLiteral = (dateString) => `'${String(dateString).replace(/[^0-9-]/g, '')}'`;

const getTenantTimezone = async (db) => {
    try {
        const [rows] = await db.query(
            "SELECT company_timezone FROM settings WHERE company_timezone IS NOT NULL AND company_timezone != '' ORDER BY id DESC LIMIT 1"
        );
        return rows?.[0]?.company_timezone || process.env.APP_TIMEZONE || 'UTC';
    } catch (error) {
        console.warn('[Timezone] Could not load tenant timezone:', error.message);
        return process.env.APP_TIMEZONE || 'UTC';
    }
};

const getTenantTodayDate = async (db) => {
    const timezone = await getTenantTimezone(db);
    try {
        return formatDateInTimezone(new Date(), timezone);
    } catch (error) {
        console.warn(`[Timezone] Invalid tenant timezone "${timezone}", falling back to UTC`);
        return formatDateInTimezone(new Date(), 'UTC');
    }
};

const TERMINAL_BOOKING_STATUSES = ['completed', 'no_show', 'cancelled'];
const terminalStatusesSqlList = TERMINAL_BOOKING_STATUSES.map((status) => `'${status}'`).join(', ');
const TODAY_HIDDEN_BOOKING_STATUSES = ['completed', 'cancelled'];
const todayHiddenStatusesSqlList = TODAY_HIDDEN_BOOKING_STATUSES.map((status) => `'${status}'`).join(', ');
const ACTIVE_RIDE_STATUSES = ['pending_acceptance', 'ongoing', 'arrived', 'started'];
const activeRideStatusesSqlList = ACTIVE_RIDE_STATUSES.map((status) => `'${status}'`).join(', ');
const ONGOING_RIDE_STATUSES = ['ongoing', 'started'];
const ongoingRideStatusesSqlList = ONGOING_RIDE_STATUSES.map((status) => `'${status}'`).join(', ');

const nonTerminalBookingCondition = (alias = '') => {
    const column = (name) => (alias ? `${alias}.${name}` : name);

    return `(${column('booking_status')} IS NULL OR ${column('booking_status')} NOT IN (${terminalStatusesSqlList}))`;
};

const todayVisibleBookingCondition = (alias = '') => {
    const column = (name) => (alias ? `${alias}.${name}` : name);

    return `(${column('booking_status')} IS NULL OR ${column('booking_status')} NOT IN (${todayHiddenStatusesSqlList}))`;
};

const todayBookingsCondition = (alias = '', todayExpression = 'CURDATE()') => {
    const column = (name) => (alias ? `${alias}.${name}` : name);

    return `
    DATE(${column('booking_date')}) = ${todayExpression}
    AND ${todayVisibleBookingCondition(alias)}
`;
};

const preBookingsCondition = (alias = '', todayExpression = 'CURDATE()') => {
    const column = (name) => (alias ? `${alias}.${name}` : name);

    return `
    DATE(${column('booking_date')}) > ${todayExpression}
    AND ${nonTerminalBookingCondition(alias)}
`;
};

const recentJobsCondition = (alias = '') => {
    const column = (name) => (alias ? `${alias}.${name}` : name);
    return `${column('updated_at')} >= DATE_SUB(NOW(), INTERVAL 7 DAY)`;
};

const ongoingRideCondition = (alias = '') => {
    const column = (name) => (alias ? `${alias}.${name}` : name);
    return `${column('booking_status')} IN (${ongoingRideStatusesSqlList})`;
};

const isTerminalBookingStatus = (status) =>
    TERMINAL_BOOKING_STATUSES.includes(String(status || '').toLowerCase());

const toDateOnlyString = (value) => {
    if (!value) return null;
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return value.toISOString().split('T')[0];
    }
    const text = String(value);
    const match = text.match(/^(\d{4}-\d{2}-\d{2})/);
    if (match) return match[1];
    const parsed = new Date(text);
    return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString().split('T')[0];
};

const isPreBookingRow = (booking, todayDate = null) => {
    if (!booking || !booking.booking_date || isTerminalBookingStatus(booking.booking_status)) {
        return false;
    }

    const bookingDateStr = toDateOnlyString(booking.booking_date);
    const todayStr = toDateOnlyString(todayDate) || toDateOnlyString(new Date());

    return Boolean(bookingDateStr && todayStr && bookingDateStr > todayStr);
};

const plotDriverQueues = new Map();
const driverLastLocationTime = new Map();
const driverDisconnectTimers = new Map();
const knownDriverRuntimeKeys = new Set();
const DISCONNECT_GRACE_MS = 15 * 60 * 1000;

const LOCATION_TIMEOUT_MS = 15 * 60 * 1000;
const GPS_IDLE_PERSIST_MS = Number(process.env.SOCKET_GPS_IDLE_PERSIST_MS || 30000);
const GPS_ACTIVE_PERSIST_MS = Number(process.env.SOCKET_GPS_ACTIVE_PERSIST_MS || 5000);
const GPS_IDLE_MIN_MOVEMENT_METERS = Number(process.env.SOCKET_GPS_IDLE_MIN_MOVEMENT_METERS || 75);
const GPS_ACTIVE_MIN_MOVEMENT_METERS = Number(process.env.SOCKET_GPS_ACTIVE_MIN_MOVEMENT_METERS || 15);
const GPS_PERSIST_CONCURRENCY = Number(process.env.SOCKET_GPS_PERSIST_CONCURRENCY || 10);
const QUEUE_FULL_BROADCAST_COALESCE_MS = Number(process.env.SOCKET_QUEUE_FULL_BROADCAST_COALESCE_MS || 2000);
const SOCKET_LISTEN_BACKLOG = Number(process.env.SOCKET_LISTEN_BACKLOG || 8192);
const GPS_LIVE_BROADCAST_FLUSH_MS = Number(process.env.SOCKET_GPS_LIVE_BROADCAST_FLUSH_MS || 250);
const driverLocationCache = new Map();
const driverLocationPersistTime = new Map();
const plotPolygonCache = new Map();
const gpsStats = {
    accepted: 0,
    broadcast: 0,
    persisted: 0,
    skippedPersist: 0,
    coalescedPersist: 0,
    pendingPersisted: 0,
    dbErrors: 0,
};
const liveGpsBroadcastStats = {
    scheduled: 0,
    flushed: 0,
    coalesced: 0,
};
const queueBroadcastStats = {
    requested: 0,
    executed: 0,
    coalesced: 0,
    errors: 0,
};
const PLOT_POLYGON_CACHE_MS = Number(process.env.SOCKET_PLOT_POLYGON_CACHE_MS || 60000);

const AUTO_DISPATCH_TIMEOUT_MS = 30000;
const DEFAULT_NEAREST_DISPATCH_TIMEOUT_SECONDS = 30;
const NEAREST_DISPATCH_ACTIVE_PREFIX = 'NEAREST_DISPATCH_ACTIVE|';
const ACTIVE_DISPATCH_HIDE_PREFIXES = [NEAREST_DISPATCH_ACTIVE_PREFIX, PLOT_DISPATCH_ACTIVE_PREFIX];
const activeDispatchHideSql = (alias = '') => {
    const column = alias ? `${alias}.dispatcher_action` : 'dispatcher_action';
    return ACTIVE_DISPATCH_HIDE_PREFIXES
        .map((prefix) => `${column} NOT LIKE '${prefix}%'`)
        .join(' AND ');
};
const DEFAULT_NEAREST_SEARCH_RADIUS_KM = 1;
const autoDispatchSessions = new Map();
const nearestDispatchSessions = new Map();
let autoDispatchOfferToken = 0;

const tenantSocketKey = (database, id) => {
    if (!database || id === undefined || id === null || id === '') return null;
    return `${toTenantSocketName(database)}:${String(id).trim()}`;
};

const toTenantSocketName = (database) => {
    const value = String(database || '').trim();
    return value.replace(/^tenant[_-]?/i, '');
};

const toTenantDbName = (database) => {
    const value = toTenantSocketName(database);
    return value ? `tenant${value}` : null;
};

const resolveTenantDbForRequest = async (req) => {
    const headerDatabase = req.headers['database']
        || req.headers['x-database']
        || req.body?.database
        || req.body?.tenantDb
        || req.query?.database
        || req.query?.tenantDb;

    const candidates = new Set();

    if (headerDatabase) {
        const databaseValue = String(headerDatabase).trim();
        if (databaseValue) {
            const socketName = toTenantSocketName(databaseValue);
            candidates.add(databaseValue);
            candidates.add(socketName);
            candidates.add(`tenant_${socketName}`);
            candidates.add(`tenant${socketName}`);
            candidates.add(req.tenantDb);
            candidates.add(toTenantDbName(socketName));
        }
    }

    if (req.tenantDb) {
        candidates.add(req.tenantDb);
        candidates.add(toTenantDbName(req.tenantDb));
    }

    for (const candidate of candidates) {
        if (!candidate) {
            continue;
        }
        const resolved = await resolveTenantDb(candidate);
        if (resolved) {
            return resolved;
        }
    }

    if (req.tenantDb) {
        const normalized = toTenantSocketName(req.tenantDb);
        if (normalized && normalized !== req.tenantDb) {
            const resolved = await resolveTenantDb(normalized);
            if (resolved) {
                return resolved;
            }
        }
    }

    return null;
};

const tenantDbCache = new Map();

const tenantDbCandidates = (database) => {
    const value = String(database || '').trim();
    if (!value) return [];

    const withoutPrefix = value.replace(/^tenant[_-]?/i, '');
    const candidates = new Set([value]);

    candidates.add(`${withoutPrefix}`);
    if (withoutPrefix) {
        candidates.add(`tenant_${withoutPrefix}`);
        candidates.add(`tenant${withoutPrefix}`);
    }

    const withPrefix = toTenantDbName(withoutPrefix);
    if (withPrefix) {
        candidates.add(withPrefix);
    }

    const normalizedWithoutPrefix = toTenantSocketName(withoutPrefix);
    if (normalizedWithoutPrefix && normalizedWithoutPrefix !== withoutPrefix) {
        candidates.add(`tenant_${normalizedWithoutPrefix}`);
        candidates.add(`tenant${normalizedWithoutPrefix}`);
    }

    return [...candidates]
        .map((item) => String(item || '').trim())
        .filter((item) => item);
};

const schemaExists = async (database) => {
    const db = getConnection();
    const [rows] = await db.query(
        'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
        [database]
    );

    return !!rows && rows.length > 0;
};

const levenshteinDistance = (left, right) => {
    const source = String(left || "");
    const target = String(right || "");
    const sourceLength = source.length;
    const targetLength = target.length;

    if (sourceLength === 0) return targetLength;
    if (targetLength === 0) return sourceLength;

    const matrix = Array.from({ length: sourceLength + 1 }, (_, row) => {
        const rowArray = new Array(targetLength + 1).fill(0);
        rowArray[0] = row;
        return rowArray;
    });

    for (let col = 0; col <= targetLength; col += 1) {
        matrix[0][col] = col;
    }

    for (let i = 1; i <= sourceLength; i += 1) {
        for (let j = 1; j <= targetLength; j += 1) {
            const cost = source[i - 1] === target[j - 1] ? 0 : 1;
            matrix[i][j] = Math.min(
                matrix[i - 1][j] + 1,
                matrix[i][j - 1] + 1,
                matrix[i - 1][j - 1] + cost
            );
        }
    }

    return matrix[sourceLength][targetLength];
};

const resolveTenantDbByHeuristic = async (database) => {
    const rawRequested = String(database || "").trim().toLowerCase();
    const requested = rawRequested.replace(/[^a-z0-9_]/g, "");
    const requestedWithoutTenant = toTenantSocketName(requested);
    if (!requested) return null;

    const centralDb = getConnection();
    const [schemaRows] = await centralDb.query(
        "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE 'tenant%'"
    );

    const requestedParts = requested.split("_");
    const requestedSuffix = requestedParts.length > 1 ? requestedParts[requestedParts.length - 1] : "";
    const requestedStem = requestedParts.length > 1 ? requestedParts.slice(0, -1).join("_") : requested;

    let bestMatch = null;
    let bestScore = Infinity;

    const threshold = Math.max(1, Math.ceil(Math.min(requested.length, 16) * 0.35));

    for (const row of schemaRows) {
        const schemaName = row?.SCHEMA_NAME;
        if (!schemaName) continue;

        const normalizedSchema = String(schemaName).trim().toLowerCase().replace(/[^a-z0-9_]/g, "");
        const normalizedSchemaWithoutTenant = toTenantSocketName(normalizedSchema);
        const schemaParts = normalizedSchemaWithoutTenant.split("_");
        const schemaSuffix = schemaParts.length > 1 ? schemaParts[schemaParts.length - 1] : "";
        const schemaStem = schemaParts.length > 1 ? schemaParts.slice(0, -1).join("_") : normalizedSchemaWithoutTenant;

        const scoreCandidates = [
            levenshteinDistance(requested, normalizedSchema),
            levenshteinDistance(requested, normalizedSchemaWithoutTenant),
            levenshteinDistance(requestedWithoutTenant, normalizedSchema),
            levenshteinDistance(requestedWithoutTenant, normalizedSchemaWithoutTenant),
        ];
        let score = Math.min(...scoreCandidates);

        if (requestedSuffix && schemaSuffix && requestedSuffix === schemaSuffix) {
            score -= 1;
        }

        if (schemaStem.startsWith(requestedStem) || requestedStem.startsWith(schemaStem)) {
            score -= 1;
        }

        if (score < 0) score = 0;

        if (score < bestScore) {
            bestMatch = schemaName;
            bestScore = score;
        }
    }

    if (bestMatch && bestScore <= threshold) {
        console.log(`[resolveTenantDb] Heuristic fallback matched ${database} -> ${bestMatch} (score=${bestScore})`);
        return bestMatch;
    }

    return null;
};

const resolveTenantDb = async (database) => {
    if (!database) return null;

    const key = `tenantDb:${String(database).trim()}`;
    const cached = tenantDbCache.get(key);
    if (cached) return cached;

    const candidates = tenantDbCandidates(database);
    const tenantId = toTenantSocketName(database);

    if (tenantId) {
        try {
            const centralDb = getConnection();
            const [tenantRows] = await centralDb.query(
                'SELECT data FROM tenants WHERE id = ? LIMIT 1',
                [tenantId]
            );
            if (tenantRows && tenantRows.length > 0) {
                const tenantRow = tenantRows[0];
                if (tenantRow?.data) {
                    try {
                        const parsed = typeof tenantRow.data === 'string'
                            ? JSON.parse(tenantRow.data)
                            : tenantRow.data;
                        const dataDb = parsed?.database;
                        if (dataDb) {
                            candidates.push(dataDb);
                        }
                        const tenancyDbName = parsed?.tenancy_db_name;
                        if (tenancyDbName) {
                            candidates.push(tenancyDbName);
                        }
                    } catch (error) {
                        // ignore malformed tenant data payload
                    }
                }
            }
        } catch (error) {
            // If tenant metadata check fails, continue with local candidates
        }
    }

    const uniqueCandidates = [...new Set(candidates.map((item) => String(item || '').trim()).filter(Boolean))];

    for (const candidate of uniqueCandidates) {
        if (await schemaExists(candidate)) {
            tenantDbCache.set(key, candidate);
            return candidate;
        }
    }

    const heuristicDb = await resolveTenantDbByHeuristic(database);
    if (heuristicDb) {
        tenantDbCache.set(key, heuristicDb);
        return heuristicDb;
    }

    tenantDbCache.set(key, null);
    return null;
};

const resolveSocketAndTenantDb = async (database) => {
    if (!database) {
        return {
            requestedDb: null,
            socketDbName: null,
            tenantDbName: null,
            resolved: false,
        };
    }

    const requestedDb = String(database).trim();
    let socketDbName = toTenantSocketName(requestedDb);
    if (socketDbName === requestedDb && /^tenant/i.test(requestedDb)) {
        socketDbName = requestedDb.replace(/^tenant/i, '');
    }
    const tenantDbName = await resolveTenantDb(requestedDb);

    return {
        requestedDb,
        socketDbName,
        tenantDbName: tenantDbName || null,
        resolved: Boolean(tenantDbName),
    };
};

const requestTenantIdentifier = (req) => (
    req.headers?.database
    || req.headers?.['x-database']
    || req.body?.tenantDb
    || req.body?.database
    || req.body?.clientId
    || req.query?.database
    || req.query?.tenantDb
    || req.tenantDb
);

const ensureTenantDbForRequest = async (req, res) => {
    const tenantIdentifier = requestTenantIdentifier(req);
    if (!tenantIdentifier) {
        res.status(400).json({ success: false, message: "Missing database header" });
        return false;
    }

    const resolved = await resolveTenantDb(tenantIdentifier);
    if (!resolved) {
        res.status(400).json({
            success: false,
            message: `Unknown tenant database '${tenantIdentifier}'`,
        });
        return false;
    }

    req.tenantDb = resolved;
    req.tenantDbResolved = true;
    return true;
};

const setTenantSocket = (map, database, id, socketId) => {
    const key = tenantSocketKey(database, id);
    if (key) map.set(key, socketId);
};

const getTenantSocket = (map, database, id) => {
    const key = tenantSocketKey(database, id);
    return key ? map.get(key) : null;
};

const deleteTenantSocket = (map, database, id) => {
    const key = tenantSocketKey(database, id);
    if (key) map.delete(key);
};

const emitTenantDriverOffline = (database, payload) => {
    const dbName = toTenantSocketName(database);
    const eventPayload = { ...payload, database: dbName };
    io.to(`dispatcher_${dbName}`).emit("driver-offline-event", eventPayload);
    io.to(`admin_${dbName}`).emit("driver-offline-event", eventPayload);
    io.to(`client_${dbName}`).emit("driver-offline-event", eventPayload);
};

const emitTenantWaitingDriver = (database, payload) => {
    const dbName = toTenantSocketName(database);
    const eventPayload = { ...payload, database: dbName };
    io.to(`dispatcher_${dbName}`).emit("waiting-driver-event", eventPayload);
    io.to(`admin_${dbName}`).emit("waiting-driver-event", eventPayload);
    io.to(`client_${dbName}`).emit("waiting-driver-event", eventPayload);
};

const emitTenantOnJobDriver = (database, payload) => {
    const dbName = toTenantSocketName(database);
    const eventPayload = { ...payload, database: dbName };
    io.to(`dispatcher_${dbName}`).emit("on-job-driver-event", eventPayload);
    io.to(`admin_${dbName}`).emit("on-job-driver-event", eventPayload);
    io.to(`client_${dbName}`).emit("on-job-driver-event", eventPayload);
};

const emitTenantRooms = (database, event, payload) => {
    if (!database || !event) return;
    const dbName = toTenantSocketName(database);
    const eventPayload = { ...payload, database: dbName };
    io.to(`dispatcher_${dbName}`).emit(event, eventPayload);
    io.to(`admin_${dbName}`).emit(event, eventPayload);
    io.to(`client_${dbName}`).emit(event, eventPayload);
};

const pendingLiveGpsBroadcasts = new Map();
const liveGpsBroadcastTimers = new Map();

const flushLiveGpsBroadcasts = (database) => {
    const dbName = toTenantSocketName(database);
    const pending = pendingLiveGpsBroadcasts.get(dbName);
    liveGpsBroadcastTimers.delete(dbName);

    if (!pending || pending.size === 0) {
        pendingLiveGpsBroadcasts.delete(dbName);
        return;
    }

    pendingLiveGpsBroadcasts.delete(dbName);
    for (const payload of pending.values()) {
        emitTenantRooms(dbName, "driver-location-update", payload);
        liveGpsBroadcastStats.flushed += 1;
    }
};

const scheduleLiveGpsBroadcast = (database, driverId, payload) => {
    const dbName = toTenantSocketName(database);
    if (!dbName || !driverId || !payload) return;

    let pending = pendingLiveGpsBroadcasts.get(dbName);
    if (!pending) {
        pending = new Map();
        pendingLiveGpsBroadcasts.set(dbName, pending);
    }

    const driverKey = String(driverId);
    if (pending.has(driverKey)) {
        liveGpsBroadcastStats.coalesced += 1;
    }
    pending.set(driverKey, payload);
    liveGpsBroadcastStats.scheduled += 1;

    if (!liveGpsBroadcastTimers.has(dbName)) {
        liveGpsBroadcastTimers.set(dbName, setTimeout(() => {
            flushLiveGpsBroadcasts(dbName);
        }, GPS_LIVE_BROADCAST_FLUSH_MS));
    }
};

const normalizeDriverRealtimePayload = (driver, database, overrides = {}) => {
    if (!driver) return null;
    const dbName = toTenantSocketName(database);
    const driverId = driver.id ?? driver.driver_id;
    const driverName = driver.name ?? driver.driver_name ?? driver.driverName;
    const plotId = driver.plot_id ?? driver.plot;

    return {
        ...driver,
        ...overrides,
        is_reconnecting: Object.prototype.hasOwnProperty.call(overrides, "is_reconnecting")
            ? overrides.is_reconnecting
            : false,
        id: driverId,
        driver_id: driverId,
        driverName,
        driver_name: driverName,
        name: driverName,
        phone_no: driver.phone_no ?? driver.driver_phone ?? driver.phone ?? null,
        phone: driver.phone ?? driver.phone_no ?? driver.driver_phone ?? null,
        plate_no: driver.plate_no ?? driver.plate ?? null,
        plate: driver.plate ?? driver.plate_no ?? null,
        assigned_vehicle: driver.assigned_vehicle ?? null,
        vehicle_name: driver.vehicle_name ?? driver.vehicle_type_name ?? null,
        vehicle_type: driver.vehicle_type ?? driver.vehicle_type_service ?? driver.vehicle_type_name ?? null,
        vehicle_service: driver.vehicle_service ?? driver.vehicle_type_service ?? null,
        vehicle_type_name: driver.vehicle_type_name ?? null,
        vehicle_type_service: driver.vehicle_type_service ?? null,
        plot: plotId,
        plot_id: plotId,
        plot_name: driver.plot_name || (plotId ? `Plot #${plotId}` : 'N/A'),
        database: dbName,
    };
};

const parseCoordinate = (value) => {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const normalizeGeoCoordinatePoint = (point) => {
    if (!Array.isArray(point) || point.length < 2) return null;

    const lng = parseCoordinate(point[0]);
    const lat = parseCoordinate(point[1]);
    const isValidLngLat = (valueLng, valueLat) => (
        Number.isFinite(valueLng)
        && Number.isFinite(valueLat)
        && Math.abs(valueLng) <= 180
        && Math.abs(valueLat) <= 90
    );

    if (isValidLngLat(lng, lat)) {
        return [lng, lat];
    }

    if (isValidLngLat(lat, lng)) {
        return [lat, lng];
    }

    return null;
};

const parsePossibleJsonString = (value) => {
    let parsed = value;
    let rounds = 0;
    while (typeof parsed === 'string' && rounds < 3) {
        try {
            const nextValue = JSON.parse(parsed.trim());
            if (nextValue === parsed) {
                return parsed;
            }
            parsed = nextValue;
            rounds += 1;
        } catch (error) {
            return parsed;
        }
    }

    return parsed;
};

const extractCoordinatePair = (payload = {}) => {
    const latitude = parseCoordinate(payload?.latitude ?? payload?.lat ?? payload?.latitudeRaw ?? payload?.y);
    const longitude = parseCoordinate(payload?.longitude ?? payload?.lng ?? payload?.lon ?? payload?.x);

    return { latitude, longitude };
};

const isValidCoordinatePair = (latitude, longitude) => (
    latitude !== null
    && longitude !== null
    && latitude >= -90
    && latitude <= 90
    && longitude >= -180
    && longitude <= 180
    && !(latitude === 0 && longitude === 0)
);

const distanceMeters = (from, to) => {
    if (!from || !to) return Number.POSITIVE_INFINITY;
    const toRadians = (degrees) => degrees * Math.PI / 180;
    const earthRadiusMeters = 6371000;
    const dLat = toRadians(to.latitude - from.latitude);
    const dLng = toRadians(to.longitude - from.longitude);
    const lat1 = toRadians(from.latitude);
    const lat2 = toRadians(to.latitude);
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
    return 2 * earthRadiusMeters * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
};

const isActiveRideStatus = (status) => {
    const normalized = String(status || '').toLowerCase();
    return ['busy', 'ongoing', 'arrived', 'started', 'pending_acceptance'].includes(normalized);
};

const shouldPersistDriverLocation = ({ runtimeKey, current, status, onlineStatus, force = false }) => {
    if (force) return true;

    const cached = runtimeKey ? driverLocationCache.get(runtimeKey) : null;
    if (!cached) return true;

    const statusChanged = status
        && String(status) !== String(cached.driving_status || cached.status || '');
    const onlineStatusChanged = onlineStatus
        && String(onlineStatus) !== String(cached.online_status || '');
    if (statusChanged || onlineStatusChanged) return true;

    const active = isActiveRideStatus(status || cached?.driving_status || cached?.status);
    const minInterval = active ? GPS_ACTIVE_PERSIST_MS : GPS_IDLE_PERSIST_MS;
    const minMovement = active ? GPS_ACTIVE_MIN_MOVEMENT_METERS : GPS_IDLE_MIN_MOVEMENT_METERS;
    const lastPersistAt = runtimeKey ? (driverLocationPersistTime.get(runtimeKey) || 0) : 0;
    const movedMeters = distanceMeters(cached, current);

    if (!lastPersistAt) return true;
    if (Date.now() - lastPersistAt >= minInterval) return true;
    return movedMeters >= minMovement;
};

const parsePlotPolygonForLocation = (plot) => {
    if (!plot?.features) return null;
    try {
        const featurePayload = parsePossibleJsonString(plot.features);
        const geometryPayload = featurePayload?.features?.[0]?.geometry
            ? featurePayload.features[0]
            : featurePayload;
        const geometry = geometryPayload?.geometry ?? geometryPayload;

        if (!geometry) {
            return null;
        }

        let rawCoords = geometry.coordinates;
        if (typeof rawCoords === 'string') {
            rawCoords = JSON.parse(rawCoords);
        }

        if (!Array.isArray(rawCoords)) {
            return null;
        }

        let polygon = rawCoords;
        if (Array.isArray(rawCoords?.[0]) && Array.isArray(rawCoords[0]?.[0])) {
            polygon = Array.isArray(rawCoords[0][0]?.[0]) ? rawCoords[0][0] : rawCoords[0];
        }
        if (!Array.isArray(polygon) || polygon.length < 3) return null;

        const normalizedPolygon = polygon
            .map((point) => normalizeGeoCoordinatePoint(point))
            .filter(Boolean);

        if (!normalizedPolygon.length || normalizedPolygon.length < 3) {
            return null;
        }

        if (normalizedPolygon.length !== polygon.length) {
            console.warn('[LocationUpdate] Plot polygon had invalid coordinate(s); ignored invalid points', {
                plotId: plot.id,
                providedPoints: polygon.length,
                normalizedPoints: normalizedPolygon.length,
            });
        }

        return normalizedPolygon;
    } catch (error) {
        console.error('[LocationUpdate] Failed to parse plot polygon:', error.message);
    }
    return null;
};

const getCachedPlotPolygons = async (db, database) => {
    const cacheKey = toTenantSocketName(database);
    const cached = plotPolygonCache.get(cacheKey);
    if (cached && Date.now() - cached.loadedAt < PLOT_POLYGON_CACHE_MS) {
        return cached.plots;
    }

    const [plotRows] = await db.query('SELECT id, features FROM plots ORDER BY id DESC');
    const plots = plotRows
        .map((plot) => ({
            id: plot.id,
            polygon: parsePlotPolygonForLocation(plot),
        }))
        .filter((plot) => plot.polygon);

    plotPolygonCache.set(cacheKey, { loadedAt: Date.now(), plots });
    return plots;
};

const resolvePlotIdForLocation = async (db, database, latitude, longitude) => {
    const plots = await getCachedPlotPolygons(db, database);
    for (const plot of plots) {
        if (pointInPolygon(latitude, longitude, plot.polygon)) {
            return plot.id;
        }
    }
    return null;
};

const cacheDriverLocationSnapshot = ({
    runtimeKey,
    cachedDriver,
    driverId,
    latitude,
    longitude,
    status,
    onlineStatus,
}) => {
    if (!runtimeKey) return;
    driverLocationCache.set(runtimeKey, {
        ...(cachedDriver || {}),
        id: driverId,
        driver_id: driverId,
        latitude,
        longitude,
        driving_status: status || cachedDriver?.driving_status || 'idle',
        online_status: onlineStatus || cachedDriver?.online_status || 'online',
    });
};

const persistDriverLocationSnapshot = async ({
    dbName,
    socketDbName,
    driverId,
    latitude,
    longitude,
    status,
    onlineStatus,
    runtimeKey,
    cachedDriver,
    socket,
}) => {
    try {
        const db = getConnection(dbName);
        const effectiveSocketDb = socketDbName || toTenantSocketName(dbName);
        const plotId = await resolvePlotIdForLocation(db, effectiveSocketDb, latitude, longitude);
        if (plotId) {
            console.log(`[LocationUpdate] plot resolved`, {
                db: dbName,
                socketDb: effectiveSocketDb,
                driverId,
                latitude,
                longitude,
                plotId,
            });
        }
        const updates = ['latitude = ?', 'longitude = ?', 'updated_at = NOW()'];
        const params = [latitude, longitude];

        if (status) {
            updates.push('driving_status = ?');
            params.push(status);
        }
        if (onlineStatus) {
            updates.push('online_status = ?');
            params.push(onlineStatus);
        }
        if (plotId) {
            updates.push('plot_id = ?');
            params.push(plotId);
        }

        params.push(driverId);
        await db.query(
            `UPDATE drivers SET ${updates.join(', ')} WHERE id = ?`,
            params
        );

        if (runtimeKey) {
            driverLocationPersistTime.set(runtimeKey, Date.now());
        }

        const [dbDriverRows] = await db.query(
            `SELECT d.id, d.name, d.phone_no, d.driving_status, d.online_status, d.plot_id, d.latitude, d.longitude,
                    d.assigned_vehicle, d.vehicle_name, d.vehicle_type, d.vehicle_service, d.plate_no,
                    p.name AS plot_name, vt.vehicle_type_name, vt.vehicle_type_service
             FROM drivers d
             LEFT JOIN plots p ON d.plot_id = p.id
             LEFT JOIN vehicle_types vt ON vt.id = d.assigned_vehicle
             WHERE d.id = ? LIMIT 1`,
            [driverId]
        );

        if (dbDriverRows.length > 0) {
            const dbDriver = dbDriverRows[0];
            const previousPlotId = cachedDriver?.plot_id;
            const plotChanged = cachedDriver && String(previousPlotId ?? '') !== String(dbDriver.plot_id ?? '');
            if (runtimeKey) {
                driverLocationCache.set(runtimeKey, {
                    ...dbDriver,
                    latitude,
                    longitude,
                });
            }

            emitTenantRooms(effectiveSocketDb, "driver-location-update", normalizeDriverRealtimePayload(dbDriver, effectiveSocketDb, {
                latitude,
                longitude,
                status: dbDriver.driving_status || "idle",
            }));

            const isIdleAndOnline = dbDriver.driving_status === "idle" && dbDriver.online_status === "online";
            if (plotChanged && isIdleAndOnline) {
                await removeFromQueue(dbDriver.id, dbName);
                const rank = dbDriver.plot_id ? await getOrAssignRankForDriver(dbDriver.plot_id, dbName, dbDriver.id) : "-";
                const eventData = normalizeDriverRealtimePayload(dbDriver, dbName, {
                    rank,
                    status: dbDriver.driving_status,
                    latitude,
                    longitude,
                    online_status: dbDriver.online_status,
                    is_reconnecting: false,
                });
                emitTenantWaitingDriver(dbName, eventData);
                socket?.emit("waiting-driver-event", eventData);
            } else if (isActiveRideStatus(dbDriver.driving_status)) {
                emitTenantOnJobDriver(dbName, normalizeDriverRealtimePayload(dbDriver, dbName, {
                    rank: null,
                    status: dbDriver.driving_status,
                    latitude,
                    longitude,
                    online_status: dbDriver.online_status,
                }));
            }
        }
        gpsStats.persisted += 1;
    } catch (dbErr) {
        gpsStats.dbErrors += 1;
        console.error("Driver location DB persist error:", dbErr.message);
    }
};

const driverLocationPersistCoalescer = createLatestPerKeyCoalescer({
    persist: persistDriverLocationSnapshot,
    maxConcurrent: GPS_PERSIST_CONCURRENCY,
    onCoalesced: () => {
        gpsStats.coalescedPersist += 1;
    },
    onPendingPersisted: () => {
        gpsStats.pendingPersisted += 1;
    },
    onError: (error) => {
        gpsStats.dbErrors += 1;
        console.error("Driver location persist scheduler error:", error.message);
    },
});

const scheduleDriverLocationPersist = (snapshot) => {
    driverLocationPersistCoalescer.schedule(snapshot.runtimeKey, snapshot);
};

const clearAutoDispatchSession = (bookingIdInt) => {
    const key = String(bookingIdInt);
    const session = autoDispatchSessions.get(key);
    if (session?.timeoutId) {
        clearTimeout(session.timeoutId);
    }
    autoDispatchSessions.delete(key);
};

const clearNearestDispatchSession = (bookingIdInt) => {
    const key = String(bookingIdInt);
    const session = nearestDispatchSessions.get(key);
    if (session?.timeoutId) {
        clearTimeout(session.timeoutId);
    }
    nearestDispatchSessions.delete(key);
};

const getNearestSearchRadiusKm = async (db) => {
    let searchRadius = DEFAULT_NEAREST_SEARCH_RADIUS_KM;

    try {
        const [settingsRows] = await db.query(
            'SELECT search_radius FROM settings ORDER BY id DESC LIMIT 1'
        );
        if (settingsRows.length && settingsRows[0].search_radius) {
            const radius = parseFloat(settingsRows[0].search_radius);
            if (!Number.isNaN(radius) && radius > 0) {
                searchRadius = radius;
            }
        }
    } catch (e) {
        console.error(`[NearestDispatch] Settings fetch error (using default ${searchRadius}km):`, e.message);
    }

    return searchRadius;
};

const getNearestDispatchTimeoutMs = async (db) => {
    try {
        const [settingsRows] = await db.query(
            'SELECT dispatch_timeout FROM settings ORDER BY id DESC LIMIT 1'
        );
        if (settingsRows.length && settingsRows[0].dispatch_timeout) {
            const seconds = parseInt(settingsRows[0].dispatch_timeout, 10);
            if (!Number.isNaN(seconds) && seconds > 0) {
                return seconds * 1000;
            }
        }
    } catch (e) {
        console.error('[NearestDispatch] Timeout setting fetch error:', e.message);
    }

    return DEFAULT_NEAREST_DISPATCH_TIMEOUT_SECONDS * 1000;
};

const isTruthyDbValue = (value) => (
    value === true
    || value === 1
    || value === '1'
    || String(value || '').toLowerCase() === 'yes'
    || String(value || '').toLowerCase() === 'true'
);

const getTenantDistanceUnit = async (dbName) => {
    try {
        const centralDb = getConnection();
        const [rows] = await centralDb.query('SELECT data FROM tenants WHERE id = ? LIMIT 1', [dbName]);
        const rawData = rows?.[0]?.data;
        const data = typeof rawData === 'string' ? JSON.parse(rawData || '{}') : (rawData || {});
        return normalizeDistanceUnit(data?.units);
    } catch (error) {
        console.warn('[Distance] Unable to resolve tenant unit, using km:', error.message);
        return 'km';
    }
};

const attachBookingDistanceDisplay = async (dbName, booking) => {
    if (!booking || typeof booking !== 'object') return booking;
    const unit = await getTenantDistanceUnit(dbName);
    return {
        ...booking,
        ...buildDistanceDisplayFieldsFromMeters(booking.distance, unit),
    };
};

const nearestDriverBiddingFallbackEnabled = async (db, booking) => {
    if (isTruthyDbValue(booking?.bidding_fallback)) {
        return true;
    }

    try {
        const [rows] = await db.query(
            `SELECT id FROM dispatch_system
             WHERE status = 'enable'
             AND dispatch_system = 'auto_dispatch_nearest_driver'
             AND steps = 'put_in_bidding_panel'
             LIMIT 1`
        );
        return rows.length > 0;
    } catch (error) {
        console.error('[NearestDispatch] Bidding fallback setting lookup error:', error.message);
        return false;
    }
};

const buildActiveRideExclusion = (driverAlias = 'd', currentBookingId = null) => {
    const params = [];
    const bookingClauses = [];
    if (currentBookingId) {
        bookingClauses.push('b.id <> ?');
        params.push(currentBookingId);
    }
    bookingClauses.push(`(b.driver = ${driverAlias}.id OR b.pending_driver_id = ${driverAlias}.id)`);
    bookingClauses.push(`b.booking_status IN (${activeRideStatusesSqlList})`);

    return {
        sql: `
        AND NOT EXISTS (
            SELECT 1
            FROM bookings b
            WHERE ${bookingClauses.join('\n            AND ')}
        )`,
        params,
    };
};

const buildRequestedVehicleFilter = (booking, driverAlias = 'd') => {
    if (!booking?.vehicle) {
        return { sql: '', params: [] };
    }

    return {
        sql: `AND ${driverAlias}.assigned_vehicle = ?`,
        params: [String(booking.vehicle)],
    };
};

const fetchNearbyIdleDrivers = async (db, lat, lng, searchRadius, excludeDriverIds = [], currentBookingId = null, booking = null) => {
    let excludeClause = '';
    let excludeParams = [];
    const activeRideExclusion = buildActiveRideExclusion('d', currentBookingId);
    const vehicleFilter = buildRequestedVehicleFilter(booking, 'd');

    if (excludeDriverIds.length > 0) {
        excludeClause = `AND d.id NOT IN (${excludeDriverIds.map(() => '?').join(',')})`;
        excludeParams = [...excludeDriverIds];
    }

    const [nearestDrivers] = await db.query(`
        SELECT d.*,
            (6371 * acos(
                cos(radians(?)) * cos(radians(d.latitude)) * cos(radians(d.longitude) - radians(?))
                + sin(radians(?)) * sin(radians(d.latitude))
            )) AS distance
        FROM drivers d
        WHERE d.driving_status = 'idle'
        AND d.online_status = 'online'
        AND d.latitude IS NOT NULL
        AND d.longitude IS NOT NULL
        ${activeRideExclusion.sql}
        ${vehicleFilter.sql}
        ${excludeClause}
        HAVING distance IS NOT NULL AND distance <= ?
        ORDER BY distance ASC
    `, [
        lat,
        lng,
        lat,
        ...activeRideExclusion.params,
        ...vehicleFilter.params,
        ...excludeParams,
        searchRadius,
    ]);

    return nearestDrivers;
};

const fetchBiddingFallbackDrivers = async (db, booking) => {
    const activeRideExclusion = buildActiveRideExclusion('d', booking?.id);
    const vehicleFilter = buildRequestedVehicleFilter(booking, 'd');

    const [drivers] = await db.query(`
        SELECT d.id
        FROM drivers d
        WHERE d.status = 'accepted'
        AND d.driving_status = 'idle'
        AND d.online_status = 'online'
        ${activeRideExclusion.sql}
        ${vehicleFilter.sql}
        ORDER BY d.id ASC
    `, [
        ...activeRideExclusion.params,
        ...vehicleFilter.params,
    ]);

    return drivers.map((driver) => driver.id);
};

const notifyBiddingFallbackDrivers = async (db, dbName, booking) => {
    const driverIds = await fetchBiddingFallbackDrivers(db, booking);
    const enrichedBooking = await attachBookingDistanceDisplay(dbName, booking);
    const payload = {
        ...enrichedBooking,
        fixed_fare: true,
        assignment_type: 'fixed_fare_bidding',
        booking_system: 'bidding',
        bidding_fallback: 1,
    };

    for (const driverId of driverIds) {
        try {
            await sendNotificationToDriver(
                db,
                driverId,
                'New Ride Available',
                'You have a new ride request',
                { booking_id: String(booking.id), type: 'new_ride' }
            );
        } catch (error) {
            console.error(`[NearestDispatch] Bidding fallback FCM error for driver #${driverId}:`, error.message);
        }

        const socketId = getTenantSocket(driverSockets, dbName, driverId);
        if (socketId) {
            io.to(socketId).emit('new-ride-request', {
                booking_id: booking.id,
                assignment_type: 'fixed_fare_bidding',
                fixed_fare: true,
                bidding_fallback: true,
                message: 'You have a new ride request',
                booking: payload,
                ...buildDistanceDisplayFieldsFromMeters(booking.distance, payload.distance_unit),
            });
        }

        try {
            await db.query(
                'INSERT INTO send_new_rides (booking_id, driver_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                [booking.id, driverId]
            );
        } catch (error) {
            console.error(`[NearestDispatch] Bidding fallback send_new_rides insert error for driver #${driverId}:`, error.message);
        }
    }

    return driverIds.length;
};

const parseServicePlotPolygon = (plot) => {
    if (!plot?.features) return null;

    try {
        const features = typeof plot.features === 'string' ? JSON.parse(plot.features) : plot.features;
        const geometry = features.geometry || features;
        let coordinates = geometry.coordinates;

        if (typeof coordinates === 'string') {
            coordinates = JSON.parse(coordinates);
        }

        if (!Array.isArray(coordinates)) return null;

        return Array.isArray(coordinates[0]?.[0]) ? coordinates[0] : coordinates;
    } catch (error) {
        console.error('[NearestDispatch] Failed to parse service plot:', error.message);
        return null;
    }
};

const findServicePlotForPickup = async (db, lat, lng) => {
    try {
        const [plots] = await db.query('SELECT id, name, features FROM plots ORDER BY id DESC');
        for (const plot of plots) {
            const polygon = parseServicePlotPolygon(plot);
            if (polygon && pointInPolygon(lat, lng, polygon)) {
                return plot;
            }
        }
    } catch (error) {
        console.error('[NearestDispatch] Service plot lookup error:', error.message);
    }

    return null;
};

const notifyNearestDriversRideWithdrawn = (dbName, notifiedDriverIds, acceptedDriverId, bookingIdInt) => {
    notifiedDriverIds.forEach((driverId) => {
        if (String(driverId) === String(acceptedDriverId)) {
            return;
        }

        const driverSocketId = getTenantSocket(driverSockets, dbName, driverId);
        if (driverSocketId) {
            io.to(driverSocketId).emit('ride-no-longer-available', {
                booking_id: bookingIdInt,
                message: 'This ride has been accepted by another driver',
            });
        }
    });
};

const isNearestDispatchActive = (dispatcherAction) => (
    typeof dispatcherAction === 'string'
    && dispatcherAction.startsWith(NEAREST_DISPATCH_ACTIVE_PREFIX)
);

const fallbackToManualDispatch = async ({ bookingIdInt, tenantDb, db, dbName, message: fallbackMessage = null }) => {
    clearAutoDispatchSession(bookingIdInt);
    clearNearestDispatchSession(bookingIdInt);
    if (typeof plotDispatch !== 'undefined') {
        plotDispatch.clearPlotDispatchSession(bookingIdInt);
    }

    const message = fallbackMessage || 'Auto dispatch completed — no driver accepted. Booking is available for manual dispatch.';

    try {
        await db.query(
            `UPDATE bookings SET driver = NULL, booking_status = 'pending', dispatcher_action = ? WHERE id = ?`,
            [message, bookingIdInt]
        );
    } catch (e) {
        console.error(`[AutoDispatch] Manual fallback DB error:`, e.message);
    }

    let updatedBooking = null;
    try {
        const [updatedRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [bookingIdInt]);
        updatedBooking = updatedRows[0];
    } catch (e) {
        console.error(`[AutoDispatch] Manual fallback fetch error:`, e.message);
    }

    if (!updatedBooking || !io) {
        return;
    }

    const payload = {
        booking_id: bookingIdInt,
        message,
        booking: updatedBooking,
        fallback: 'manual_dispatch_only',
    };

    emitTenantRooms(dbName, "notification-ride", updatedBooking);
    emitTenantRooms(dbName, "new-booking-event", updatedBooking);

    io.to(`dispatcher_${dbName}`).emit("manual-dispatch-required", payload);
    io.to(`admin_${dbName}`).emit("manual-dispatch-required", payload);
    io.to(`client_${dbName}`).emit("manual-dispatch-required", payload);

    // Keep legacy event for clients that still listen for it
    io.to(`dispatcher_${dbName}`).emit("auto-dispatch-failed", payload);
    io.to(`admin_${dbName}`).emit("auto-dispatch-failed", payload);
    io.to(`client_${dbName}`).emit("auto-dispatch-failed", payload);

    await broadcastDashboardCardsUpdate(tenantDb);
    await broadcastTodaysBookingsListUpdate(tenantDb, db, dbName, bookingIdInt);

    console.log(`[AutoDispatch] Fallback to manual dispatch for booking #${bookingIdInt}`);
};

const fallbackNearestDispatchToBidding = async ({ bookingIdInt, tenantDb, db, dbName, message }) => {
    clearAutoDispatchSession(bookingIdInt);
    clearNearestDispatchSession(bookingIdInt);

    try {
        await db.query(
            `UPDATE bookings
             SET driver = NULL,
                 pending_driver_id = NULL,
                 booking_status = 'pending',
                 booking_system = 'bidding',
                 bidding_fallback = 1,
                 dispatcher_action = ?
             WHERE id = ?`,
            [message, bookingIdInt]
        );
    } catch (error) {
        console.error('[NearestDispatch] Bidding fallback DB update error:', error.message);
        await fallbackToManualDispatch({ bookingIdInt, tenantDb, db, dbName, message });
        return;
    }

    let updatedBooking = null;
    try {
        const [updatedRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        updatedBooking = updatedRows[0];
    } catch (error) {
        console.error('[NearestDispatch] Bidding fallback fetch error:', error.message);
    }

    if (!updatedBooking || !io) {
        return;
    }

    updatedBooking = await attachBookingDistanceDisplay(dbName, updatedBooking);
    const driverCount = await notifyBiddingFallbackDrivers(db, dbName, updatedBooking);
    const payload = {
        booking_id: bookingIdInt,
        message,
        booking: updatedBooking,
        fallback: 'bidding',
        assignment_type: 'fixed_fare_bidding',
        fixed_fare: true,
        bidding_fallback: true,
        driver_count: driverCount,
    };

    emitTenantRooms(dbName, 'notification-ride', updatedBooking);
    emitTenantRooms(dbName, 'new-booking-event', updatedBooking);
    io.to(`dispatcher_${dbName}`).emit('nearest-dispatch-bidding-fallback', payload);
    io.to(`admin_${dbName}`).emit('nearest-dispatch-bidding-fallback', payload);
    io.to(`client_${dbName}`).emit('nearest-dispatch-bidding-fallback', payload);

    await broadcastDashboardCardsUpdate(tenantDb);
    await broadcastTodaysBookingsListUpdate(tenantDb, db, dbName, bookingIdInt);

    console.log(`[NearestDispatch] Fallback to bidding for booking #${bookingIdInt}; notified ${driverCount} driver(s)`);
};

const fallbackNearestDispatchAfterExhaustion = async ({ bookingIdInt, tenantDb, db, dbName, message }) => {
    let booking = null;
    try {
        const [bookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        booking = bookingRows[0];
    } catch (error) {
        console.error('[NearestDispatch] Exhaustion booking fetch error:', error.message);
    }

    if (booking && (await nearestDriverBiddingFallbackEnabled(db, booking))) {
        await fallbackNearestDispatchToBidding({ bookingIdInt, tenantDb, db, dbName, message });
        return 'bidding';
    }

    await fallbackToManualDispatch({ bookingIdInt, tenantDb, db, dbName, message });
    return 'manual';
};

const advanceAutoDispatchAfterDriverSkip = async ({
    bookingIdInt,
    tenantDb,
    plotIdInt,
    driverIndex,
    visitedPlots,
    drivers,
    allBackupPlots,
    driverId,
    reason,
}) => {
    const db = getConnection(tenantDb);
    const actionMessage = reason === 'reject'
        ? `Auto dispatch is working — driver #${driverId} rejected the ride`
        : `Auto dispatch is working — driver #${driverId} did not respond`;

    try {
        await db.query(
            `UPDATE bookings SET driver = NULL, booking_status = 'pending', dispatcher_action = ? WHERE id = ?`,
            [actionMessage, bookingIdInt]
        );
    } catch (e) {
        console.error(`[AutoDispatch] Skip update error:`, e.message);
        return;
    }

    return autoDispatchRide({
        bookingId: bookingIdInt,
        tenantDb,
        currentPlotId: plotIdInt,
        driverIndex: driverIndex + 1,
        visitedPlots: [...visitedPlots],
        driversInCurrentPlot: drivers,
        allBackupPlots,
    });
};

const handleAutoDispatchReject = async ({ bookingIdInt, tenantDb, driverId }) => {
    const db = getConnection(tenantDb);

    const [rows] = await db.query(
        "SELECT booking_status, driver, pending_driver_id, dispatcher_action FROM bookings WHERE id = ?",
        [bookingIdInt]
    );
    if (!rows.length) {
        return { status: 404, body: { success: false, message: "Booking not found" } };
    }

    const { booking_status, driver, pending_driver_id, dispatcher_action } = rows[0];
    const actionText = String(dispatcher_action || '').toLowerCase();
    const isManualAssignmentReject = (
        actionText.includes('assigned') ||
        actionText.includes('pre-job') ||
        actionText.includes('manual') ||
        actionText.includes('driver selected') ||
        actionText.includes('dispatching now')
    ) && (
        String(driver) === String(driverId) ||
        String(pending_driver_id) === String(driverId)
    ) && ['pending', 'ongoing'].includes(String(booking_status));

    if (plotDispatch.isPlotDispatchActive(dispatcher_action)) {
        return plotDispatch.handlePlotDispatchReject({ bookingIdInt, tenantDb, driverId });
    }

    if (isManualAssignmentReject) {
        const dbName = toTenantSocketName(tenantDb);
        const rejectAction = `Driver #${driverId} cancelled/rejected this assigned job. Please assign another driver or start auto dispatch.`;
        await db.query(
            `UPDATE bookings
             SET driver = NULL,
                 pending_driver_id = NULL,
                 booking_status = 'pending',
                 dispatcher_action = ?
             WHERE id = ?`,
            [rejectAction, bookingIdInt]
        );
        await db.query("UPDATE drivers SET driving_status = 'idle' WHERE id = ?", [driverId]);

        const [updatedRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [bookingIdInt]);
        const updatedBooking = updatedRows[0] || { id: bookingIdInt };
        const rejectEvent = {
            booking_id: bookingIdInt,
            id: bookingIdInt,
            driver_id: driverId,
            database: dbName,
            booking_status: 'pending',
            booking: updatedBooking,
            message: rejectAction,
        };

        emitTenantRooms(dbName, "job-rejected-by-driver", rejectEvent);
        emitTenantRooms(dbName, "job-cancelled-by-driver", rejectEvent);
        emitTenantRooms(dbName, "booking-updated-event", updatedBooking);
        emitTenantRooms(dbName, "notification-ride", updatedBooking);
        await broadcastDashboardCardsUpdate(tenantDb);
        await broadcastTodaysBookingsListUpdate(tenantDb, db, dbName, bookingIdInt);

        return {
            status: 200,
            body: { success: true, booking_status: 'pending', message: rejectAction },
        };
    }

    if (booking_status !== "pending") {
        clearAutoDispatchSession(bookingIdInt);
        plotDispatch.clearPlotDispatchSession(bookingIdInt);
        return {
            status: 200,
            body: { success: true, message: "Booking no longer pending", skipped: true },
        };
    }

    if (String(driver) !== String(driverId)) {
        return {
            status: 400,
            body: { success: false, message: "Driver is not assigned to this booking" },
        };
    }

    const session = autoDispatchSessions.get(String(bookingIdInt));
    if (session && String(session.driverId) === String(driverId)) {
        clearTimeout(session.timeoutId);
        autoDispatchSessions.delete(String(bookingIdInt));

        console.log(`[AutoDispatch] Driver #${driverId} rejected booking #${bookingIdInt} → next driver`);

        const rejectEvent = {
            booking_id: bookingIdInt,
            id: bookingIdInt,
            driver_id: driverId,
            database: toTenantSocketName(tenantDb),
            message: `Driver #${driverId} rejected the ride`,
        };
        emitTenantRooms(tenantDb, "job-rejected-by-driver", rejectEvent);

        await advanceAutoDispatchAfterDriverSkip({
            bookingIdInt,
            tenantDb,
            plotIdInt: session.plotIdInt,
            driverIndex: session.driverIndex,
            visitedPlots: session.visitedPlots,
            drivers: session.drivers,
            allBackupPlots: session.allBackupPlots,
            driverId,
            reason: "reject",
        });

        return {
            status: 200,
            body: { success: true, message: "Reject processed — moving to next driver" },
        };
    }

    console.log(`[AutoDispatch] Reject for #${bookingIdInt} without active session — restarting dispatch`);
    clearAutoDispatchSession(bookingIdInt);
    await db.query(
        `UPDATE bookings SET driver = NULL, booking_status = 'pending', dispatcher_action = ? WHERE id = ?`,
        [`Driver #${driverId} rejected the ride — restarting auto dispatch`, bookingIdInt]
    );

    autoDispatchRide({ bookingId: bookingIdInt, tenantDb });

    return {
        status: 200,
        body: { success: true, message: "Reject processed — auto dispatch restarted", recovered: true },
    };
};

const { createPlotDispatchService } = require("./plotDispatchService");

const waitingQueue = createWaitingQueueService({
    io,
    plotDriverQueues,
    getConnection,
});

const getQueueSnapshot = (plotKey) => plotDriverQueues.get(plotKey) || [];

const notifyPlotQueueChanged = async (database, plotId, bookingId = null) => {
    if (plotId) {
        await waitingQueue.broadcastPlotRankUpdate(database, plotId, bookingId);
        return;
    }

    await waitingQueue.broadcastAllPlotRankUpdates(database, bookingId);
};

const fullQueueBroadcastInFlight = new Set();
const fullQueueBroadcastPending = new Map();
const fullQueueBroadcastTimers = new Map();
const fullQueueLastBroadcastAt = new Map();

const runFullQueueBroadcast = async (database, bookingId = null) => {
    const dbName = toTenantSocketName(database);
    if (!dbName) return null;

    fullQueueBroadcastInFlight.add(dbName);
    queueBroadcastStats.executed += 1;
    try {
        return await waitingQueue.broadcastAllPlotRankUpdates(dbName, bookingId);
    } catch (error) {
        queueBroadcastStats.errors += 1;
        console.error("[WaitingQueue] Full broadcast error:", error.message);
        return null;
    } finally {
        fullQueueBroadcastInFlight.delete(dbName);
        fullQueueLastBroadcastAt.set(dbName, Date.now());

        const pendingBookingId = fullQueueBroadcastPending.get(dbName);
        if (fullQueueBroadcastPending.has(dbName)) {
            fullQueueBroadcastPending.delete(dbName);
            setTimeout(() => {
                runFullQueueBroadcast(dbName, pendingBookingId).catch((error) => {
                    queueBroadcastStats.errors += 1;
                    console.error("[WaitingQueue] Pending full broadcast error:", error.message);
                });
            }, QUEUE_FULL_BROADCAST_COALESCE_MS);
        }
    }
};

const broadcastFullQueueToDrivers = async (database, bookingId = null) => {
    const dbName = toTenantSocketName(database);
    if (!dbName) return null;

    queueBroadcastStats.requested += 1;

    if (fullQueueBroadcastInFlight.has(dbName)) {
        queueBroadcastStats.coalesced += 1;
        fullQueueBroadcastPending.set(dbName, bookingId || fullQueueBroadcastPending.get(dbName) || null);
        return null;
    }

    if (fullQueueBroadcastTimers.has(dbName)) {
        queueBroadcastStats.coalesced += 1;
        fullQueueBroadcastPending.set(dbName, bookingId || fullQueueBroadcastPending.get(dbName) || null);
        return null;
    }

    const lastBroadcastAt = fullQueueLastBroadcastAt.get(dbName) || 0;
    const delayMs = Math.max(0, QUEUE_FULL_BROADCAST_COALESCE_MS - (Date.now() - lastBroadcastAt));
    if (delayMs > 0) {
        queueBroadcastStats.coalesced += 1;
        fullQueueBroadcastPending.set(dbName, bookingId || fullQueueBroadcastPending.get(dbName) || null);
        fullQueueBroadcastTimers.set(dbName, setTimeout(() => {
            fullQueueBroadcastTimers.delete(dbName);
            const pendingBookingId = fullQueueBroadcastPending.get(dbName);
            fullQueueBroadcastPending.delete(dbName);
            runFullQueueBroadcast(dbName, pendingBookingId).catch((error) => {
                queueBroadcastStats.errors += 1;
                console.error("[WaitingQueue] Delayed full broadcast error:", error.message);
            });
        }, delayMs));
        return null;
    }

    return runFullQueueBroadcast(dbName, bookingId);
};

const broadcastUpdatedQueue = (plotId, database, bookingId = null) => {
    notifyPlotQueueChanged(database, plotId, bookingId).catch((err) => {
        console.error("[WaitingQueue] Broadcast error:", err.message);
    });
};

const getOrAssignRankForDriver = async (plotId, database, driverId) => {
    const db = getConnection(toTenantDbName(database));
    const rank = await waitingQueue.getOrAssignRank(db, plotId, database, driverId);
    await waitingQueue.broadcastPlotRankUpdate(database, plotId, null);
    return rank;
};

const removeFromQueue = async (driverId, database, bookingId = null) => {
    const db = getConnection(toTenantDbName(database));
    const changedPlots = await waitingQueue.removeFromQueue(db, driverId, database);

    for (const plotId of changedPlots) {
        await waitingQueue.broadcastPlotRankUpdate(database, plotId, bookingId);
    }

    return changedPlots;
};

const applyDriverRankUpdate = async (database, plotId, driverId, newRank) => {
    return waitingQueue.applyDriverRankUpdate(database, plotId, driverId, newRank);
};

const storeNotification = async (db, { user_type, user_id, title, message }) => {
    try {
        await db.query(
            `INSERT INTO notifications (user_type, user_id, title, message, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'unread', NOW(), NOW())`,
            [user_type, user_id, title, message]
        );
        console.log(`Notification stored → [${user_type} #${user_id}] ${title}`);
    } catch (error) {
        console.error("Failed to store notification:", error.message);
    }
};

const broadcastDashboardCardsUpdate = async (tenantDb) => {
    try {
        const db = getConnection(tenantDb);
        const todayDate = await getTenantTodayDate(db);
        const todaySql = sqlDateLiteral(todayDate);

        const query = `
            SELECT
                COUNT(CASE 
                    WHEN ${todayBookingsCondition('', todaySql)}
                    THEN 1 
                END) AS todays_booking,

                COUNT(CASE 
                    WHEN ${preBookingsCondition('', todaySql)}
                    THEN 1 
                END) AS pre_bookings,

                COUNT(CASE 
                    WHEN booking_status = 'completed' 
                    THEN 1 
                END) AS completed,

                COUNT(CASE 
                    WHEN ${ongoingRideCondition()}
                    THEN 1 
                END) AS ongoing,

                COUNT(CASE 
                    WHEN booking_status = 'no_show'
                    THEN 1 
                END) AS no_show,

                COUNT(CASE 
                    WHEN booking_status = 'cancelled' 
                    THEN 1 
                END) AS cancelled,

                COUNT(CASE 
                    WHEN ${recentJobsCondition()}
                    THEN 1 
                END) AS recent_jobs
            FROM bookings
        `;

        const [[counts]] = await db.query(query);

        const dashboardData = {
            todaysBooking: counts.todays_booking,
            preBookings: counts.pre_bookings,
            recentJobs: counts.recent_jobs,
            ongoing: counts.ongoing,
            completed: counts.completed,
            noShow: counts.no_show,
            cancelled: counts.cancelled
        };

        const dbName = toTenantSocketName(tenantDb);

        console.log("Broadcasting dashboard cards update to company:", dbName);

        io.to(`dispatcher_${dbName}`).emit("dashboard-cards-update", dashboardData);
        io.to(`admin_${dbName}`).emit("dashboard-cards-update", dashboardData);
        io.to(`client_${dbName}`).emit("dashboard-cards-update", dashboardData);

        return dashboardData;
    } catch (error) {
        console.error("Error broadcasting dashboard cards:", error);
        return null;
    }
};

const fetchTodaysBookingsPage = async (db, page = 1, limit = 10) => {
    const pageNum = Math.max(parseInt(page, 10) || 1, 1);
    const limitNum = Math.max(parseInt(limit, 10) || 10, 1);
    const offset = (pageNum - 1) * limitNum;

    const baseQuery = `
        FROM bookings b
        LEFT JOIN drivers d ON b.driver = d.id
        LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
        LEFT JOIN sub_companies sc ON b.sub_company = sc.id
        WHERE ${todayBookingsCondition('b')}
    `;

    const dataQuery = `
        SELECT
            b.*,
            d.id as driver_id, d.name as driver_name, d.email as driver_email,
            d.phone_no as driver_phone, d.profile_image as driver_profile_image,
            vt.id as vehicle_type_id, vt.vehicle_type_name, vt.vehicle_type_service,
            sc.id as sub_company_id, sc.name as sub_company_name, sc.email as sub_company_email
        ${baseQuery}
        ORDER BY b.booking_date DESC, b.id DESC
        LIMIT ? OFFSET ?
    `;

    const [bookings] = await db.query(dataQuery, [limitNum, offset]);

    const formattedBookings = bookings.map((booking) => {
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
                profile_image: driver_profile_image,
            } : null,
            vehicleDetail: vehicle_type_id ? {
                id: vehicle_type_id,
                vehicle_type_name,
                vehicle_type_service,
            } : null,
            subCompanyDetail: sub_company_id ? {
                id: sub_company_id,
                name: sub_company_name,
                email: sub_company_email,
            } : null,
        };
    });

    const countQuery = `SELECT COUNT(*) AS total ${baseQuery}`;
    const [[{ total }]] = await db.query(countQuery);

    return {
        success: true,
        data: formattedBookings,
        pagination: {
            total,
            page: pageNum,
            limit: limitNum,
            total_pages: Math.ceil(total / limitNum),
            hasNext: pageNum * limitNum < total,
            hasPrev: pageNum > 1,
        },
    };
};

const broadcastTodaysBookingsListUpdate = async (tenantDb, db, dbName, highlightBookingId = null) => {
    try {
        const listPayload = await fetchTodaysBookingsPage(db, 1, 10);
        const refreshPayload = {
            filter: 'todays_booking',
            page: 1,
            limit: 10,
            highlight_booking_id: highlightBookingId,
            ...listPayload,
        };

        emitTenantRooms(dbName, 'refresh-bookings-list', refreshPayload);

        console.log(`[AutoDispatch] Broadcast todays_booking list (${listPayload.data.length} rows) for manual fallback`);
        return refreshPayload;
    } catch (error) {
        console.error('[AutoDispatch] todays_booking list broadcast error:', error.message);
        return null;
    }
};

const plotDispatch = createPlotDispatchService({
    io,
    driverSockets,
    dispatcherSockets,
    adminSockets,
    clientSockets,
    getConnection,
    getQueueSnapshot,
    waitingQueue,
    broadcastDashboardCardsUpdate,
    broadcastTodaysBookingsListUpdate,
    sendNotificationToDriver,
});

const autoDispatchRide = async (params) => plotDispatch.startPlotDispatchCycle({
    bookingId: params.bookingId,
    tenantDb: params.tenantDb,
});

const tryNextBackupPlot = async ({ bookingIdInt, tenantDb, db, dbName, plotIdInt, plotIdStr, visitedPlots }) => {
    let backupPlots = [];
    try {
        const [plotRows] = await db.query(
            "SELECT backup_plots FROM plots WHERE id = ? OR id = ?",
            [plotIdStr, plotIdInt]
        );

        const rawBackup = plotRows[0]?.backup_plots;
        if (rawBackup) {
            if (typeof rawBackup === 'string') {
                try { backupPlots = JSON.parse(rawBackup); } catch (e) { backupPlots = []; }
            } else if (Array.isArray(rawBackup)) {
                backupPlots = rawBackup;
            }
        }
        console.log(`[AutoDispatch] Backup plots for plot ${plotIdInt}: ${JSON.stringify(backupPlots)}`);
        console.log(`[AutoDispatch] Already visited: ${JSON.stringify(visitedPlots)}`);
    } catch (e) {
        console.error(`[AutoDispatch] Backup plots DB error:`, e.message);
    }

    // Find first backup plot NOT yet visited
    const nextPlot = backupPlots.find(p => !visitedPlots.includes(String(p)));
    console.log(`[AutoDispatch] Next unvisited backup plot: ${nextPlot ?? 'NONE'}`);

    if (nextPlot) {
        console.log(`[AutoDispatch] → Moving to backup plot ${nextPlot}`);
        return autoDispatchRide({
            bookingId: bookingIdInt,
            tenantDb,
            currentPlotId: nextPlot,
            driverIndex: 0,
            visitedPlots: [...visitedPlots],
            driversInCurrentPlot: null
        });
    }

    // All plots exhausted — fall back to manual dispatch
    console.log(`[AutoDispatch] No drivers anywhere. All visited: ${JSON.stringify(visitedPlots)}`);
    await fallbackToManualDispatch({ bookingIdInt, tenantDb, db, dbName });
};

const advanceNearestDispatchAfterDriverSkip = async ({
    bookingIdInt,
    tenantDb,
    db,
    driverIndex,
    drivers,
    triedDriverIds,
    lat,
    lng,
    searchRadius,
    timeoutMs = null,
    driverId,
    reason,
}) => {
    const timeoutSeconds = Math.round((timeoutMs || DEFAULT_NEAREST_DISPATCH_TIMEOUT_SECONDS * 1000) / 1000);
    const actionMessage = reason === 'reject'
        ? `Nearest dispatch — driver #${driverId} rejected the ride`
        : `Nearest dispatch — driver #${driverId} did not respond within ${timeoutSeconds}s`;

    try {
        await db.query(
            `UPDATE bookings SET driver = NULL, booking_status = 'pending', dispatcher_action = ? WHERE id = ?`,
            [`${NEAREST_DISPATCH_ACTIVE_PREFIX}${actionMessage}`, bookingIdInt]
        );
    } catch (e) {
        console.error('[NearestDispatch] Skip update error:', e.message);
        return;
    }

    return nearestDriverDispatch({
        bookingId: bookingIdInt,
        tenantDb,
        driverIndex: driverIndex + 1,
        triedDriverIds: [...triedDriverIds, driverId],
        cachedDrivers: drivers,
        lat,
        lng,
        searchRadius,
    });
};

const handleNearestDispatchReject = async ({ bookingIdInt, tenantDb, driverId }) => {
    let session = nearestDispatchSessions.get(String(bookingIdInt));
    const db = getConnection(tenantDb);
    const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;

    if (!session) {
        const [rows] = await db.query(
            'SELECT * FROM bookings WHERE id = ?',
            [bookingIdInt]
        );
        if (!rows.length) {
            return { handled: false };
        }

        const { booking_status, driver, dispatcher_action } = rows[0];
        if (booking_status !== 'pending' || !isNearestDispatchActive(dispatcher_action)) {
            return { handled: false };
        }

        const [notifiedRows] = await db.query(
            'SELECT driver_id FROM send_new_rides WHERE booking_id = ?',
            [bookingIdInt]
        );
        const notifiedIds = notifiedRows.map((row) => String(row.driver_id));
        if (!notifiedIds.includes(String(driverId))) {
            return { handled: false };
        }

        if (driver && String(driver) !== String(driverId)) {
            return {
                handled: true,
                status: 400,
                body: { success: false, message: 'Ride already accepted by another driver' },
            };
        }

        const [pickupRows] = await db.query('SELECT pickup_point FROM bookings WHERE id = ?', [bookingIdInt]);
        if (!pickupRows.length || !pickupRows[0].pickup_point || !pickupRows[0].pickup_point.includes(',')) {
            return { handled: false };
        }

        const [latStr, lngStr] = pickupRows[0].pickup_point.split(',');
        const lat = parseFloat(latStr.trim());
        const lng = parseFloat(lngStr.trim());
        const searchRadius = await getNearestSearchRadiusKm(db);
        const drivers = await fetchNearbyIdleDrivers(db, lat, lng, searchRadius, [], bookingIdInt, rows[0]);
        const driverIndex = drivers.findIndex((item) => String(item.id) === String(driverId));

        clearNearestDispatchSession(bookingIdInt);

        const rejectEvent = {
            booking_id: bookingIdInt,
            id: bookingIdInt,
            driver_id: driverId,
            database: dbName,
            message: `Driver #${driverId} rejected the ride`,
        };
        emitTenantRooms(dbName, 'job-rejected-by-driver', rejectEvent);

        if (driverIndex < 0 || driverIndex + 1 >= drivers.length) {
            const message = 'Nearest driver dispatch completed — all nearby drivers exhausted. Booking is available for bidding or manual dispatch.';
            console.log(`[NearestDispatch] Driver #${driverId} rejected booking #${bookingIdInt} → fallback`);
            const fallback = await fallbackNearestDispatchAfterExhaustion({ bookingIdInt, tenantDb, db, dbName, message });
            return {
                handled: true,
                status: 200,
                body: {
                    success: true,
                    message: fallback === 'bidding'
                        ? 'All nearby drivers exhausted — moved to bidding panel'
                        : 'All nearby drivers exhausted — moved to manual dispatch list',
                },
            };
        }

        await advanceNearestDispatchAfterDriverSkip({
            bookingIdInt,
            tenantDb,
            db,
            driverIndex,
            drivers,
            triedDriverIds: drivers.slice(0, driverIndex).map((item) => item.id),
            lat,
            lng,
            searchRadius,
            driverId,
            reason: 'reject',
        });

        return {
            handled: true,
            status: 200,
            body: { success: true, message: 'Reject processed — offering to next nearest driver' },
        };
    }

    if (String(session.currentDriverId) !== String(driverId)) {
        return {
            handled: true,
            status: 400,
            body: { success: false, message: 'Driver is not the current nearest-dispatch assignee' },
        };
    }

    const [rows] = await db.query(
        'SELECT booking_status, driver FROM bookings WHERE id = ?',
        [bookingIdInt]
    );
    if (!rows.length) {
        clearNearestDispatchSession(bookingIdInt);
        return {
            handled: true,
            status: 404,
            body: { success: false, message: 'Booking not found' },
        };
    }

    const { booking_status, driver } = rows[0];

    if (booking_status !== 'pending') {
        clearNearestDispatchSession(bookingIdInt);
        return {
            handled: true,
            status: 200,
            body: { success: true, message: 'Booking no longer pending', skipped: true },
        };
    }

    if (driver && String(driver) !== String(driverId)) {
        return {
            handled: true,
            status: 400,
            body: { success: false, message: 'Ride already accepted by another driver' },
        };
    }

    const rejectEvent = {
        booking_id: bookingIdInt,
        id: bookingIdInt,
        driver_id: driverId,
        database: session.dbName,
        message: `Driver #${driverId} rejected the ride`,
    };
    emitTenantRooms(session.dbName, 'job-rejected-by-driver', rejectEvent);

    const sessionState = {
        tenantDb: session.tenantDb,
        dbName: session.dbName,
        driverIndex: session.driverIndex,
        triedDriverIds: session.triedDriverIds,
        drivers: session.drivers,
        lat: session.lat,
        lng: session.lng,
        searchRadius: session.searchRadius,
    };

    clearNearestDispatchSession(bookingIdInt);

    if (sessionState.driverIndex + 1 >= sessionState.drivers.length) {
        const message = 'Nearest driver dispatch completed — all nearby drivers exhausted. Booking is available for bidding or manual dispatch.';
        console.log(`[NearestDispatch] Driver #${driverId} rejected booking #${bookingIdInt} → fallback`);
        const fallback = await fallbackNearestDispatchAfterExhaustion({
            bookingIdInt,
            tenantDb,
            db,
            dbName: sessionState.dbName,
            message,
        });

        return {
            handled: true,
            status: 200,
            body: {
                success: true,
                message: fallback === 'bidding'
                    ? 'All nearby drivers exhausted — moved to bidding panel'
                    : 'All nearby drivers exhausted — moved to manual dispatch list',
            },
        };
    }

    console.log(`[NearestDispatch] Driver #${driverId} rejected booking #${bookingIdInt} → next nearest driver`);

    await advanceNearestDispatchAfterDriverSkip({
        bookingIdInt,
        tenantDb,
        db,
        driverIndex: sessionState.driverIndex,
        drivers: sessionState.drivers,
        triedDriverIds: sessionState.triedDriverIds,
        lat: sessionState.lat,
        lng: sessionState.lng,
        searchRadius: sessionState.searchRadius,
        driverId,
        reason: 'reject',
    });

    return {
        handled: true,
        status: 200,
        body: { success: true, message: 'Reject processed — offering to next nearest driver' },
    };
};

const nearestDriverDispatch = async ({
    bookingId,
    tenantDb,
    driverIndex = 0,
    triedDriverIds = [],
    cachedDrivers = null,
    lat = null,
    lng = null,
    searchRadius = null,
}) => {
    try {
        const db = getConnection(tenantDb);
        const dbName = tenantDb.startsWith('tenant') ? tenantDb.slice('tenant'.length) : tenantDb;
        const bookingIdInt = parseInt(bookingId, 10);
        const isFreshStart = driverIndex === 0 && triedDriverIds.length === 0 && !cachedDrivers;

        console.log(
            `[NearestDispatch] bookingId=${bookingIdInt} index=${driverIndex} tried=${JSON.stringify(triedDriverIds)}`
        );

        if (isFreshStart) {
            clearNearestDispatchSession(bookingIdInt);
        }

        const [bookingRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        if (!bookingRows.length) {
            console.log('[NearestDispatch] Booking not found');
            return;
        }
        const booking = bookingRows[0];

        if (['cancelled', 'completed'].includes(booking.booking_status)) {
            console.log(`[NearestDispatch] Status=${booking.booking_status}. Stop.`);
            return;
        }
        if (['ongoing', 'started'].includes(booking.booking_status) && booking.driver) {
            console.log(`[NearestDispatch] Already assigned (status=${booking.booking_status}).`);
            return;
        }

        if (isFreshStart && isNearestDispatchActive(booking.dispatcher_action)) {
            console.log(`[NearestDispatch] Dispatch already active for booking #${bookingIdInt}.`);
            return;
        }

        if (!booking.pickup_point || !booking.pickup_point.includes(',')) {
            console.log('[NearestDispatch] No valid pickup_point on booking');
            await fallbackToManualDispatch({
                bookingIdInt,
                tenantDb,
                db,
                dbName,
                message: 'Nearest driver dispatch stopped — pickup coordinates are missing. Booking is available for manual dispatch.',
            });
            return;
        }

        if (lat === null || lng === null) {
            const [latStr, lngStr] = booking.pickup_point.split(',');
            lat = parseFloat(latStr.trim());
            lng = parseFloat(lngStr.trim());
        }

        if (Number.isNaN(lat) || Number.isNaN(lng)) {
            console.log('[NearestDispatch] Invalid pickup coordinates on booking');
            await fallbackToManualDispatch({
                bookingIdInt,
                tenantDb,
                db,
                dbName,
                message: 'Nearest driver dispatch stopped — pickup coordinates are invalid. Booking is available for manual dispatch.',
            });
            return;
        }

        if (isFreshStart) {
            const servicePlot = await findServicePlotForPickup(db, lat, lng);
            if (!servicePlot) {
                console.log('[NearestDispatch] Pickup is outside all service plots → manual dispatch');
                await fallbackToManualDispatch({
                    bookingIdInt,
                    tenantDb,
                    db,
                    dbName,
                    message: 'Nearest driver dispatch stopped — pickup is outside configured service plots. Booking is available for manual dispatch.',
                });
                return;
            }

            if (!booking.pickup_plot_id) {
                try {
                    await db.query('UPDATE bookings SET pickup_plot_id = ? WHERE id = ?', [servicePlot.id, bookingIdInt]);
                    booking.pickup_plot_id = servicePlot.id;
                } catch (error) {
                    console.error('[NearestDispatch] pickup_plot_id update error:', error.message);
                }
            }
        }

        if (searchRadius === null) {
            searchRadius = await getNearestSearchRadiusKm(db);
        }
        const dispatchTimeoutMs = await getNearestDispatchTimeoutMs(db);
        const dispatchTimeoutSeconds = Math.round(dispatchTimeoutMs / 1000);

        let nearestDrivers = cachedDrivers;
        if (!nearestDrivers) {
            nearestDrivers = await fetchNearbyIdleDrivers(
                db,
                lat,
                lng,
                searchRadius,
                triedDriverIds,
                bookingIdInt,
                booking
            );
        }

        console.log(`[NearestDispatch] Pickup: lat=${lat} lng=${lng} radius=${searchRadius}km`);
        console.log(`[NearestDispatch] Eligible drivers within radius: ${nearestDrivers.length}`);
        if (nearestDrivers.length) {
            console.log(
                '[NearestDispatch] Drivers:',
                nearestDrivers.map((d) => `#${d.id} ${d.name} (${Number(d.distance).toFixed(2)}km)`)
            );
        }

        if (!nearestDrivers.length || driverIndex >= nearestDrivers.length) {
            const reason = !nearestDrivers.length
                ? `no idle drivers within ${searchRadius}km`
                : `all ${nearestDrivers.length} nearby driver(s) exhausted`;
            const message = `Nearest driver dispatch completed — ${reason}. Booking is available for bidding or manual dispatch.`;
            console.log(`[NearestDispatch] ${reason} → fallback`);
            await fallbackNearestDispatchAfterExhaustion({
                bookingIdInt,
                tenantDb,
                db,
                dbName,
                message,
            });
            return;
        }

        const driver = nearestDrivers[driverIndex];
        const distanceKm = parseFloat(driver.distance || 0).toFixed(2);
        const distanceUnit = await getTenantDistanceUnit(dbName);
        const distanceDisplay = buildDistanceDisplayFieldsFromKm(distanceKm, distanceUnit);
        const distanceLabel = distanceDisplay.distance_value == null
            ? `${distanceKm}km`
            : `${distanceDisplay.distance_value}${distanceDisplay.distance_unit === 'miles' ? 'mi' : 'km'}`;
        console.log(
            `[NearestDispatch] Offering to driver #${driver.id} "${driver.name}" (${distanceKm}km away, index=${driverIndex})`
        );

        const dispatchAmount = (
            booking.booking_amount === null || booking.booking_amount === undefined || booking.booking_amount == 0
        ) ? (booking.offered_amount ?? null) : booking.booking_amount;

        const actionMessage = `${NEAREST_DISPATCH_ACTIVE_PREFIX}Request sent to driver #${driver.id} (${distanceKm}km away, radius=${searchRadius}km) — waiting up to ${dispatchTimeoutSeconds}s`;

        await db.query(
            `UPDATE bookings SET driver = ?, booking_amount = ?, booking_status = 'pending', dispatcher_action = ? WHERE id = ?`,
            [driver.id, dispatchAmount, actionMessage, bookingIdInt]
        );

        const [updatedRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);
        const updatedBooking = updatedRows[0];

        const driverSocketId = getTenantSocket(driverSockets, dbName, driver.id);
        if (driverSocketId) {
            io.to(driverSocketId).emit('new-ride-request', {
                booking_id: updatedBooking.id,
                assignment_type: 'nearest_dispatch',
                message: `You have a new ride request (${distanceLabel} from your location)`,
                booking: {
                    ...updatedBooking,
                    ...distanceDisplay,
                },
                distance_km: distanceKm,
                ...distanceDisplay,
                search_radius: searchRadius,
                expires_in_seconds: dispatchTimeoutSeconds,
            });
            console.log(`[NearestDispatch] Socket sent to driver #${driver.id}`);
        } else {
            console.log(`[NearestDispatch] Driver #${driver.id} not in socket map — FCM only`);
        }

        try {
            await db.query(
                'INSERT INTO send_new_rides (booking_id, driver_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                [bookingIdInt, driver.id]
            );
        } catch (e) {
            console.error(`[NearestDispatch] send_new_rides insert error for driver #${driver.id}:`, e.message);
        }

        if (isFreshStart) {
            io.to(`dispatcher_${dbName}`).emit('nearest-dispatch-started', {
                booking_id: bookingIdInt,
                driver_count: nearestDrivers.length,
                search_radius: searchRadius,
                expires_in_seconds: dispatchTimeoutSeconds,
                booking: updatedBooking,
            });
        }

        clearNearestDispatchSession(bookingIdInt);
        const offerToken = ++autoDispatchOfferToken;
        const sessionState = {
            offerToken,
            tenantDb,
            dbName,
            lat,
            lng,
            searchRadius,
            dispatchTimeoutMs,
            driverIndex,
            triedDriverIds: [...triedDriverIds],
            drivers: nearestDrivers,
            currentDriverId: driver.id,
        };

        console.log(`[NearestDispatch] ${dispatchTimeoutSeconds}s timeout for driver #${driver.id}`);

        const timeoutId = setTimeout(async () => {
            try {
                const session = nearestDispatchSessions.get(String(bookingIdInt));
                if (!session || session.offerToken !== offerToken) {
                    return;
                }
                nearestDispatchSessions.delete(String(bookingIdInt));

                const [checkRows] = await db.query(
                    'SELECT booking_status, driver, dispatcher_action FROM bookings WHERE id = ?',
                    [bookingIdInt]
                );
                if (!checkRows.length) return;

                const { booking_status: status, driver: assignedDriver, dispatcher_action } = checkRows[0];
                console.log(`[NearestDispatch] Timeout check: status=${status} driver=${assignedDriver}`);

                if (['cancelled', 'completed', 'ongoing', 'started'].includes(status)) {
                    return;
                }
                if (!isNearestDispatchActive(dispatcher_action)) {
                    return;
                }

                if (status === 'pending' && String(assignedDriver) === String(driver.id)) {
                    console.log(`[NearestDispatch] No response from #${driver.id} → next nearest driver`);
                    await advanceNearestDispatchAfterDriverSkip({
                        bookingIdInt,
                        tenantDb,
                        db,
                        driverIndex: sessionState.driverIndex,
                        drivers: sessionState.drivers,
                        triedDriverIds: sessionState.triedDriverIds,
                        lat: sessionState.lat,
                        lng: sessionState.lng,
                        searchRadius: sessionState.searchRadius,
                        timeoutMs: sessionState.dispatchTimeoutMs,
                        driverId: driver.id,
                        reason: 'timeout',
                    });
                }
            } catch (e) {
                console.error('[NearestDispatch] Timeout error:', e.message);
            }
        }, dispatchTimeoutMs);

        nearestDispatchSessions.set(String(bookingIdInt), { ...sessionState, timeoutId });

        sendNotificationToDriver(
            db,
            driver.id,
            'New Ride Nearby',
            `You have a new ride request (${distanceLabel} away)`,
            { booking_id: String(updatedBooking.id), type: 'new_ride' }
        ).catch((e) => {
            console.error(`[NearestDispatch] FCM error for driver #${driver.id}:`, e.message);
        });
    } catch (error) {
        console.error('[NearestDispatch] FATAL:', error.message);
        console.error(error.stack);
    }
};

io.use(async (socket, next) => {
    const databaseQuery = socket.handshake?.query?.database;
    const handshakeRole = socket.handshake?.query?.role;
    const socketId = socket.id || "unknown";
    console.log(`[Socket HANDSHAKE] in progress socket=${socketId} role=${handshakeRole || "unknown"} db=${databaseQuery || "missing_db"} remote=${socket.handshake?.address || "unknown"}`);

    const authHeader = (() => {
        const fromHeader = socket.handshake.headers.authorization;
        if (fromHeader) return fromHeader;

        const auth = socket.handshake.auth || {};
        if (auth.authorization) {
            return auth.authorization.startsWith("Bearer ")
                ? auth.authorization
                : `Bearer ${auth.authorization}`;
        }
        if (auth.token) {
            return auth.token.startsWith("Bearer ")
                ? auth.token
                : `Bearer ${auth.token}`;
        }

        const queryToken = socket.handshake.query?.token;
        if (queryToken) {
            return `Bearer ${queryToken}`;
        }

        return null;
    })();

    const driverId = socket.handshake.query.driver_id;
    const userId = socket.handshake.query.user_id;
    const adminId = socket.handshake.query.admin_id;
    const role = socket.handshake.query.role;
    const dispatcherId = socket.handshake.query.dispatcher_id;
    const clientId = socket.handshake.query.client_id;

    if (!authHeader || (handshakeRole === 'driver' && !driverId) || (handshakeRole === 'admin' && !adminId) ||
        (handshakeRole === 'client' && !clientId) || (handshakeRole === 'dispatcher' && !dispatcherId) ||
        (handshakeRole === 'user' && !userId)) {
        console.log(`[Socket HANDSHAKE] unauthorized socket=${socketId} role=${handshakeRole || "unknown"} db=${databaseQuery || "missing_db"} reason=auth_or_role_missing`);
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

io.engine.on("connection_error", (err) => {
    const req = err.req || {};
    const sid = err.context?.sid || "n/a";
    console.log(`[Socket CONNECT_ERROR] sid=${sid} code=${err.code || "unknown"} message=${err.message || "n/a"} ip=${req.socket?.remoteAddress || "unknown"}`);
});

io.on("connection", (socket) => {
    const role = socket.handshake.query.role;
    const driverId = socket.handshake.query.driver_id;
    const dispatcherId = socket.handshake.query.dispatcher_id;
    const userId = socket.handshake.query.user_id || socket.handshake.query.customer_id;
    const clientId = socket.handshake.query.client_id;
    const adminId = socket.handshake.query.admin_id;
    let database = toTenantSocketName(socket.handshake.query.database);
    if (database === socket.handshake.query.database && /^tenant/i.test(socket.handshake.query.database || '')) {
        database = String(socket.handshake.query.database).replace(/^tenant/i, '');
    }
    const rawDatabase = socket.handshake.query.database;

    const resolveEventDb = async (databaseFromEvent) => {
        const context = await resolveSocketAndTenantDb(databaseFromEvent || rawDatabase);
        if (!context.requestedDb && !rawDatabase && !databaseFromEvent) {
            return {
                requestedDb: null,
                socketDbName: null,
                tenantDbName: null,
                resolved: false,
            };
        }

        return {
            ...context,
            tenantDbName: context.tenantDbName,
            resolved: Boolean(context.tenantDbName),
        };
    };

    const safeSocketLog = (value) => {
        if (value === undefined) return "undefined";
        try {
            return JSON.stringify(value);
        } catch (error) {
            try {
                return String(value);
            } catch {
                return "[unserializable]";
            }
        }
    };

    socket.onAny((eventName, ...args) => {
        const db = socket.handshake.query?.database || "unknown_db";
        const payload = args.map((arg) => safeSocketLog(arg)).join(" | ");
        console.log(`[Socket IN] event=${eventName} socket=${socket.id} db=${db} payload=${payload}`);
    });

    socket.onAnyOutgoing((eventName, ...args) => {
        const db = socket.handshake.query?.database || "unknown_db";
        const payload = args.map((arg) => safeSocketLog(arg)).join(" | ");
        console.log(`[Socket OUT] event=${eventName} socket=${socket.id} db=${db} response=${payload}`);
    });

    if (database) {
        socket.join(database);
        if (role) socket.join(`${role}_${database}`);
        socket.database = database;
        console.log(`Socket connected: Role=${role}, ID=${driverId || dispatcherId || userId || adminId || clientId}, DB=${database}`);
    }

    if (role === "dispatcher" && dispatcherId) setTenantSocket(dispatcherSockets, database, dispatcherId, socket.id);
    if ((role === "user" || role === "customer") && userId) setTenantSocket(userSockets, database, userId, socket.id);
    if (role === "client" && clientId) setTenantSocket(clientSockets, database, clientId, socket.id);
    if (role === "admin" && adminId) setTenantSocket(adminSockets, database, adminId, socket.id);

    if (driverId) {
        setTenantSocket(driverSockets, database, driverId, socket.id);
        const driverRuntimeKey = tenantSocketKey(database, driverId);
        if (driverRuntimeKey) driverLastLocationTime.set(driverRuntimeKey, Date.now());

        (async () => {
            try {
                const dbContext = await resolveEventDb(rawDatabase);
                const tenantDb = dbContext.tenantDbName;
                if (!tenantDb) {
                    console.warn(`[DriverConnect] unknown tenant database for socket db=${database}`);
                    return;
                }

                const db = getConnection(tenantDb);

                const [rows] = await db.query(
                    `SELECT d.id, d.name, d.driving_status, d.online_status, d.plot_id, d.latitude, d.longitude, p.name AS plot_name
                 FROM drivers d
                 LEFT JOIN plots p ON d.plot_id = p.id
                 WHERE d.id = ? LIMIT 1`,
                    [driverId]
                );

                if (!rows.length) return;
                const driver = rows[0];
                if (driverRuntimeKey) {
                    knownDriverRuntimeKeys.add(driverRuntimeKey);
                    driverLocationCache.set(driverRuntimeKey, {
                        ...driver,
                        id: driver.id || driverId,
                        driver_id: driver.id || driverId,
                    });
                }

                console.log(`[Connect] Driver #${driverId} online_status=${driver.online_status} driving_status=${driver.driving_status}`);

                if (driver.driving_status === "idle" && driver.online_status === "online") {
                    const plotId = driver.plot_id;
                    const plotName = driver.plot_name || (plotId ? `Plot #${plotId}` : "N/A");
                    const rank = plotId ? await getOrAssignRankForDriver(plotId, database, driverId) : "-";

                    const emitData = {
                        driver_id: driverId,
                        driver_name: driver.name,
                        driverName: driver.name,
                        plot: plotId ?? "Unassigned",
                        plot_name: plotName,
                        rank: rank,
                        online_status: driver.online_status,
                        is_reconnecting: false,
                        database
                    };

                    emitTenantWaitingDriver(database, emitData);
                    socket.emit("waiting-driver-event", emitData);

                } else {
                    await removeFromQueue(driverId, database);
                    const plotId = driver.plot_id;
                    if (plotId) broadcastUpdatedQueue(plotId, database);

                    const offlineData = {
                        driver_id: driverId,
                        driver_name: driver.name,
                        online_status: driver.online_status,
                        driving_status: driver.driving_status,
                        database
                    };
                    emitTenantDriverOffline(database, offlineData);
                }

            } catch (err) {
                console.error("Driver connect error:", err);
            }
        })();
    }

    socket.on("driver-location", async (data) => {
        try {
            let dataArray = typeof data === "string" ? JSON.parse(data) : data;

            const requestedDb = dataArray.database || socket.handshake.query.database;
            const driverIdFromData = dataArray.id || dataArray.driver_id || socket.driverId;
            const { latitude, longitude } = extractCoordinatePair(dataArray);
            const status = dataArray.driving_status || dataArray.status;
            const onlineStatus = dataArray.online_status;
            const dbContext = await resolveEventDb(requestedDb);
            const dbName = dbContext.socketDbName || requestedDb || socket.database;
            const tenantDbName = dbContext.tenantDbName;
            const runtimeKey = tenantSocketKey(dbName, driverIdFromData);

            if (!dbName || !tenantDbName || !driverIdFromData || !isValidCoordinatePair(latitude, longitude)) {
                if (!tenantDbName) {
                    console.error(`[driver-location] failed to resolve tenant db for ${requestedDb}`);
                }
                return;
            }

            gpsStats.accepted += 1;

            if (runtimeKey) driverLastLocationTime.set(runtimeKey, Date.now());

            const cachedDriver = runtimeKey ? driverLocationCache.get(runtimeKey) : null;
            const livePayload = normalizeDriverRealtimePayload(cachedDriver || {
                id: driverIdFromData,
                driver_id: driverIdFromData,
                driving_status: status || cachedDriver?.driving_status || 'idle',
                online_status: onlineStatus || cachedDriver?.online_status || 'online',
            }, dbName, {
                latitude,
                longitude,
                status: status || cachedDriver?.driving_status || 'idle',
                driving_status: status || cachedDriver?.driving_status || 'idle',
                online_status: onlineStatus || cachedDriver?.online_status || 'online',
            });

            if (livePayload) {
                scheduleLiveGpsBroadcast(dbName, driverIdFromData, livePayload);
                gpsStats.broadcast += 1;
            }

            const shouldPersist = shouldPersistDriverLocation({
                runtimeKey,
                current: { latitude, longitude },
                status,
                onlineStatus,
                force: dataArray.force_persist === true || String(dataArray.force_persist || '').toLowerCase() === 'true'
                    || (!cachedDriver?.plot_id && cachedDriver?.plot_id !== 0),
            });

            if (!shouldPersist) {
                cacheDriverLocationSnapshot({
                    runtimeKey,
                    cachedDriver,
                    driverId: driverIdFromData,
                    latitude,
                    longitude,
                    status,
                    onlineStatus,
                });
                gpsStats.skippedPersist += 1;
                return;
            }

            scheduleDriverLocationPersist({
                dbName: tenantDbName,
                socketDbName: dbName,
                driverId: driverIdFromData,
                latitude,
                longitude,
                status,
                onlineStatus,
                runtimeKey,
                cachedDriver,
                socket,
                force: dataArray.force_persist === true || String(dataArray.force_persist || '').toLowerCase() === 'true',
            });
        } catch (err) {
            console.error("Driver location socket error:", err.message);
        }
    });

    socket.on("driver-status-change", async (data) => {
        try {
            const payload = typeof data === "string" ? JSON.parse(data) : (data || {});
            const requestedDb = payload.database || socket.handshake.query.database;
            const driverIdFromData = payload.driver_id || payload.driverId || payload.id || socket.driverId;
            const onlineStatus = payload.online_status || payload.status;
            const drivingStatus = payload.driving_status;
            const { latitude, longitude } = extractCoordinatePair(payload);

            const dbContext = await resolveEventDb(requestedDb);
            const dbName = dbContext.socketDbName || requestedDb;
            const tenantDbName = dbContext.tenantDbName;
            if (!dbName || !tenantDbName || !driverIdFromData || !onlineStatus) return;

            const db = getConnection(tenantDbName);
            const updates = ['online_status = ?', 'updated_at = NOW()'];
            const params = [onlineStatus];

            if (drivingStatus) {
                updates.unshift('driving_status = ?');
                params.unshift(drivingStatus);
            }
            if (latitude !== null && longitude !== null) {
                updates.unshift('longitude = ?');
                updates.unshift('latitude = ?');
                params.unshift(longitude);
                params.unshift(latitude);
            }

            params.push(driverIdFromData);
            await db.query(`UPDATE drivers SET ${updates.join(', ')} WHERE id = ?`, params);

            await emitDriverStatusForTenant({
                db,
                database: dbName,
                driverId: driverIdFromData,
                reason: 'explicit_status_socket',
            });
            await broadcastDashboardCardsUpdate(tenantDbName);
        } catch (err) {
            console.error("[driver-status-change] Error:", err.message);
        }
    });

    socket.on("get-driver-location", async (data) => {
        try {
            var dataArray;
            if (typeof data === "string") {
                dataArray = JSON.parse(data);
            } else {
                dataArray = data;
            }
            const response = await axios.post(
                "https://backend.cabifyit.com/api/driver/get-location",
                dataArray,
                { headers: { database: `${dataArray.database}` } }
            );

            socket.emit("get-driver-location-on-user", { success: true, data: response.data });
        } catch (err) {
            console.error("Laravel Socket error", err);
        }
    });

    socket.on("update-driver-rank", async (data) => {
        try {
            const role = socket.handshake.query.role;
            if (!["dispatcher", "admin", "client"].includes(role)) {
                socket.emit("update-driver-rank-response", { success: false, message: "Unauthorized" });
                return;
            }

            const dbName = data?.database || socket.handshake.query.database;
            const { driver_id, plot_id, rank } = data || {};

            if (!dbName || !driver_id || !plot_id || rank == null) {
                socket.emit("update-driver-rank-response", { success: false, message: "Missing required fields" });
                return;
            }

            const result = await applyDriverRankUpdate(dbName, plot_id, driver_id, rank);
            socket.emit("update-driver-rank-response", result.success === 1
                ? result
                : { success: 0, message: result.message });
        } catch (err) {
            console.error("[update-driver-rank] Error:", err.message);
            socket.emit("update-driver-rank-response", { success: 0, message: err.message });
        }
    });

    socket.on("get-my-rank", async (data) => {
        try {
            const payload = typeof data === "string"
                ? (() => {
                    try {
                        return JSON.parse(data);
                    } catch {
                        return {};
                    }
                })()
                : (data || {});

            const dbName = payload?.database || socket.handshake.query.database;

            if (!dbName) {
                socket.emit("my-rank-update", { success: false, message: "Missing database" });
                return;
            }

            const tenantDb = await resolveTenantDb(dbName);
            const socketDbName = toTenantSocketName(dbName);

            if (!tenantDb) {
                socket.emit("my-rank-update", {
                    success: false,
                    database: socketDbName,
                    message: `Unknown tenant database '${dbName}'`,
                    attempted_databases: tenantDbCandidates(dbName),
                });
                return;
            }

            const db = getConnection(tenantDb);
            let plotId = payload?.plot_id || null;
            const bookingId = payload?.booking_id || null;
            const driverId = payload?.driver_id || socket.driverId || socket.handshake.query.driver_id || null;
            const supportsRank = await driverRankSupported(db);

            if (!supportsRank) {
                socket.emit("my-rank-update", {
                    success: true,
                    database: socketDbName,
                    supports_rank: false,
                    show_rank: false,
                    rank_available: false,
                    drivers: [],
                    total_idle_drivers: 0,
                });
                return;
            }

            const buildPlotSummary = async (currentPlotId = null) => {
                const [plotRows] = await db.query(
                    `SELECT p.id AS plot_id,
                            p.name AS plot_name,
                            p.features,
                            COUNT(DISTINCT q.driver_id) AS total_drivers
                     FROM plots p
                     LEFT JOIN plot_driver_queues q ON q.plot_id = p.id
                     GROUP BY p.id, p.name
                     ORDER BY p.name ASC, p.id ASC`
                );

                const plots = plotRows.map((plot) => ({
                    plot_id: plot.plot_id,
                    plot_name: plot.plot_name || `Plot #${plot.plot_id}`,
                    features: plot.features,
                    polygon: parsePlotPolygonForLocation(plot),
                    total_drivers: Number(plot.total_drivers || 0),
                    is_current_plot: currentPlotId
                        ? String(plot.plot_id) === String(currentPlotId)
                        : false,
                }));

                const currentPlot = currentPlotId
                    ? plots.find((plot) => String(plot.plot_id) === String(currentPlotId)) || null
                    : null;

                return { plots, currentPlot };
            };

            if (!plotId && driverId) {
                const [driverRows] = await db.query(
                    "SELECT plot_id, latitude, longitude FROM drivers WHERE id = ? LIMIT 1",
                    [driverId]
                );
                plotId = driverRows[0]?.plot_id || null;
                const driverLatitude = parseCoordinate(driverRows[0]?.latitude);
                const driverLongitude = parseCoordinate(driverRows[0]?.longitude);

                if (!plotId) {
                    const runtimeKey = tenantSocketKey(socketDbName, driverId);
                    const cachedDriver = runtimeKey ? driverLocationCache.get(runtimeKey) : null;
                    plotId = cachedDriver?.plot_id || cachedDriver?.plot;

                    if (!plotId && cachedDriver?.latitude !== null && cachedDriver?.longitude !== null) {
                        const lat = parseCoordinate(cachedDriver.latitude);
                        const lng = parseCoordinate(cachedDriver.longitude);
                        const resolvedPlotId = await resolvePlotIdForLocation(db, socketDbName, lat, lng);
                        if (resolvedPlotId) {
                            plotId = resolvedPlotId;
                            if (runtimeKey) {
                                driverLocationCache.set(runtimeKey, {
                                    ...cachedDriver,
                                    plot_id: plotId,
                                });
                            }
                            try {
                                await db.query("UPDATE drivers SET plot_id = ? WHERE id = ?", [plotId, driverId]);
                            } catch (error) {
                                console.error("[get-my-rank] Failed to persist inferred plot_id:", error.message);
                            }
                        }
                    }

                    if (!plotId && driverLatitude !== null && driverLongitude !== null) {
                        const resolvedPlotId = await resolvePlotIdForLocation(db, socketDbName, driverLatitude, driverLongitude);
                        if (resolvedPlotId) {
                            plotId = resolvedPlotId;
                            if (runtimeKey) {
                                driverLocationCache.set(runtimeKey, {
                                    ...cachedDriver,
                                    plot_id: plotId,
                                });
                            }
                            try {
                                await db.query("UPDATE drivers SET plot_id = ? WHERE id = ?", [plotId, driverId]);
                            } catch (error) {
                                console.error("[get-my-rank] Failed to persist inferred plot_id:", error.message);
                            }
                        }
                    }
                }
            }

            if (plotId) {
                const queue = await waitingQueue.loadPlotQueueFromDb(db, plotId, socketDbName);
                const rank = driverId ? await getOrAssignRankForDriver(plotId, socketDbName, driverId) : null;
                const { plots, currentPlot } = await buildPlotSummary(plotId);

                socket.emit("my-rank-update", {
                    success: true,
                    database: socketDbName,
                    plot_id: plotId,
                    booking_id: bookingId,
                    rank,
                    rank_available: rank != null,
                    plots,
                    current_plot: currentPlot,
                    total_drivers: currentPlot?.total_drivers ?? queue.length,
                });
                return;
            }

            const { plots } = await buildPlotSummary(null);

            socket.emit("my-rank-update", {
                success: true,
                database: socketDbName,
                plot_id: null,
                booking_id: bookingId,
                rank: null,
                rank_available: false,
                plots,
                current_plot: null,
                total_drivers: 0,
                message: plots.length ? "No current plot found" : "No plots found",
            });

        } catch (err) {
            console.error("[get-my-rank] Error:", err.message);
            socket.emit("my-rank-update", { success: false, message: err.message });
        }
    });
    socket.on("disconnect", () => {
        const role = socket.handshake.query.role;
        const database = socket.handshake.query.database;
        const dispatcherId = socket.handshake.query.dispatcher_id;
        const userId = socket.handshake.query.user_id;
        const clientId = socket.handshake.query.client_id;
        const adminId = socket.handshake.query.admin_id;

        if (role === "dispatcher" && dispatcherId) {
            deleteTenantSocket(dispatcherSockets, database, dispatcherId);
            console.log(`Dispatcher ${dispatcherId} disconnected`);
        }
        if (role === "user" && userId) deleteTenantSocket(userSockets, database, userId);
        if (role === "client" && clientId) deleteTenantSocket(clientSockets, database, clientId);
        if (role === "admin" && adminId) {
            deleteTenantSocket(adminSockets, database, adminId);
            console.log(`Admin ${adminId} disconnected`);
        }

        if (driverId) {
            deleteTenantSocket(driverSockets, database, driverId);
            const driverRuntimeKey = tenantSocketKey(database, driverId);
            if (driverRuntimeKey) {
                driverLocationCache.delete(driverRuntimeKey);
                driverLocationPersistTime.delete(driverRuntimeKey);
                driverLocationPersistCoalescer.clear(driverRuntimeKey);
            }

            if (driverRuntimeKey && !knownDriverRuntimeKeys.has(driverRuntimeKey)) {
                driverLastLocationTime.delete(driverRuntimeKey);
                if (driverDisconnectTimers.has(driverRuntimeKey)) {
                    clearTimeout(driverDisconnectTimers.get(driverRuntimeKey));
                    driverDisconnectTimers.delete(driverRuntimeKey);
                }
                console.log(`[Disconnect] Driver #${driverId} was not loaded from DB — skipping offline grace timer`);
                return;
            }

            if (driverRuntimeKey && driverDisconnectTimers.has(driverRuntimeKey)) {
                clearTimeout(driverDisconnectTimers.get(driverRuntimeKey));
                driverDisconnectTimers.delete(driverRuntimeKey);
                console.log(`[Disconnect] Driver #${driverId} — cancelled previous grace timer`);
            }

            console.log(`[Disconnect] Driver #${driverId} — starting ${DISCONNECT_GRACE_MS / 60000}min grace period`);

            if (database) {
                (async () => {
                    try {
                        const db = getConnection(toTenantDbName(database));
                        const [rows] = await db.query(
                            "SELECT plot_id, driving_status, online_status FROM drivers WHERE id = ? LIMIT 1",
                            [driverId]
                        );
                        const driver = rows[0];

                        if (driver && driver.driving_status === "idle" && driver.online_status === "online") {
                            await broadcastFullQueueToDrivers(database);
                        } else {
                            await removeFromQueue(driverId, database);
                            const plotId = driver?.plot_id;
                            if (plotId) broadcastUpdatedQueue(plotId, database);
                            await broadcastFullQueueToDrivers(database);
                        }
                    } catch (err) {
                        console.error(`[Disconnect] Grace period init error for #${driverId}:`, err.message);
                    }
                })();
            }

            const timer = setTimeout(async () => {
                if (driverRuntimeKey) driverDisconnectTimers.delete(driverRuntimeKey);
                if (driverRuntimeKey) knownDriverRuntimeKeys.delete(driverRuntimeKey);

                if (getTenantSocket(driverSockets, database, driverId)) {
                    console.log(`[GraceTimer] Driver #${driverId} already reconnected — skip offline`);
                    return;
                }

                console.log(`[GraceTimer] Driver #${driverId} — grace period expired, marking offline`);

                if (driverRuntimeKey) driverLastLocationTime.delete(driverRuntimeKey);

                if (database) {
                    try {
                        const db = getConnection(toTenantDbName(database));

                        await db.query(
                            "UPDATE drivers SET online_status = 'offline' WHERE id = ?",
                            [driverId]
                        );

                        const [rows] = await db.query(
                            "SELECT plot_id FROM drivers WHERE id = ? LIMIT 1",
                            [driverId]
                        );
                        const plotId = rows[0]?.plot_id;

                        await removeFromQueue(driverId, database);
                        if (plotId) broadcastUpdatedQueue(plotId, database);
                        await broadcastFullQueueToDrivers(database);

                        emitTenantDriverOffline(database, {
                            driver_id: driverId,
                            online_status: "offline",
                            reason: "15 min grace period expired"
                        });

                        console.log(`[GraceTimer] Driver #${driverId} → offline, removed from queue`);
                    } catch (err) {
                        console.error(`[GraceTimer] Error for #${driverId}:`, err.message);
                        await removeFromQueue(driverId, database);
                    }
                }
            }, DISCONNECT_GRACE_MS);

            if (driverRuntimeKey) driverDisconnectTimers.set(driverRuntimeKey, timer);
        }
    });
});

app.use((req, res, next) => {
    if (req.url?.startsWith('/socket.io')) {
        console.log(`[HTTP SOCKET.IO] ${req.method} ${req.url} ip=${req.socket?.remoteAddress || "unknown"}`);
    }

    const databaseHeader = req.headers['database'] || req.headers['x-database'];
    if (databaseHeader) {
        return resolveTenantDb(databaseHeader)
            .then((resolved) => {
                req.tenantDb = resolved || toTenantDbName(databaseHeader);
                req.tenantDbFromHeader = true;
                req.tenantDbResolved = !!resolved;
                console.log(`Using database: ${req.tenantDb}`);
                next();
            })
            .catch(() => {
                req.tenantDb = toTenantDbName(databaseHeader);
                req.tenantDbFromHeader = true;
                req.tenantDbResolved = false;
                next();
            });
    }
    next();
});

app.use(express.json());

app.use(async (req, res, next) => {
    if (req.tenantDbFromHeader && req.tenantDb && !req.tenantDbResolved) {
        return res.status(400).json({
            success: false,
            message: `Unknown tenant database '${req.headers['database'] || req.headers['x-database']}'`,
        });
    }

    if (req.tenantDb) {
        return next();
    }

    const explicitTenant = req.body?.tenantDb
        || req.body?.database
        || req.body?.clientId
        || req.query?.database
        || req.query?.tenantDb;

    if (explicitTenant) {
        const resolved = await resolveTenantDb(explicitTenant);
        if (!resolved) {
            return res.status(400).json({ success: false, message: `Unknown tenant database '${explicitTenant}'` });
        }
        req.tenantDb = resolved;
        req.tenantDbResolved = true;
        return next();
    }

    if (req.method !== 'POST' || process.env.SOCKET_ENABLE_TENANT_SCAN_RESOLUTION !== 'true') {
        return next();
    }

    const bookingId = req.body?.booking?.booking_id || req.body?.booking_id;
    if (bookingId) {
        console.warn(`[TenantResolve] Slow tenant scan enabled for booking_id: ${bookingId}`);
        const centralDb = getConnection();
        try {
            const [databases] = await centralDb.query("SHOW DATABASES LIKE 'tenant%'");
            for (const row of databases) {
                const dbName = Object.values(row)[0];
                try {
                    const tenantPool = getConnection(dbName);
                    const [rows] = await tenantPool.query(
                        "SELECT id FROM bookings WHERE booking_id = ? LIMIT 1",
                        [bookingId]
                    );
                    if (rows.length > 0) {
                        req.tenantDb = dbName;
                        console.log(`Successfully resolved to database: ${dbName}`);
                        break;
                    }
                } catch (dbErr) {
                    // Ignore database query errors (e.g., if database is offline or table doesn't exist)
                }
            }
        } catch (err) {
            console.error("Error listing databases:", err.message);
        }
    }
    next();
});

const buildDriverStateSnapshot = async (db, database) => {
    const [rows] = await db.query(
        `SELECT d.id, d.name, d.phone_no, d.driving_status, d.online_status, d.plot_id, d.latitude, d.longitude,
                d.assigned_vehicle, d.vehicle_name, d.vehicle_type, d.vehicle_service, d.plate_no,
                p.name AS plot_name, vt.vehicle_type_name, vt.vehicle_type_service
         FROM drivers d
         LEFT JOIN plots p ON d.plot_id = p.id
         LEFT JOIN vehicle_types vt ON vt.id = d.assigned_vehicle
         WHERE d.online_status = 'online'
         ORDER BY d.plot_id ASC, d.updated_at ASC, d.id ASC`
    );

    const waiting = [];
    const onJob = [];

    for (const driver of rows) {
        const isBusy = String(driver.driving_status || '').toLowerCase() === 'busy';
        const plotId = driver.plot_id;
        const rank = !isBusy && plotId ? await getOrAssignRankForDriver(plotId, database, driver.id) : null;
        const payload = normalizeDriverRealtimePayload(driver, database, {
            rank,
            status: driver.driving_status || 'idle',
            driving_status: driver.driving_status || 'idle',
            online_status: driver.online_status,
            latitude: driver.latitude,
            longitude: driver.longitude,
            is_reconnecting: false,
        });

        if (isBusy) onJob.push(payload);
        else waiting.push(payload);
    }

    return { waiting, onJob };
};

const emitDriverStatusForTenant = async ({ db, database, driverId, reason = 'status_change' }) => {
    const [rows] = await db.query(
        `SELECT d.id, d.name, d.phone_no, d.driving_status, d.online_status, d.plot_id, d.latitude, d.longitude,
                d.assigned_vehicle, d.vehicle_name, d.vehicle_type, d.vehicle_service, d.plate_no,
                p.name AS plot_name, vt.vehicle_type_name, vt.vehicle_type_service
         FROM drivers d
         LEFT JOIN plots p ON d.plot_id = p.id
         LEFT JOIN vehicle_types vt ON vt.id = d.assigned_vehicle
         WHERE d.id = ? LIMIT 1`,
        [driverId]
    );

    if (!rows.length) return null;

    const driver = rows[0];
    const runtimeKey = tenantSocketKey(database, driver.id);
    const plotId = driver.plot_id;
    const onlineStatus = String(driver.online_status || '').toLowerCase();
    const drivingStatus = String(driver.driving_status || 'idle').toLowerCase();

    if (runtimeKey && driverDisconnectTimers.has(runtimeKey)) {
        clearTimeout(driverDisconnectTimers.get(runtimeKey));
        driverDisconnectTimers.delete(runtimeKey);
    }

    if (onlineStatus !== 'online') {
        await removeFromQueue(driver.id, database);
        if (runtimeKey) driverLastLocationTime.delete(runtimeKey);
        if (plotId) broadcastUpdatedQueue(plotId, database);
        await broadcastFullQueueToDrivers(database);
        emitTenantDriverOffline(database, {
            driver_id: driver.id,
            driver_name: driver.name,
            online_status: 'offline',
            driving_status: driver.driving_status,
            reason,
        });
        return { state: 'offline', driver };
    }

    if (runtimeKey) driverLastLocationTime.set(runtimeKey, Date.now());

    if (drivingStatus === 'busy') {
        await removeFromQueue(driver.id, database);
        if (plotId) broadcastUpdatedQueue(plotId, database);
        emitTenantOnJobDriver(database, normalizeDriverRealtimePayload(driver, database, {
            status: driver.driving_status,
            driving_status: driver.driving_status,
            online_status: driver.online_status,
            latitude: driver.latitude,
            longitude: driver.longitude,
        }));
        return { state: 'busy', driver };
    }

    const rank = plotId ? await getOrAssignRankForDriver(plotId, database, driver.id) : '-';
    emitTenantWaitingDriver(database, normalizeDriverRealtimePayload(driver, database, {
        rank,
        status: driver.driving_status || 'idle',
        driving_status: driver.driving_status || 'idle',
        online_status: driver.online_status,
        latitude: driver.latitude,
        longitude: driver.longitude,
        is_reconnecting: false,
    }));
    return { state: 'waiting', driver };
};

app.get("/drivers/state", async (req, res) => {
    try {
        if (!req.tenantDb) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const database = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.tenantDb);
        const db = getConnection(req.tenantDb);
        const snapshot = await buildDriverStateSnapshot(db, database);

        return res.json({ success: true, data: snapshot });
    } catch (error) {
        console.error("Driver state snapshot error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

app.post("/driver-status-change", async (req, res) => {
    try {
        if (!req.tenantDb) {
            const dbHeader = req.headers['database'] || req.headers['x-database'] || req.body?.database;
            if (dbHeader) req.tenantDb = toTenantDbName(dbHeader);
        }

        if (!req.tenantDb) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const database = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.body?.database || req.tenantDb);
        const driverId = req.body?.driver_id || req.body?.driverId || req.body?.id;
        const onlineStatus = req.body?.online_status || req.body?.status;
        const drivingStatus = req.body?.driving_status;
        const { latitude, longitude } = extractCoordinatePair(req.body || {});

        if (!driverId || !onlineStatus) {
            return res.status(400).json({ success: false, message: "Missing driver_id or online_status" });
        }

        const db = getConnection(req.tenantDb);
        const updates = ['online_status = ?', 'updated_at = NOW()'];
        const params = [onlineStatus];

        if (drivingStatus) {
            updates.unshift('driving_status = ?');
            params.unshift(drivingStatus);
        }
        if (latitude !== null && longitude !== null) {
            updates.unshift('longitude = ?');
            updates.unshift('latitude = ?');
            params.unshift(longitude);
            params.unshift(latitude);
        }

        params.push(driverId);
        await db.query(`UPDATE drivers SET ${updates.join(', ')} WHERE id = ?`, params);

        const result = await emitDriverStatusForTenant({
            db,
            database,
            driverId,
            reason: 'explicit_status_change',
        });

        await broadcastDashboardCardsUpdate(req.tenantDb);

        return res.json({ success: true, data: result });
    } catch (error) {
        console.error("Driver status change error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

async function calculatePostPaidEntries(driver, settings, db) {
    const packageDays = parseInt(settings.package_days);
    const packageAmount = parseFloat(settings.package_amount);

    const packageChangedDate = settings.package_updated_at
        ? new Date(settings.package_updated_at)
        : settings.updated_at
            ? new Date(settings.updated_at)
            : new Date(driver.created_at);

    let lastSettlementDate;
    if (driver.last_settlement_date) {
        const settlDate = new Date(driver.last_settlement_date);
        lastSettlementDate =
            settlDate >= packageChangedDate ? settlDate : packageChangedDate;
    } else {
        lastSettlementDate = packageChangedDate;
    }

    const currentDate = new Date();

    const calculationStartDate = new Date(lastSettlementDate);
    calculationStartDate.setDate(calculationStartDate.getDate() + 1);
    calculationStartDate.setHours(0, 0, 0, 0);

    const daysPassed = Math.floor(
        (currentDate - calculationStartDate) / (1000 * 60 * 60 * 24)
    );
    const completedCycles = Math.floor(daysPassed / packageDays);

    const entries = [];

    for (let i = 0; i < completedCycles; i++) {
        const cycleStartDate = new Date(calculationStartDate);
        cycleStartDate.setDate(cycleStartDate.getDate() + i * packageDays);
        const cycleEndDate = new Date(cycleStartDate);
        cycleEndDate.setDate(cycleEndDate.getDate() + packageDays - 1);

        entries.push({
            entry_number: i + 1,
            cycle_start_date: formatDate(cycleStartDate),
            cycle_end_date: formatDate(cycleEndDate),
            days_in_cycle: packageDays,
            amount: packageAmount.toFixed(2),
            status: "pending",
            description: `${packageDays} days package - ${packageAmount} Rs`,
        });
    }

    const currentCycleStart = new Date(calculationStartDate);
    currentCycleStart.setDate(
        currentCycleStart.getDate() + completedCycles * packageDays
    );
    const currentCycleEnd = new Date(currentCycleStart);
    currentCycleEnd.setDate(currentCycleEnd.getDate() + packageDays - 1);

    const daysElapsedInCycle = daysPassed % packageDays;
    const daysRemainingInCycle = packageDays - daysElapsedInCycle;

    entries.push({
        entry_number: completedCycles + 1,
        cycle_start_date: formatDate(currentCycleStart),
        cycle_end_date: formatDate(currentCycleEnd),
        days_in_cycle: packageDays,
        days_elapsed: daysElapsedInCycle,
        days_remaining: daysRemainingInCycle,
        amount: packageAmount.toFixed(2),
        status: "pending",
        description: `Current cycle - ${daysElapsedInCycle} of ${packageDays} days elapsed`,
    });

    return entries;
}

async function calculatePercentageEntries(driver, settings, db) {
    const packageDays = parseInt(settings.package_days);
    const packagePercentage = parseFloat(settings.package_percentage);

    const packageStartDate = settings.package_updated_at
        ? new Date(settings.package_updated_at)
        : settings.updated_at
            ? new Date(settings.updated_at)
            : new Date(driver.created_at);

    let lastSettlementDate;
    if (driver.last_settlement_date) {
        const settlDate = new Date(driver.last_settlement_date);
        lastSettlementDate =
            settlDate >= packageStartDate ? settlDate : packageStartDate;
    } else {
        lastSettlementDate = packageStartDate;
    }

    const currentDate = new Date();

    const calculationStartDate = new Date(lastSettlementDate);
    calculationStartDate.setDate(calculationStartDate.getDate() + 1);
    calculationStartDate.setHours(0, 0, 0, 0);

    const daysPassed = Math.floor(
        (currentDate - calculationStartDate) / (1000 * 60 * 60 * 24)
    );
    const completedCycles = Math.floor(daysPassed / packageDays);

    const entries = [];

    for (let i = 0; i < completedCycles; i++) {
        const cycleStartDate = new Date(calculationStartDate);
        cycleStartDate.setDate(cycleStartDate.getDate() + i * packageDays);

        const cycleEndDate = new Date(cycleStartDate);
        cycleEndDate.setDate(cycleEndDate.getDate() + packageDays - 1);
        cycleEndDate.setHours(23, 59, 59, 999);

        const [bookingRows] = await db.query(
            `SELECT 
                COUNT(*) as total_rides,
                COALESCE(SUM(
                    CASE 
                        WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                        WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                        WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                        ELSE 0
                    END
                ), 0) as total_rides_amount
            FROM bookings
            WHERE driver = ?
            AND booking_status = 'completed'
            AND DATE(booking_date) >= ?
            AND DATE(booking_date) <= ?`,
            [driver.id, formatDate(cycleStartDate), formatDate(cycleEndDate)]
        );

        const totalRidesAmount = parseFloat(
            bookingRows[0]?.total_rides_amount || 0
        );
        const totalRides = parseInt(bookingRows[0]?.total_rides || 0);
        const commissionAmount = (totalRidesAmount * packagePercentage) / 100;

        entries.push({
            entry_number: i + 1,
            cycle_start_date: formatDate(cycleStartDate),
            cycle_end_date: formatDate(cycleEndDate),
            days_in_cycle: packageDays,
            total_rides: totalRides,
            total_rides_amount: totalRidesAmount.toFixed(2),
            commission_percentage: packagePercentage,
            amount: commissionAmount.toFixed(2),
            status: "pending",
            description: `${packagePercentage}% of ${totalRidesAmount.toFixed(2)} Rs rides (${totalRides} rides)`,
        });
    }

    const currentCycleStart = new Date(calculationStartDate);
    currentCycleStart.setDate(
        currentCycleStart.getDate() + completedCycles * packageDays
    );

    const currentCycleEnd = new Date(currentCycleStart);
    currentCycleEnd.setDate(currentCycleEnd.getDate() + packageDays - 1);

    const daysElapsedInCycle = daysPassed % packageDays;
    const daysRemainingInCycle = packageDays - daysElapsedInCycle;

    const [currentBookingRows] = await db.query(
        `SELECT 
            COUNT(*) as total_rides,
            COALESCE(SUM(
                CASE 
                    WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                    WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                    WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                    ELSE 0
                END
            ), 0) as total_rides_amount
        FROM bookings
        WHERE driver = ?
        AND booking_status = 'completed'
        AND DATE(booking_date) >= ?
        AND DATE(booking_date) <= ?`,
        [driver.id, formatDate(currentCycleStart), formatDate(currentCycleEnd)]
    );

    const currentRidesAmount = parseFloat(
        currentBookingRows[0]?.total_rides_amount || 0
    );
    const currentTotalRides = parseInt(currentBookingRows[0]?.total_rides || 0);
    const currentCommission = (currentRidesAmount * packagePercentage) / 100;

    entries.push({
        entry_number: completedCycles + 1,
        cycle_start_date: formatDate(currentCycleStart),
        cycle_end_date: formatDate(currentCycleEnd),
        days_in_cycle: packageDays,
        days_elapsed: daysElapsedInCycle,
        days_remaining: daysRemainingInCycle,
        total_rides: currentTotalRides,
        total_rides_amount: currentRidesAmount.toFixed(2),
        commission_percentage: packagePercentage,
        amount: currentCommission.toFixed(2),
        status: "pending",
        description: `Current cycle - ${packagePercentage}% of ${currentRidesAmount.toFixed(2)} Rs rides (${currentTotalRides} rides)`,
    });

    return entries;
}

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatDateTime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

app.get("/driver/commission-entries", async (req, res) => {
    try {
        const { driver_id, page = 1, limit = 10 } = req.query;

        if (!driver_id) {
            return res
                .status(400)
                .json({ success: 0, message: "Driver ID is required" });
        }

        const databaseHeader =
            req.headers["x-database"] ||
            req.headers["database"] ||
            req.query.database;
        if (!databaseHeader) {
            return res
                .status(400)
                .json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [settingsRows] = await db.query(
            "SELECT * FROM settings ORDER BY id DESC LIMIT 1"
        );
        if (!settingsRows.length) {
            return res
                .status(404)
                .json({ success: 0, message: "Company settings not found" });
        }
        const settings = settingsRows[0];

        const [driverRows] = await db.query(
            "SELECT * FROM drivers WHERE id = ?",
            [driver_id]
        );
        if (!driverRows.length) {
            return res
                .status(404)
                .json({ success: 0, message: "Driver not found" });
        }
        const driver = driverRows[0];

        // Build entries based on package type
        let allEntries = [];
        if (settings.package_type === "packages_post_paid") {
            allEntries = await calculatePostPaidEntries(driver, settings, db);
        } else if (settings.package_type === "commission_without_topup") {
            allEntries = await calculatePercentageEntries(driver, settings, db);
        } else {
            return res
                .status(400)
                .json({ success: 0, message: "Invalid package type" });
        }

        // Filter out already settled entries
        let uncollectedEntries = allEntries;
        if (driver.last_settlement_date) {
            const lastSettlementDate = new Date(driver.last_settlement_date);
            lastSettlementDate.setHours(0, 0, 0, 0);

            uncollectedEntries = allEntries.filter((entry) => {
                const cycleEndDate = new Date(entry.cycle_end_date);
                cycleEndDate.setHours(0, 0, 0, 0);
                return cycleEndDate > lastSettlementDate;
            });
        }
        const markedEntries = uncollectedEntries.map((entry, index) => ({
            ...entry,
            is_collectible: index === 0,
            is_locked: index !== 0,
        }));

        // Pagination
        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const totalEntries = markedEntries.length;
        const totalPages = Math.ceil(totalEntries / limitNum);
        const offset = (pageNum - 1) * limitNum;
        const paginatedEntries = markedEntries.slice(offset, offset + limitNum);

        const pendingEntries = markedEntries.filter(
            (e) => e.status === "pending"
        );

        console.log("Commission Entries Success:", {
            total: totalEntries,
            page: pageNum,
            package_type: settings.package_type,
        });

        return res.json({
            success: 1,
            data: {
                driver_id: driver.id,
                driver_name: driver.name,
                driver_wallet_balance: parseFloat(
                    driver.wallet_balance || 0
                ).toFixed(2),
                package_type: settings.package_type,
                package_days: settings.package_days,
                package_amount: settings.package_amount,
                package_percentage: settings.package_percentage,
                last_settlement_date: driver.last_settlement_date,
                total_uncollected_entries: pendingEntries.length,
                total_uncollected_amount: pendingEntries
                    .reduce((sum, e) => sum + parseFloat(e.amount), 0)
                    .toFixed(2),
                commission_entries: paginatedEntries,
                pagination: {
                    total: totalEntries,
                    page: pageNum,
                    limit: limitNum,
                    total_pages: totalPages,
                    hasNext: pageNum < totalPages,
                    hasPrev: pageNum > 1,
                },
            },
        });
    } catch (error) {
        console.error("Error in commission-entries:", error);
        return res.status(500).json({ success: 0, message: error.message });
    }
});

app.post("/driver/collect-commission", async (req, res) => {
    try {
        const { driver_id } = req.body;

        if (!driver_id) {
            return res
                .status(400)
                .json({ success: 0, message: "Driver ID is required" });
        }

        const databaseHeader =
            req.headers["x-database"] ||
            req.headers["database"] ||
            req.query.database;
        if (!databaseHeader) {
            return res
                .status(400)
                .json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [settingsRows] = await db.query(
            "SELECT * FROM settings ORDER BY id DESC LIMIT 1"
        );
        if (!settingsRows.length) {
            return res
                .status(404)
                .json({ success: 0, message: "Company settings not found" });
        }
        const settings = settingsRows[0];

        const [driverRows] = await db.query(
            "SELECT * FROM drivers WHERE id = ?",
            [driver_id]
        );
        if (!driverRows.length) {
            return res
                .status(404)
                .json({ success: 0, message: "Driver not found" });
        }
        const driver = driverRows[0];

        let commissionEntries = [];
        if (settings.package_type === "packages_post_paid") {
            commissionEntries = await calculatePostPaidEntries(
                driver,
                settings,
                db
            );
        } else if (settings.package_type === "commission_without_topup") {
            commissionEntries = await calculatePercentageEntries(
                driver,
                settings,
                db
            );
        } else {
            return res
                .status(400)
                .json({ success: 0, message: "Invalid package type" });
        }

        let uncollectedEntries = commissionEntries;
        if (driver.last_settlement_date) {
            const lastSettlementDate = new Date(driver.last_settlement_date);
            lastSettlementDate.setHours(0, 0, 0, 0);
            uncollectedEntries = commissionEntries.filter((entry) => {
                const cycleEndDate = new Date(entry.cycle_end_date);
                cycleEndDate.setHours(0, 0, 0, 0);
                return cycleEndDate > lastSettlementDate;
            });
        }

        const pendingEntries = uncollectedEntries.filter(
            (e) => e.status === "pending"
        );

        if (pendingEntries.length === 0) {
            return res.json({
                success: 0,
                message: "No commission entries available to collect",
            });
        }

        const firstEntry = pendingEntries[0];
        const collectionAmount = parseFloat(firstEntry.amount);

        if (collectionAmount <= 0) {
            return res.json({
                success: 0,
                message: "No collectible amount in the first entry",
            });
        }

        const newSettlementDate = new Date(
            firstEntry.cycle_end_date + " 23:59:59"
        );
        const currentDateTime = new Date();

        await db.query(
            `UPDATE drivers SET last_settlement_date = ? WHERE id = ?`,
            [formatDateTime(newSettlementDate), driver_id]
        );

        const transactionComment = `Commission collected - ${firstEntry.description}`;
        await db.query(
            `INSERT INTO wallet_transactions 
             (user_type, user_id, type, comment, created_at, updated_at, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)`,
            [
                "driver",
                driver_id,
                "deduct",
                transactionComment,
                formatDateTime(currentDateTime),
                formatDateTime(currentDateTime),
                collectionAmount,
            ]
        );

        const [updatedDriverRows] = await db.query(
            "SELECT * FROM drivers WHERE id = ?",
            [driver_id]
        );
        const updatedDriver = updatedDriverRows[0];

        let remainingEntries = [];
        if (settings.package_type === "packages_post_paid") {
            remainingEntries = await calculatePostPaidEntries(
                updatedDriver,
                settings,
                db
            );
        } else if (settings.package_type === "commission_without_topup") {
            remainingEntries = await calculatePercentageEntries(
                updatedDriver,
                settings,
                db
            );
        }

        let remainingUncollected = remainingEntries;
        if (updatedDriver.last_settlement_date) {
            const lastDate = new Date(updatedDriver.last_settlement_date);
            lastDate.setHours(0, 0, 0, 0);
            remainingUncollected = remainingEntries.filter((e) => {
                const end = new Date(e.cycle_end_date);
                end.setHours(0, 0, 0, 0);
                return end > lastDate;
            });
        }

        const remainingPending = remainingUncollected.filter(
            (e) => e.status === "pending"
        );

        console.log("✅ Commission Collected:", {
            driver_id,
            package_type: settings.package_type,
            collected_amount: collectionAmount,
            cycle: `${firstEntry.cycle_start_date} → ${firstEntry.cycle_end_date}`,
            remaining_entries: remainingPending.length,
        });

        return res.json({
            success: 1,
            message: "Commission collected successfully",
            data: {
                driver_id: driver.id,
                driver_name: driver.name,
                package_type: settings.package_type,
                collected_entry: {
                    entry_number: firstEntry.entry_number,
                    cycle_start_date: firstEntry.cycle_start_date,
                    cycle_end_date: firstEntry.cycle_end_date,
                    amount: firstEntry.amount,
                    description: firstEntry.description,
                    // % based extra fields
                    ...(settings.package_type === "commission_without_topup" && {
                        total_rides: firstEntry.total_rides,
                        total_rides_amount: firstEntry.total_rides_amount,
                        commission_percentage: firstEntry.commission_percentage,
                    }),
                },
                collected_amount: collectionAmount.toFixed(2),
                previous_settlement_date: driver.last_settlement_date
                    ? formatDate(new Date(driver.last_settlement_date))
                    : "Not Set",
                new_settlement_date: formatDate(newSettlementDate),
                remaining_entries: remainingPending.length,
                remaining_amount: remainingPending
                    .reduce((sum, e) => sum + parseFloat(e.amount), 0)
                    .toFixed(2),
                next_collectible:
                    remainingPending.length > 0 ? remainingPending[0] : null,
                transaction_recorded: true,
            },
        });
    } catch (error) {
        console.error("Error in collect-commission:", error);
        return res.status(500).json({ success: 0, message: error.message });
    }
});

app.get("/bookings/dashboard-cards", async (req, res) => {
    try {
        if (!(await ensureTenantDbForRequest(req, res))) {
            return;
        }

        const db = getConnection(req.tenantDb);
        const todayDate = await getTenantTodayDate(db);
        const todaySql = sqlDateLiteral(todayDate);
        const dispatcherId = req.query.dispatcher_id;
        const scope = String(req.query.scope || "").toLowerCase();
        const shouldFilterDispatcher = scope === "mine" && dispatcherId;
        const dispatcherWhere = shouldFilterDispatcher ? "WHERE dispatcher_id = ?" : "";

        const query = `
            SELECT
                COUNT(CASE 
                    WHEN ${todayBookingsCondition('', todaySql)}
                    THEN 1 
                END) AS todays_booking,

                COUNT(CASE 
                    WHEN ${preBookingsCondition('', todaySql)}
                    THEN 1 
                END) AS pre_bookings,

                COUNT(CASE 
                    WHEN booking_status = 'completed' 
                    THEN 1 
                END) AS completed,

                COUNT(CASE 
                    WHEN ${ongoingRideCondition()}
                    THEN 1 
                END) AS ongoing,

                COUNT(CASE 
                    WHEN booking_status = 'no_show'
                    THEN 1 
                END) AS no_show,

                COUNT(CASE 
                    WHEN booking_status = 'cancelled' 
                    THEN 1 
                END) AS cancelled,

                COUNT(CASE 
                    WHEN ${recentJobsCondition()}
                    THEN 1 
                END) AS recent_jobs
            FROM bookings
            ${dispatcherWhere}
        `;

        const [[counts]] = await db.query(query, shouldFilterDispatcher ? [dispatcherId] : []);

        return res.json({
            success: true,
            data: {
                todaysBooking: counts.todays_booking,
                preBookings: counts.pre_bookings,
                recentJobs: counts.recent_jobs,
                ongoing: counts.ongoing,
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
        if (!(await ensureTenantDbForRequest(req, res))) {
            return;
        }

        let { status, date, user_id, driver_id, dispatcher_id, sub_company, search, filter, scope, page = 1, limit = 10 } = req.query;
        const normalizedStatusList = (rawStatus) => {
            if (!rawStatus || typeof rawStatus !== 'string') return null;
            const values = rawStatus
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);
            return values.length ? values : null;
        };

        const statusList = normalizedStatusList(status);
console.log("Fetching bookings with query:", req.query);
        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const offset = (pageNum - 1) * limitNum;

        const db = getConnection(req.tenantDb);
        const todayDate = await getTenantTodayDate(db);
        const todaySql = sqlDateLiteral(todayDate);

        let baseQuery = `
            FROM bookings b
            LEFT JOIN drivers d ON b.driver = d.id
            LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
            LEFT JOIN sub_companies sc ON b.sub_company = sc.id
            WHERE 1=1
        `;
        const params = [];

        if (filter) {
            switch (filter) {
                case 'todays_booking':
                    baseQuery += ` AND ${todayBookingsCondition('b', todaySql)}`;
                    break;
                case 'pre_bookings':
                    baseQuery += ` AND ${preBookingsCondition('b', todaySql)}`;
                    break;
                case 'pending':
                    baseQuery += ` AND b.booking_status IN ('pending','pending_acceptance')`;
                    break;
                case 'ongoing':
                    baseQuery += ` AND ${ongoingRideCondition('b')}`;
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
                    baseQuery += ` AND ${recentJobsCondition('b')}`;
                    break;
            }
        }

        if (statusList && statusList.length > 0) {
            if (statusList.length === 1) {
                baseQuery += ` AND b.booking_status = ?`;
                params.push(statusList[0]);
            } else {
                const placeholders = statusList.map(() => '?').join(', ');
                baseQuery += ` AND b.booking_status IN (${placeholders})`;
                params.push(...statusList);
            }
        }
        if (date) { baseQuery += ` AND DATE(b.booking_date) = ?`; params.push(date); }
        if (user_id) { baseQuery += ` AND b.user_id = ?`; params.push(user_id); }
        if (driver_id) { baseQuery += ` AND b.driver = ?`; params.push(driver_id); }
        if (dispatcher_id && String(scope || "").toLowerCase() === "mine") { baseQuery += ` AND b.dispatcher_id = ?`; params.push(dispatcher_id); }
        if (sub_company) { baseQuery += ` AND b.sub_company = ?`; params.push(sub_company); }
        if (search) {
            baseQuery += ` AND (b.booking_id LIKE ? OR b.name LIKE ? OR b.phone_no LIKE ? OR b.email LIKE ? OR d.name LIKE ? OR vt.vehicle_type_name LIKE ?)`;
            const s = `%${search}%`;
            params.push(s, s, s, s, s, s);
        }

        const dataQuery = `
            SELECT 
                b.*,
                d.id as driver_id, d.name as driver_name, d.email as driver_email,
                d.phone_no as driver_phone, d.profile_image as driver_profile_image,
                vt.id as vehicle_type_id, vt.vehicle_type_name, vt.vehicle_type_service,
                sc.id as sub_company_id, sc.name as sub_company_name, sc.email as sub_company_email
            ${baseQuery}
            ORDER BY b.booking_date DESC, b.id DESC
            LIMIT ? OFFSET ?
        `;

        const [bookings] = await db.query(dataQuery, [...params, limitNum, offset]);

        const formattedBookings = bookings.map(booking => {
            const {
                driver_id, driver_name, driver_email, driver_phone, driver_profile_image,
                vehicle_type_id, vehicle_type_name, vehicle_type_service,
                sub_company_id, sub_company_name, sub_company_email,
                ...bookingData
            } = booking;

            return {
                ...bookingData,
                pre_booking: isPreBookingRow(bookingData, todayDate),
                driverDetail: driver_id ? { id: driver_id, name: driver_name, email: driver_email, phone_no: driver_phone, profile_image: driver_profile_image } : null,
                vehicleDetail: vehicle_type_id ? { id: vehicle_type_id, vehicle_type_name, vehicle_type_service } : null,
                subCompanyDetail: sub_company_id ? { id: sub_company_id, name: sub_company_name, email: sub_company_email } : null
            };
        });

        const countQuery = `SELECT COUNT(*) AS total ${baseQuery}`;
        const [[{ total }]] = await db.query(countQuery, params);

        return res.json({
            success: true,
            data: formattedBookings,
            pagination: {
                total, page: pageNum, limit: limitNum,
                total_pages: Math.ceil(total / limitNum),
                hasNext: pageNum * limitNum < total,
                hasPrev: pageNum > 1
            },
            message: "Bookings fetched successfully by Hassan Raza"
        });

    } catch (error) {
        console.error("Error fetching bookings:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.put("/bookings/:id", async (req, res) => {
    try {
        const { id } = req.params;
        const databaseHeader = req.headers["database"];

        if (!databaseHeader) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const laravelApi = process.env.LARAVEL_API || `https://${process.env.APP_URL || "127.0.0.1:8000"}/api`;
        const response = await axios.post(
            `${laravelApi}/internal/edit-booking`,
            {
                id: parseInt(id, 10),
                ...req.body,
            },
            {
                headers: {
                    database: databaseHeader,
                    Authorization: `Bearer ${process.env.NODE_INTERNAL_SECRET}`,
                    Accept: "application/json",
                },
                timeout: 10000,
            }
        );

        const booking = response.data?.booking;
        const dbName = databaseHeader.toString();
        const finalDb = toTenantDbName(dbName);

        if (booking) {
            emitTenantRooms(dbName, "booking-updated-event", booking);
            await broadcastDashboardCardsUpdate(finalDb);
        }

        return res.json(response.data);
    } catch (error) {
        const status = error.response?.status || 500;
        const payload = error.response?.data || { success: false, message: error.message };
        return res.status(status).json(payload);
    }
});

app.put("/bookings/:id/assign-driver", async (req, res) => {
    try {
        const { id } = req.params;
        const { driver_id, assignment_type } = req.body;
        const dbName = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.tenantDb);

        if (!driver_id) {
            return res.status(400).json({ success: false, message: "Driver ID is required" });
        }

        const db = getConnection(req.tenantDb);

        const [bookingRows] = await db.query(
            "SELECT id, booking_status, booking_id, offered_amount, booking_amount, recommended_amount, booking_date, pickup_time FROM bookings WHERE id = ?",
            [id]
        );
        if (bookingRows.length === 0) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = bookingRows[0];

        const [driverRows] = await db.query(
            "SELECT id, name, phone_no, driving_status FROM drivers WHERE id = ?",
            [driver_id]
        );
        if (driverRows.length === 0) {
            return res.status(404).json({ success: false, message: "Driver not found" });
        }

        const isPreJob = assignment_type === "pre_job";
        const dispatcherName = req.body.dispatcher_name || "Dispatcher";
        const driverName = driverRows[0].name || "Driver";

        // ✅ ±30 min window check
        let newStatus = 'pending';
        let isWithinWindow = false;

        if (booking.booking_date && booking.pickup_time) {
            const bookingDateStr = new Date(booking.booking_date).toISOString().split("T")[0];
            const bookingDateTime = new Date(`${bookingDateStr}T${booking.pickup_time}`);

            const now = new Date();
            const diffMs = bookingDateTime.getTime() - now.getTime();
            const diffMins = diffMs / (1000 * 60);

            if (diffMins >= -30 && diffMins <= 30) {
                isWithinWindow = true;
                newStatus = 'ongoing';
            } else {
                isWithinWindow = false;
                newStatus = 'pending';
            }
        } else {
            isWithinWindow = true;
            newStatus = 'ongoing';
        }

        const actionText = isPreJob
            ? `${dispatcherName} sent a pre-job request and automatically accepted for driver ${driverName}`
            : `${dispatcherName} assigned and automatically accepted for driver ${driverName}`;

        const existingAmount = booking.booking_amount;
        const offeredAmount = booking.offered_amount;
        const amountToSet = (existingAmount === null || existingAmount === undefined || existingAmount == 0)
            ? (offeredAmount ?? null)
            : existingAmount;

        await db.query(
            `UPDATE bookings SET driver = ?, booking_amount = ?, dispatcher_action = ?, booking_status = ? WHERE id = ?`,
            [driver_id, amountToSet, actionText, newStatus, id]
        );

        if (isWithinWindow) {
            await db.query("UPDATE drivers SET driving_status = 'busy' WHERE id = ?", [driver_id]);
        }

        const [updatedBookingRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
        const updatedBooking = updatedBookingRows[0];

        const notifTitle = isPreJob ? "Pre-Job Assigned" : "New Ride Assigned";
        const notifMessage = isWithinWindow
            ? isPreJob
                ? `You have been assigned a pre-job ride #${updatedBooking.booking_id}. It has been automatically accepted for you.`
                : `You have been assigned a new ride #${updatedBooking.booking_id}. It has been automatically accepted for you.`
            : `You have been assigned a ride #${updatedBooking.booking_id}. Pickup is scheduled at ${booking.pickup_time}. Please be ready.`;

        try {
            await sendNotificationToDriver(db, driver_id, notifTitle, notifMessage, {
                booking_id: String(id),
                type: "new_ride"
            });
            console.log("Notification sent to driver:", driverRows[0].name);
        } catch (fcmError) {
            console.error("FCM failed (non-fatal):", fcmError.message);
        }

        try {
            await storeNotification(db, {
                user_type: 'driver',
                user_id: driver_id,
                title: notifTitle,
                message: notifMessage
            });
        } catch (storeError) {
            console.error("Store notification failed (non-fatal):", storeError.message);
        }

        const driverSocketId = getTenantSocket(driverSockets, dbName, driver_id);
        if (driverSocketId) {
            io.to(driverSocketId).emit("new-ride-request", {
                booking_id: id,
                assignment_type: assignment_type,
                message: notifMessage,
                booking: updatedBooking
            });
        }

        emitTenantRooms(dbName, "notification-ride", updatedBooking);

        return res.json({
            success: true,
            is_within_window: isWithinWindow,
            booking_status: newStatus,
            message: isWithinWindow
                ? isPreJob
                    ? "Pre-job assigned and automatically accepted successfully."
                    : "Driver assigned and ride accepted successfully."
                : `Driver assigned successfully. Pickup is at ${booking.pickup_time} — status remains pending until closer to pickup time.`
        });

    } catch (error) {
        console.error("Assign driver error:", error);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/bookings/:id/start-auto-dispatch", async (req, res) => {
    try {
        const { id } = req.params;
        const { dispatcher_name } = req.body;
        const dispatcherName = dispatcher_name || "Dispatcher";

        if (!req.tenantDb) {
            console.error("[API /start-auto-dispatch] req.tenantDb is undefined — missing 'database' header");
            return res.status(400).json({
                success: false,
                message: "Missing 'database' header in request"
            });
        }

        console.log(`[API] Connected drivers at dispatch time: [${Array.from(driverSockets.keys()).join(', ')}]`);

        const db = getConnection(req.tenantDb);
        const result = await plotDispatch.startPlotDispatchCycle({
            bookingId: id,
            tenantDb: req.tenantDb,
        });

        return res.json({
            success: true,
            message: "Plot dispatch cycle started",
            ...result,
            debug: {
                tenantDb: req.tenantDb,
                connected_driver_ids: Array.from(driverSockets.keys())
            }
        });

    } catch (error) {
        console.error("[API] /start-auto-dispatch error:", error.message);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.get("/bookings/:id/plot-dispatch-status", async (req, res) => {
    try {
        const { id } = req.params;

        if (!req.tenantDb) {
            return res.status(400).json({
                success: false,
                message: "Missing 'database' header in request",
            });
        }

        const status = await plotDispatch.getPlotDispatchStatus({
            bookingId: id,
            tenantDb: req.tenantDb,
        });

        if (!status.found) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        return res.json({ success: true, ...status });
    } catch (error) {
        console.error("[API] /plot-dispatch-status error:", error.message);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/bookings/:id/auto-dispatch/reject", async (req, res) => {
    try {
        const bookingIdInt = parseInt(req.params.id, 10);
        const driverId = req.body.driver_id ?? req.body.driverId;
        const tenantDb = await resolveTenantDbForRequest(req) || req.tenantDb;

        if (!req.tenantDb) {
            return res.status(400).json({
                success: false,
                message: "Missing 'database' header in request",
            });
        }
        if (!tenantDb) {
            return res.status(400).json({
                success: false,
                message: "Unable to resolve tenant database from request",
            });
        }
        req.tenantDb = tenantDb;
        if (!driverId) {
            return res.status(400).json({
                success: false,
                message: "driver_id is required",
            });
        }

        const nearestResult = await handleNearestDispatchReject({
            bookingIdInt,
                tenantDb,
                driverId,
            });
        if (nearestResult.handled) {
            return res.status(nearestResult.status).json(nearestResult.body);
        }

        const result = await handleAutoDispatchReject({
                bookingIdInt,
                tenantDb: req.tenantDb,
                driverId,
            });

        return res.status(result.status).json(result.body);
    } catch (error) {
        console.error("[API] /auto-dispatch/reject error:", error.message);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/bookings/:id/manual-assignment/expire", async (req, res) => {
    try {
        const bookingIdInt = parseInt(req.params.id, 10);
        const driverId = req.body.driver_id ?? req.body.driverId;

        if (!req.tenantDb) {
            return res.status(400).json({
                success: false,
                message: "Missing 'database' header in request",
            });
        }
        if (!driverId) {
            return res.status(400).json({
                success: false,
                message: "driver_id is required",
            });
        }

        const db = getConnection(req.tenantDb);
        const dbName = toTenantSocketName(req.tenantDb);
        const [rows] = await db.query(
            "SELECT booking_status, driver, pending_driver_id, dispatcher_action FROM bookings WHERE id = ?",
            [bookingIdInt]
        );

        if (!rows.length) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = rows[0];
        const actionText = String(booking.dispatcher_action || "").toLowerCase();
        const isManualAssignment = (
            actionText.includes("assigned") ||
            actionText.includes("pre-job") ||
            actionText.includes("manual") ||
            actionText.includes("driver selected") ||
            actionText.includes("dispatching now")
        );
        const isAssignedDriver = (
            String(booking.driver || "") === String(driverId) ||
            String(booking.pending_driver_id || "") === String(driverId)
        );

        if (!isManualAssignment || !isAssignedDriver || String(booking.booking_status) !== "pending") {
            return res.status(200).json({
                success: true,
                skipped: true,
                message: "Manual assignment is no longer pending",
            });
        }

        const expireAction = `Manual assignment expired for driver #${driverId} — returned to dispatcher panel`;
        await db.query(
            `UPDATE bookings
             SET driver = NULL,
                 pending_driver_id = NULL,
                 booking_status = 'pending',
                 dispatcher_action = ?
             WHERE id = ?`,
            [expireAction, bookingIdInt]
        );
        await db.query("UPDATE drivers SET driving_status = 'idle' WHERE id = ?", [driverId]);

        const [updatedRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [bookingIdInt]);
        const updatedBooking = updatedRows[0] || { id: bookingIdInt };
        const expireEvent = {
            booking_id: bookingIdInt,
            id: bookingIdInt,
            driver_id: driverId,
            database: dbName,
            booking: updatedBooking,
            message: expireAction,
        };

        emitTenantRooms(dbName, "manual-assignment-expired", expireEvent);
        emitTenantRooms(dbName, "booking-updated-event", updatedBooking);
        emitTenantRooms(dbName, "notification-ride", updatedBooking);
        await broadcastDashboardCardsUpdate(req.tenantDb);
        await broadcastTodaysBookingsListUpdate(req.tenantDb, db, dbName, bookingIdInt);

        return res.json({
            success: true,
            message: "Manual assignment expired — returned to dispatcher panel",
        });
    } catch (error) {
        console.error("[API] /manual-assignment/expire error:", error.message);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/driver/reject-ride", async (req, res) => {
    try {
        const rideId = req.body.ride_id ?? req.body.booking_id;
        const driverId = req.body.driver_id ?? req.body.driverId;
        const tenantDb = await resolveTenantDbForRequest(req) || req.tenantDb;

        if (!req.tenantDb) {
            return res.status(400).json({ success: false, message: "Missing 'database' header in request" });
        }
        if (!tenantDb) {
            return res.status(400).json({
                success: false,
                message: "Unable to resolve tenant database from request",
            });
        }
        req.tenantDb = tenantDb;
        if (!rideId) {
            return res.status(400).json({ success: false, message: "ride_id is required" });
        }
        if (!driverId) {
            return res.status(400).json({ success: false, message: "driver_id is required" });
        }

        const nearestResult = await handleNearestDispatchReject({
            bookingIdInt: parseInt(rideId, 10),
            tenantDb,
            driverId,
        });
        if (nearestResult.handled) {
            return res.status(nearestResult.status).json(nearestResult.body);
        }

        const result = await handleAutoDispatchReject({
            bookingIdInt: parseInt(rideId, 10),
            tenantDb,
            driverId,
        });

        return res.status(result.status).json(result.body);
    } catch (error) {
        console.error("[API] /driver/reject-ride error:", error.message);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/driver/accept-ride", async (req, res) => {
    try {
        const rideId = req.body.ride_id ?? req.body.booking_id;
        const driverId = req.body.driver_id ?? req.body.driverId;

        if (rideId) {
            const bookingIdInt = parseInt(rideId, 10);
            const nearestSession = nearestDispatchSessions.get(String(bookingIdInt));
            const acceptDbName = toTenantSocketName(req.tenantDb || req.headers['database'] || req.headers['x-database']);

            if (nearestSession) {
                notifyNearestDriversRideWithdrawn(
                    acceptDbName,
                    nearestSession.notifiedDriverIds ?? [],
                    driverId,
                    bookingIdInt
                );
            }

            clearNearestDispatchSession(bookingIdInt);
            clearAutoDispatchSession(bookingIdInt);

            if (req.tenantDb) {
                try {
                    const db = getConnection(req.tenantDb);
                    const [bookingRows] = await db.query(
                        'SELECT dispatcher_action FROM bookings WHERE id = ?',
                        [bookingIdInt]
                    );
                    const dispatcherAction = bookingRows[0]?.dispatcher_action;
                    const plotSession = plotDispatch.plotDispatchSessions.get(String(bookingIdInt));
                    const [cycleRows] = await db.query(
                        'SELECT status FROM booking_dispatch_cycles WHERE booking_id = ?',
                        [bookingIdInt]
                    );
                    const cycleStatus = cycleRows[0]?.status;
                    const isPlotAccept = plotSession
                        || plotDispatch.isPlotDispatchActive(dispatcherAction)
                        || cycleStatus === 'accepted';

                    if (isPlotAccept) {
                        await plotDispatch.handlePlotDispatchAccept({
                            bookingIdInt,
                            tenantDb: req.tenantDb,
                            driverId,
                        });
                    } else {
                        const dbName = req.tenantDb.startsWith('tenant')
                            ? req.tenantDb.slice('tenant'.length)
                            : req.tenantDb;
                        const [updatedRows] = await db.query('SELECT * FROM bookings WHERE id = ?', [bookingIdInt]);

                        if (updatedRows.length) {
                            const updatedBooking = updatedRows[0];
                            emitTenantRooms(dbName, 'notification-ride', updatedBooking);
                            emitTenantRooms(dbName, 'booking-updated-event', updatedBooking);
                            await broadcastDashboardCardsUpdate(req.tenantDb);
                        }
                    }
                } catch (e) {
                    console.error('[Dispatch] Accept broadcast error:', e.message);
                }
            }

            plotDispatch.clearPlotDispatchSession(bookingIdInt);
        }
        return res.json({ success: true, message: "Accept acknowledged" });
    } catch (error) {
        console.error("[API] /driver/accept-ride error:", error.message);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/bookings/:id/start-nearest-dispatch", async (req, res) => {
    try {
        const { id } = req.params;
        const { dispatcher_name } = req.body;
        const dispatcherName = dispatcher_name || "Dispatcher";

        if (!req.tenantDb) {
            return res.status(400).json({
                success: false,
                message: "Missing 'database' header in request"
            });
        }

        const db = getConnection(req.tenantDb);

        // Booking check
        const [bookingRows] = await db.query(
            "SELECT id, booking_status, pickup_point FROM bookings WHERE id = ?",
            [id]
        );
        if (!bookingRows.length) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = bookingRows[0];

        if (!booking.pickup_point || !booking.pickup_point.includes(',')) {
            return res.status(400).json({
                success: false,
                message: "Booking does not have valid pickup coordinates (pickup_point)"
            });
        }

        if (["cancelled", "completed", "ongoing"].includes(booking.booking_status)) {
            return res.status(400).json({
                success: false,
                message: `Booking status is '${booking.booking_status}' — cannot dispatch`
            });
        }

        await db.query(
            "UPDATE bookings SET dispatcher_action = ? WHERE id = ?",
            [`${dispatcherName} started nearest driver dispatch`, id]
        );

        // Start nearest dispatch
        nearestDriverDispatch({ bookingId: id, tenantDb: req.tenantDb });

        return res.json({
            success: true,
            message: "Nearest driver dispatch started",
            pickup_point: booking.pickup_point
        });

    } catch (error) {
        console.error("start-nearest-dispatch error:", error.message);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.get("/debug/dispatch-check", async (req, res) => {
    try {
        const { booking_id, database } = req.query;
        if (!database) {
            return res.status(400).json({ error: "database query param required" });
        }

        const tenantDb = toTenantDbName(database);
        const db = getConnection(tenantDb);

        // booking info
        let booking = null;
        if (booking_id) {
            const [bRows] = await db.query(
                "SELECT id, booking_status, driver, pickup_plot_id, destination_plot_id, pickup_point FROM bookings WHERE id = ?",
                [booking_id]
            );
            booking = bRows[0] || null;
        }

        let driversInPlot = [];
        let idleInPlot = [];
        if (booking?.pickup_plot_id) {
            const [dRows] = await db.query(
                "SELECT id, name, driving_status, plot_id, latitude, longitude FROM drivers WHERE plot_id = ?",
                [booking.pickup_plot_id]
            );
            driversInPlot = dRows;
            idleInPlot = dRows.filter(d => d.driving_status === 'idle');
        }

        const [allDrivers] = await db.query(
            "SELECT id, name, driving_status, plot_id FROM drivers ORDER BY id"
        );

        return res.json({
            socket_map: {
                total_drivers_connected: driverSockets.size,
                drivers: Array.from(driverSockets.entries()).map(([id, sid]) => ({
                    driver_id: id,
                    socket_id: sid
                })),
                dispatchers: Array.from(dispatcherSockets.entries()).map(([id, sid]) => ({ id, socket_id: sid })),
                admins: Array.from(adminSockets.entries()).map(([id, sid]) => ({ id, socket_id: sid }))
            },
            booking,
            drivers_in_booking_plot: driversInPlot,
            idle_drivers_in_plot: idleInPlot,
            all_drivers_in_db: allDrivers,
            plot_queues: Array.from(plotDriverQueues.entries()).map(([key, queue]) => ({ key, queue }))
        });

    } catch (err) {
        console.error("[Debug] dispatch-check error:", err.message);
        return res.status(500).json({ error: err.message });
    }
});

const hasValidInternalAuth = (req) => {
    const expected = process.env.NODE_INTERNAL_SECRET;
    if (!expected) return true;
    const header = req.headers.authorization || '';
    const token = header.startsWith('Bearer ') ? header.slice('Bearer '.length) : header;
    return token === expected;
};

app.get("/socket-health", (req, res) => {
    if (!hasValidInternalAuth(req)) {
        return res.status(401).json({ success: false, message: "Unauthorized" });
    }

    const locationPersistStats = driverLocationPersistCoalescer.stats();
    return res.json({
        success: true,
        sockets: {
            drivers: driverSockets.size,
            users: userSockets.size,
            dispatchers: dispatcherSockets.size,
            clients: clientSockets.size,
            admins: adminSockets.size,
            total: driverSockets.size + userSockets.size + dispatcherSockets.size + clientSockets.size + adminSockets.size,
        },
        runtime: {
            driverLastLocationEntries: driverLastLocationTime.size,
            driverDisconnectTimers: driverDisconnectTimers.size,
            knownDriverRuntimeKeys: knownDriverRuntimeKeys.size,
            driverLocationCacheEntries: driverLocationCache.size,
            driverLocationPersistInFlight: locationPersistStats.inFlight,
            driverLocationPendingPersist: locationPersistStats.pending,
            driverLocationQueuedPersist: locationPersistStats.queued,
            plotQueueCount: plotDriverQueues.size,
            plotPolygonCacheEntries: plotPolygonCache.size,
            memory: process.memoryUsage(),
            uptimeSeconds: process.uptime(),
        },
        gps: {
            ...gpsStats,
            idlePersistMs: GPS_IDLE_PERSIST_MS,
            activePersistMs: GPS_ACTIVE_PERSIST_MS,
            persistConcurrency: GPS_PERSIST_CONCURRENCY,
            idleMinMovementMeters: GPS_IDLE_MIN_MOVEMENT_METERS,
            activeMinMovementMeters: GPS_ACTIVE_MIN_MOVEMENT_METERS,
            liveBroadcastFlushMs: GPS_LIVE_BROADCAST_FLUSH_MS,
        },
        liveGpsBroadcast: {
            ...liveGpsBroadcastStats,
            pendingTenants: pendingLiveGpsBroadcasts.size,
            pendingDrivers: Array.from(pendingLiveGpsBroadcasts.values())
                .reduce((total, pending) => total + pending.size, 0),
            timers: liveGpsBroadcastTimers.size,
        },
        queueBroadcast: {
            ...queueBroadcastStats,
            fullBroadcastInFlight: fullQueueBroadcastInFlight.size,
            fullBroadcastPending: fullQueueBroadcastPending.size,
            fullBroadcastTimers: fullQueueBroadcastTimers.size,
            fullBroadcastCoalesceMs: QUEUE_FULL_BROADCAST_COALESCE_MS,
        },
        server: {
            listenBacklog: SOCKET_LISTEN_BACKLOG,
            pingIntervalMs: SOCKET_PING_INTERVAL_MS,
            pingTimeoutMs: SOCKET_PING_TIMEOUT_MS,
        },
        db: getPoolStats(),
    });
});

app.get("/debug/tokens", async (req, res) => {
    try {
        const { database, limit = 100 } = req.query;
        if (!database) {
            return res.status(400).json({ error: "database query param is required (e.g., ?database=1 or ?database=tenant1)" });
        }

        let tenantDb = database;
        if (!isNaN(database)) {
            tenantDb = toTenantDbName(database);
        }

        const db = getConnection(tenantDb);
        const rowLimit = parseInt(limit) || 100;

        // 1. Fetch from tokens table
        let tokensTableRows = [];
        try {
            const [rows] = await db.query(
                "SELECT id, user_id, user_type, LEFT(fcm_token, 50) as fcm_token_prefix, LENGTH(fcm_token) as token_length, created_at FROM tokens ORDER BY id DESC LIMIT ?",
                [rowLimit]
            );
            tokensTableRows = rows;
        } catch (e) {
            console.warn(`[Debug Tokens] tokens table query failed: ${e.message}`);
        }

        // 2. Fetch from drivers table (where fcm_token or device_token is set)
        let driversTableRows = [];
        try {
            const [rows] = await db.query(
                "SELECT id, name, email, LEFT(fcm_token, 50) as fcm_token_prefix, LENGTH(fcm_token) as fcm_token_length, LEFT(device_token, 50) as device_token_prefix, LENGTH(device_token) as device_token_length FROM drivers WHERE fcm_token IS NOT NULL OR device_token IS NOT NULL LIMIT ?",
                [rowLimit]
            );
            driversTableRows = rows;
        } catch (e) {
            console.warn(`[Debug Tokens] drivers table query failed: ${e.message}`);
        }

        // 3. Fetch from users table (where fcm_token or device_token is set)
        let usersTableRows = [];
        try {
            const [rows] = await db.query(
                "SELECT id, name, email, LEFT(fcm_token, 50) as fcm_token_prefix, LENGTH(fcm_token) as fcm_token_length, LEFT(device_token, 50) as device_token_prefix, LENGTH(device_token) as device_token_length FROM users WHERE fcm_token IS NOT NULL OR device_token IS NOT NULL LIMIT ?",
                [rowLimit]
            );
            usersTableRows = rows;
        } catch (e) {
            console.warn(`[Debug Tokens] users table query failed: ${e.message}`);
        }

        return res.json({
            success: true,
            database: tenantDb,
            tokens_table_count: tokensTableRows.length,
            tokens_table: tokensTableRows,
            drivers_tokens: driversTableRows,
            users_tokens: usersTableRows
        });

    } catch (err) {
        console.error("[Debug Tokens] Error fetching tokens:", err.message);
        return res.status(500).json({ error: err.message });
    }
});

app.post("/bookings/:id/record-action", async (req, res) => {
    try {
        const { id } = req.params;
        const { action, dispatcher_name } = req.body;
        const dispatcherName = dispatcher_name || "Dispatcher";

        if (!action) return res.status(400).json({ success: false, message: "action is required" });

        const db = getConnection(req.tenantDb);
        await db.query(
            "UPDATE bookings SET dispatcher_action = ? WHERE id = ?",
            [`${dispatcherName} ${action}`, id]
        );

        return res.json({ success: true, message: "Action recorded" });
    } catch (error) {
        console.error("Record action error:", error);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.post("/bookings/:id/set-follow-on-job", async (req, res) => {
    try {
        const { id } = req.params;
        const { follow_on_booking_id } = req.body;

        if (!follow_on_booking_id) {
            return res.status(400).json({ success: false, message: "follow_on_booking_id is required" });
        }

        if (parseInt(id) === parseInt(follow_on_booking_id)) {
            return res.status(400).json({ success: false, message: "A booking cannot be a follow-on of itself" });
        }

        const db = getConnection(req.tenantDb);

        const [job1Rows] = await db.query(
            "SELECT id, booking_id, booking_status, driver, booking_system FROM bookings WHERE id = ?",
            [id]
        );
        if (!job1Rows.length) return res.status(404).json({ success: false, message: "Job 1 not found" });

        const job1 = job1Rows[0];

        if (!job1.driver) {
            return res.status(400).json({ success: false, message: "Job 1 has no driver assigned. Assign a driver first." });
        }

        if (!['ongoing', 'arrived', 'started'].includes(job1.booking_status)) {
            return res.status(400).json({
                success: false,
                message: `Job 1 must be active (ongoing/arrived/started). Current status: ${job1.booking_status}`
            });
        }

        const [job2Rows] = await db.query(
            "SELECT id, booking_id, booking_status FROM bookings WHERE id = ?",
            [follow_on_booking_id]
        );
        if (!job2Rows.length) return res.status(404).json({ success: false, message: "Follow-on booking (Job 2) not found" });

        const job2 = job2Rows[0];

        if (!['pending', 'pending_acceptance'].includes(job2.booking_status)) {
            return res.status(400).json({
                success: false,
                message: `Job 2 must be pending. Current status: ${job2.booking_status}`
            });
        }

        const [alreadyLinked] = await db.query(
            "SELECT id FROM bookings WHERE booking_system = ?",
            [String(follow_on_booking_id)]
        );
        if (alreadyLinked.length) {
            return res.status(400).json({
                success: false,
                message: `Booking #${job2.booking_id} is already queued as a follow-on for another job`
            });
        }

        const [driverRows] = await db.query("SELECT id, name FROM drivers WHERE id = ?", [job1.driver]);
        const driverName = driverRows[0]?.name || "Driver";
        const dispatcherName = req.body.dispatcher_name || "Dispatcher";

        await db.query(
            "UPDATE bookings SET booking_system = ?, dispatcher_action = ? WHERE id = ?",
            [
                String(follow_on_booking_id),
                `${dispatcherName} linked booking #${job2.booking_id} as a follow-on job to this ride`,
                id
            ]
        );

        // ✅ Fetch full updated job2 booking to send to driver
        const [updatedJob2Rows] = await db.query("SELECT * FROM bookings WHERE id = ?", [follow_on_booking_id]);
        const updatedJob2 = updatedJob2Rows[0];

        const responseData = {
            job1_id: job1.id,
            job1_booking_id: job1.booking_id,
            job2_id: job2.id,
            job2_booking_id: job2.booking_id,
            driver_id: job1.driver,
            driver_name: driverName,
            message: `Booking #${job2.booking_id} queued as follow-on after #${job1.booking_id} for ${driverName}`
        };

        // ✅ Notify dispatcher/admin/client via socket
        emitTenantRooms(dbName, "follow-on-job-linked", responseData);

        // ✅ Push notification to driver
        const notifTitle = "New Follow-On Job";
        const notifMessage = `You have a new follow-on ride #${updatedJob2.booking_id} queued after your current job. Please accept or reject.`;

        try {
            await sendNotificationToDriver(db, job1.driver, notifTitle, notifMessage, {
                booking_id: String(follow_on_booking_id),
                type: "new_ride"
            });
            console.log(`[FollowOn] Push notification sent to driver #${job1.driver}`);
        } catch (fcmErr) {
            console.error("[FollowOn] FCM failed (non-fatal):", fcmErr.message);
        }

        try {
            await storeNotification(db, {
                user_type: 'driver',
                user_id: job1.driver,
                title: notifTitle,
                message: notifMessage
            });
        } catch (storeErr) {
            console.error("[FollowOn] Store notification failed (non-fatal):", storeErr.message);
        }

        const driverSocketId = getTenantSocket(driverSockets, dbName, job1.driver);

        console.log(`[FollowOn] job1.driver = "${job1.driver}", type = ${typeof job1.driver}`);
        console.log(`[FollowOn] driverSockets keys:`, Array.from(driverSockets.keys()));
        console.log(`[FollowOn] Found socketId: ${driverSocketId}`);

        if (driverSocketId) {
            io.to(driverSocketId).emit("new-ride-request", {
                booking_id: String(follow_on_booking_id),
                assignment_type: "allocate_driver",
                message: notifMessage,
                booking: updatedJob2
            });
            console.log(`[FollowOn] Socket event 'new-ride-request' sent to driver #${job1.driver}`);
        } else {
            console.log(`[FollowOn] Driver #${job1.driver} not connected via socket — push notification sent only`);
        }

        console.log(`[FollowOn] Linked: Job #${job1.booking_id} → Job #${job2.booking_id} (Driver: ${driverName})`);

        return res.json({ success: true, message: responseData.message, data: responseData });

    } catch (error) {
        console.error("Set follow-on job error:", error);
        return res.status(500).json({ success: false, message: error.message });
    }
});

app.put("/bookings/:id/status", async (req, res) => {
    try {
        const { id } = req.params;
        let { booking_status, cancel_reason, cancelled_by } = req.body;
        let cancelled_by_actor = cancelled_by || 'admin';

        if (!booking_status) return res.status(400).json({ success: false, message: "booking_status is required" });

        const dbName = toTenantSocketName(req.tenantDb || req.headers['database'] || req.headers['x-database']);
        const db = getConnection(req.tenantDb);
        const [bookings] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
        if (bookings.length === 0) return res.status(404).json({ success: false, message: "Booking not found" });

        const booking = bookings[0];
        let res_user = null;

        const dispatcherName = req.body.dispatcher_name || "Dispatcher";
        let updateQuery = "UPDATE bookings SET booking_status = ?";
        const params = [booking_status];

        let actionLabel = `updated the status to ${booking_status}`;
        if (booking_status === 'cancelled') actionLabel = "cancelled this ride";
        else if (booking_status === 'completed') actionLabel = "marked the ride as completed";
        else if (booking_status === 'no_show') actionLabel = "marked this ride as no show";

        updateQuery += ", dispatcher_action = ?";
        params.push(`${dispatcherName} ${actionLabel}`);

        if (booking_status === 'cancelled') {
            if (cancel_reason) { updateQuery += ", cancel_reason = ?"; params.push(cancel_reason); }
            if (cancelled_by === 'user' || cancelled_by === 'driver') {
                updateQuery += ", cancelled_by = ?"; params.push(cancelled_by);
            }
        }
        updateQuery += " WHERE id = ?";
        params.push(id);

	        await db.query(updateQuery, params);
	        const isTerminalStatus = ['cancelled', 'completed', 'no_show'].includes(String(booking_status || '').toLowerCase());
	        if (isTerminalStatus) {
	            const bookingIdInt = parseInt(id, 10);
	            clearNearestDispatchSession(bookingIdInt);
	            clearAutoDispatchSession(bookingIdInt);
	            await plotDispatch.terminatePlotDispatchForBooking({
	                bookingId: bookingIdInt,
	                tenantDb: req.tenantDb,
	                db,
	                dbName,
	                status: booking_status,
	            });
	        }

	        if (booking.driver) {
            let driverStatus = null;
            if (['cancelled', 'completed', 'no_show'].includes(booking_status)) driverStatus = 'idle';
            else if (['ongoing', 'started', 'arrived'].includes(booking_status)) driverStatus = 'busy';
            if (driverStatus) {
                await db.query("UPDATE drivers SET driving_status = ? WHERE id = ?", [driverStatus, booking.driver]);
            }
        }

        if (booking_status === 'cancelled') {
            const notifTitle = "Ride Cancelled";
            const notifMessage = cancelled_by_actor === 'user'
                ? `Ride #${booking.booking_id} has been cancelled by customer`
                : `Ride #${booking.booking_id} is cancelled by Admin or Dispatcher`;

            if (booking.user_id) {
                const userNotifTitle = "Ride Cancelled";
                const userNotifMessage = cancelled_by_actor === 'user'
                    ? `Your ride #${booking.booking_id} has been successfully cancelled.`
                    : `Your ride #${booking.booking_id} has been cancelled by Admin or Dispatcher.`;
                try {
                    res_user = await sendNotificationToUser(db, booking.user_id, userNotifTitle, userNotifMessage, {
                        booking_id: String(id),
                        type: "ride_cancelled"
                    });
                    await storeNotification(db, {
                        user_type: 'rider',
                        user_id: booking.user_id,
                        title: userNotifTitle,
                        message: userNotifMessage
                    });
                    console.log("Cancel notification sent to user:", booking.user_id);
                } catch (userNotifErr) {
                    console.error("User Notification error in ride cancellation:", userNotifErr.message);
                }
            }

            if (booking.driver) {
                try {
                    await sendNotificationToDriver(db, booking.driver, notifTitle, notifMessage, {
                        booking_id: String(id),
                        type: "ride_cancelled"
                    });
                    await storeNotification(db, {
                        user_type: 'driver',
                        user_id: booking.driver,
                        title: notifTitle,
                        message: notifMessage
                    });
                    console.log("Cancel notification sent to driver:", booking.driver);
                } catch (notifErr) {
                    console.error("Notification error in ride cancellation (driver):", notifErr.message);
                }
            }

        } else if (booking.driver) {
            const [driverInfoRows] = await db.query(
                "SELECT id, name, phone_no FROM drivers WHERE id = ?",
                [booking.driver]
            );
            const driverInfoForFo = driverInfoRows[0];

            if (booking_status === 'completed') {
                const notifTitle = "Ride Completed";
                const notifMessage = `Ride #${booking.booking_id} has been marked as completed`;

                try {
                    await sendNotificationToDriver(db, booking.driver, notifTitle, notifMessage, {
                        booking_id: String(id),
                        type: "ride_completed"
                    });
                    await storeNotification(db, {
                        user_type: 'driver',
                        user_id: booking.driver,
                        title: notifTitle,
                        message: notifMessage
                    });
                    console.log("Complete notification sent to driver:", driverInfoForFo?.name);
                } catch (notifErr) {
                    console.error("Notification error in ride completion (driver):", notifErr.message);
                }

                if (booking.user_id) {
                    try {
                        res_user = await sendNotificationToUser(db, booking.user_id, notifTitle, notifMessage, {
                            booking_id: String(id),
                            type: "ride_completed"
                        });
                        await storeNotification(db, {
                            user_type: 'rider',
                            user_id: booking.user_id,
                            title: notifTitle,
                            message: notifMessage
                        });
                    } catch (err) {
                        console.error("Notification error in ride completion (user):", err.message);
                    }
                }
            } else if (['arrived', 'started'].includes(booking_status)) {
                const userNotifTitle = booking_status === 'arrived' ? "Driver Arrived" : "Ride Started";
                const userNotifMessage = booking_status === 'arrived'
                    ? `Your driver has arrived at the pickup location.`
                    : `Your ride has started. Have a safe journey!`;

                if (booking.user_id) {
                    try {
                        res_user = await sendNotificationToUser(db, booking.user_id, userNotifTitle, userNotifMessage, {
                            booking_id: String(id),
                            type: `ride_${booking_status}`
                        });
                        await storeNotification(db, {
                            user_type: 'rider',
                            user_id: booking.user_id,
                            title: userNotifTitle,
                            message: userNotifMessage
                        });
                    } catch (err) {
                        console.error(`Notification error in ride ${booking_status} (user):`, err.message);
                    }
                }
            }
        }

        let followOnPayload = null;
        let followOnEventData = null;

        if (booking.booking_system && !isNaN(parseInt(booking.booking_system))) {
            const followOnId = parseInt(booking.booking_system);
            console.log(`[FollowOn] Detected follow-on job #${followOnId} for driver #${booking.driver}`);

            try {
                const [followOnRows] = await db.query(
                    "SELECT * FROM bookings WHERE id = ?",
                    [followOnId]
                );

                if (followOnRows.length && ['pending', 'pending_acceptance'].includes(followOnRows[0].booking_status)) {
                    const followOnBooking = followOnRows[0];
                    const driverId = booking.driver;

                    await db.query(
                        `UPDATE bookings SET driver = ?, booking_status = 'pending_acceptance' WHERE id = ?`,
                        [driverId, followOnId]
                    );

                    await db.query(
                        "UPDATE bookings SET booking_system = NULL WHERE id = ?",
                        [id]
                    );

                    followOnPayload = { ...followOnBooking, driver: driverId, is_follow_on: true };

                    const foNotifTitle = "New Follow-On Job";
                    const foNotifMsg = `Your next job #${followOnBooking.booking_id} is ready. Please accept or reject.`;

                    try {
                        await sendNotificationToDriver(db, driverId, foNotifTitle, foNotifMsg, {
                            booking_id: String(followOnId),
                            type: "new_ride"
                        });
                        await storeNotification(db, {
                            user_type: 'driver',
                            user_id: driverId,
                            title: foNotifTitle,
                            message: foNotifMsg
                        });
                    } catch (notifErr) {
                        console.error("[FollowOn] Notification error:", notifErr.message);
                    }

                    const [driverInfoRows] = await db.query("SELECT name FROM drivers WHERE id = ?", [driverId]);
                    const driverInfo = driverInfoRows[0];

                    followOnEventData = {
                        booking_id: followOnId,
                        driver_id: driverId,
                        driver_name: driverInfo?.name,
                        booking: { ...followOnBooking, driver: driverId, booking_status: 'pending_acceptance' },
                        message: `Follow-on job #${followOnBooking.booking_id} sent to ${driverInfo?.name} — waiting for acceptance`
                    };

                    setTimeout(async () => {
                        try {
                            const [checkRows] = await db.query(
                                "SELECT booking_status, driver FROM bookings WHERE id = ?",
                                [followOnId]
                            );
                            if (!checkRows.length) return;

                            const { booking_status: currentStatus, driver: currentDriver } = checkRows[0];

                            console.log(`[FollowOn] Timeout check for #${followOnId}: status=${currentStatus}, driver=${currentDriver}`);

                            if (currentStatus === 'ongoing') {
                                console.log(`[FollowOn] Job #${followOnId} accepted — status is ongoing`);
                                return;
                            }

                            if (currentStatus === 'cancelled' || currentStatus === 'completed') {
                                console.log(`[FollowOn] Job #${followOnId} is ${currentStatus} — no action`);
                                return;
                            }

                            if (currentStatus === 'pending_acceptance' && currentDriver == driverId) {
                                await db.query(
                                    `UPDATE bookings SET driver = NULL, booking_status = 'pending' WHERE id = ?`,
                                    [followOnId]
                                );

                                const timeoutEvent = {
                                    booking_id: followOnId,
                                    driver_id: driverId,
                                    driver_name: driverInfo?.name,
                                    message: `Driver ${driverInfo?.name} did not respond to follow-on job #${followOnBooking.booking_id} — reset to pending`
                                };
                                emitTenantRooms(dbName, "follow-on-job-timeout", timeoutEvent);

                                console.log(`[FollowOn] Job #${followOnId} timed out — reset to pending`);
                            }
                        } catch (timeoutErr) {
                            console.error("[FollowOn] Timeout check error:", timeoutErr.message);
                        }
                    }, 30000);

                    console.log(`[FollowOn] Job #${followOnId} dispatched to driver #${driverId}`);
                }
            } catch (foError) {
                console.error(`[FollowOn] Error dispatching follow-on job:`, foError.message);
            }
        }

        if (booking.driver) {
            const driverSocketId = getTenantSocket(driverSockets, dbName, booking.driver);
            if (driverSocketId) {
                io.to(driverSocketId).emit("booking-status-updated", {
                    booking_id: id,
                    status: booking_status,
                    message: `Ride status updated to ${booking_status}`
                });
                if (booking_status === 'cancelled' || booking_status === 'cancel') {
                    io.to(driverSocketId).emit("booking-cancelled-event", {
                        booking_id: id,
                        booking: booking,
                        message: cancelled_by_actor === 'user'
                            ? `Ride #${booking.booking_id} has been cancelled by customer`
                            : `Ride #${booking.booking_id} is cancelled by Admin or Dispatcher`
                    });
                }
            }
        }

        if (booking.user_id) {
            const userSocketId = getTenantSocket(userSockets, dbName, booking.user_id);
            if (userSocketId) {
                io.to(userSocketId).emit("booking-status-updated", {
                    booking_id: id,
                    status: booking_status,
                    message: `Your ride status has been updated to ${booking_status}`
                });
                if (booking_status === 'cancelled' || booking_status === 'cancel') {
                    io.to(userSocketId).emit("booking-cancelled-event", {
                        booking_id: id,
                        booking: booking,
                        message: cancelled_by_actor === 'user'
                            ? `Your ride #${booking.booking_id} has been cancelled.`
                            : `Your ride #${booking.booking_id} has been cancelled by Admin or Dispatcher.`
                    });
                }
            }
        }

	        const [updatedBookingRows] = await db.query("SELECT * FROM bookings WHERE id = ?", [id]);
	        const updatedBooking = updatedBookingRows[0];

	        const statusUpdateData = {
	            booking_id: updatedBooking.booking_id,
	            id: updatedBooking.id,
	            booking_reference: updatedBooking.booking_id,
	            status: booking_status,
	            booking_status,
	            database: dbName,
	            booking: { ...updatedBooking, database: dbName },
	            message: `Booking #${updatedBooking.booking_id} status updated to ${booking_status}`
	        };
	        emitTenantRooms(dbName, "booking-status-updated", statusUpdateData);

	        const socketPayload = {
	            status: booking_status,
	            booking_status,
	            id: updatedBooking.id,
	            booking_id: updatedBooking.booking_id,
	            database: dbName,
            booking: {
                ...updatedBooking,
                database: dbName,
                cancelled_by: cancelled_by_actor === 'admin' ? 'admin' : updatedBooking.cancelled_by
            }
        };

        if (updatedBooking.user_id) {
            const userSocketId = getTenantSocket(userSockets, dbName, updatedBooking.user_id);
            if (userSocketId) io.to(userSocketId).emit("user-ride-status-event", socketPayload);
        }
        if (updatedBooking.driver) {
            const driverSocketId = getTenantSocket(driverSockets, dbName, updatedBooking.driver);
            if (driverSocketId) io.to(driverSocketId).emit("driver-ride-status-event", socketPayload);
        }

        if (booking_status === 'cancelled') {
            const cancelNotif = {
                booking_id: id,
                id,
                booking_reference: updatedBooking.booking_id,
                booking_status: 'cancelled',
                status: 'cancelled',
                booking: { ...updatedBooking, database: dbName },
                database: dbName,
                message: `Booking #${updatedBooking.booking_id} has been cancelled`,
                cancelled_by: cancelled_by_actor
            };
            emitTenantRooms(dbName, "booking-cancelled-event", cancelNotif);
        }

        if (booking_status === 'no_show') {
            const noShowNotif = {
                booking_id: id,
                id,
                booking_reference: updatedBooking.booking_id,
                booking_status: 'no_show',
                status: 'no_show',
                booking: { ...updatedBooking, database: dbName },
                database: dbName,
                message: `Booking #${updatedBooking.booking_id} marked as no show`,
                driver_id: updatedBooking.driver,
            };
            emitTenantRooms(dbName, "booking-no-show-event", noShowNotif);
        }

        if (followOnPayload) {
            const driverSocketId = getTenantSocket(driverSockets, dbName, booking.driver);
            if (driverSocketId) {
                io.to(driverSocketId).emit("new-ride-request", {
                    booking_id: followOnPayload.id,
                    assignment_type: "allocate_driver",
                    message: "You have a follow-on ride request",
                    booking: followOnPayload
                });
                console.log(`[FollowOn] new-ride-request sent to driver #${booking.driver}`);
            } else {
                console.log(`[FollowOn] Driver #${booking.driver} not connected via socket`);
            }
        }

        if (followOnEventData) {
            emitTenantRooms(dbName, "follow-on-job-sent-to-driver", followOnEventData);
        }

	        await broadcastDashboardCardsUpdate(req.tenantDb);

	        return res.json({
	            success: true,
	            message: "Booking status updated successfully",
	            res_user,
	            data: {
	                id: updatedBooking.id,
	                booking_id: updatedBooking.booking_id,
	                booking_status,
	                database: dbName,
	                booking: { ...updatedBooking, database: dbName },
	            },
	        });

    } catch (error) {
        console.error("Error updating booking status:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/bookings/:id/send-confirmation-email", async (req, res) => {
    try {
        const { id } = req.params;
        const { dispatcher_name } = req.body;
        const dispatcherName = dispatcher_name || "Dispatcher";

        const db = getConnection(req.tenantDb);

        await db.query(
            "UPDATE bookings SET dispatcher_action = ? WHERE id = ?",
            [`${dispatcherName} sent a booking confirmation email to the customer`, id]
        );

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
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = bookings[0];

        if (!booking.email) {
            return res.status(400).json({ success: false, message: "Booking does not have an email address" });
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
            from: getMailFrom(),
            to: booking.email,
            subject: `Booking Confirmation - ${booking.booking_id}`,
            html: emailHtml
        };

        const info = await transporter.sendMail(mailOptions);

        console.log(`Email sent successfully to ${booking.email}`);
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
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/bookings/broadcast", async (req, res) => {
    try {
        const { booking_id, tenantDb, pre_booking } = req.body;
        const DB_PREFIX = "tenant";

        const finalDb = `${DB_PREFIX}${tenantDb}`;
        const dbName = tenantDb;

        console.log("Using DB:", finalDb);

        const db = getConnection(finalDb);

        const [rows] = await db.query(
            "SELECT * FROM bookings WHERE id = ?",
            [booking_id]
        );

        if (!rows.length) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = rows[0];
        let sentCount = 0;

        if (dbName) {
            emitTenantRooms(dbName, "new-booking-event", booking);
            sentCount = 1;
        }

        await broadcastDashboardCardsUpdate(finalDb);

        if (!pre_booking) {
            try {
                const plotId = await waitingQueue.resolvePlotIdFromBooking(db, booking);
                if (plotId && !booking.pickup_plot_id) {
                    await db.query('UPDATE bookings SET pickup_plot_id = ? WHERE id = ?', [plotId, booking.id]);
                    booking.pickup_plot_id = plotId;
                }

                await waitingQueue.refreshPlotWaitingQueueForBooking(finalDb, booking, booking.id);
            } catch (queueError) {
                console.error('[WaitingQueue] Failed to refresh queue for new booking:', queueError.message);
            }
        }

        return res.json({
            success: true,
            sent_to: sentCount,
            booking
        });

    } catch (error) {
        console.error("Broadcast error:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/bookings/notify-updated", async (req, res) => {
    try {
        const authHeader = req.headers.authorization || "";
        const token = authHeader.startsWith("Bearer ") ? authHeader.slice(7) : "";
        if (!token || token !== process.env.NODE_INTERNAL_SECRET) {
            return res.status(401).json({ success: false, message: "Unauthorized" });
        }

        const { booking_id, tenantDb } = req.body;
        if (!booking_id || !tenantDb) {
            return res.status(400).json({ success: false, message: "booking_id and tenantDb are required" });
        }

        const finalDb = toTenantDbName(tenantDb);
        const db = getConnection(finalDb);

        const [bookings] = await db.query(`
            SELECT
                b.*,
                d.id as driver_id,
                d.name as driver_name,
                d.email as driver_email,
                d.phone_no as driver_phone,
                d.profile_image as driver_profile_image,
                vt.id as vehicle_type_id,
                vt.vehicle_type_name,
                vt.vehicle_type_service,
                sc.id as sub_company_id,
                sc.name as sub_company_name,
                sc.email as sub_company_email,
                a.id as account_row_id,
                a.name as account_name,
                a.email as account_email,
                a.company as account_company
            FROM bookings b
            LEFT JOIN drivers d ON b.driver = d.id
            LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
            LEFT JOIN sub_companies sc ON b.sub_company = sc.id
            LEFT JOIN accounts a ON a.id = b.account
            WHERE b.id = ?
        `, [booking_id]);

        if (!bookings.length) {
            return res.status(404).json({ success: false, message: "Booking not found" });
        }

        const booking = bookings[0];
        const todayDate = await getTenantTodayDate(db);
        const {
            driver_id, driver_name, driver_email, driver_phone, driver_profile_image,
            vehicle_type_id, vehicle_type_name, vehicle_type_service,
            sub_company_id, sub_company_name, sub_company_email,
            account_row_id, account_name, account_email, account_company,
            ...bookingData
        } = booking;

        const formattedBooking = {
            ...bookingData,
            pre_booking: isPreBookingRow(bookingData, todayDate),
            account_id: bookingData.account,
            driverDetail: driver_id ? {
                id: driver_id,
                name: driver_name,
                email: driver_email,
                phone_no: driver_phone,
                profile_image: driver_profile_image,
            } : null,
            vehicleDetail: vehicle_type_id ? {
                id: vehicle_type_id,
                vehicle_type_name,
                vehicle_type_service,
            } : null,
            subCompanyDetail: sub_company_id ? {
                id: sub_company_id,
                name: sub_company_name,
                email: sub_company_email,
            } : null,
            accountDetail: account_row_id ? {
                id: account_row_id,
                name: account_name,
                email: account_email,
                company: account_company,
            } : null,
        };

        const dbName = tenantDb.toString();
        emitTenantRooms(dbName, "booking-updated-event", formattedBooking);

        await broadcastDashboardCardsUpdate(finalDb);

        return res.json({ success: true, booking: formattedBooking });
    } catch (error) {
        console.error("Booking notify-updated error:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/send-new-ride", async (req, res) => {
    try {
        const { drivers, booking, tenantDb } = req.body;
        const resolvedTenantDb = tenantDb || req.tenantDb;
        const dbName = resolvedTenantDb ? toTenantSocketName(resolvedTenantDb) : null;
        const db = getConnection(resolvedTenantDb);
        let sentCount = 0;

        for (const driverId of drivers) {
            const isAccepted = booking.booking_status === 'ongoing';
            const isAdminAssigned = booking.assigned_by_admin === true || booking.assignment_source === "admin";
            const title = isAdminAssigned || isAccepted ? "New Ride Assigned" : "New Ride Available";
            const message = booking.assignment_message
                || (isAccepted
                    ? `You have been assigned a new ride #${booking.booking_id}. It is already accepted.`
                    : "You have a new ride request");

            // Send Push Notification
            try {
                await sendNotificationToDriver(db, driverId, title, message, {
                    booking_id: String(booking.id),
                    type: "new_ride",
                    assignment_source: booking.assignment_source || null,
                    assignment_type: booking.assignment_type || null,
                });
            } catch (notifErr) {
                console.error("Notification error in /send-new-ride:", notifErr.message);
            }

            try {
                await storeNotification(db, {
                    user_type: "driver",
                    user_id: driverId,
                    title,
                    message,
                });
            } catch (storeErr) {
                console.error("Store notification error in /send-new-ride:", storeErr.message);
            }

            const socketId = getTenantSocket(driverSockets, dbName, driverId);
            if (socketId) {
                if (isAccepted) {
                    io.to(socketId).emit("new-ride", booking);
                    io.to(socketId).emit("booking-status-updated", {
                        booking_id: booking.id,
                        status: booking.booking_status,
                        message: message
                    });
                } else {
                    io.to(socketId).emit("new-ride-request", {
                        booking_id: booking.id,
                        assignment_source: booking.assignment_source || null,
                        assignment_type: booking.assignment_type || null,
                        assigned_by_admin: isAdminAssigned,
                        message: message,
                        booking: booking
                    });
                }
                sentCount++;
            }
        }
        return res.json({ success: true, sent_to: sentCount });
    } catch (error) {
        console.error("/send-new-ride error:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.post("/send-notification-dispatcher", (req, res) => {
    console.log("mmediate");
    const { dispatchers, booking } = req.body;
    const dbName = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.body?.tenantDb || req.tenantDb);
    let sentCount = 0;
    dispatchers.forEach(dispatcherId => {
        const socketId = getTenantSocket(dispatcherSockets, dbName, dispatcherId);
        if (socketId) {
            io.to(socketId).emit("notification-ride", { ...booking, database: dbName });
            sentCount++;
        }
    });
    return res.json({ success: true, sent_to: sentCount });
});

app.post("/change-cancel-ride", async (req, res) => {
    const { status, booking } = req.body;
    const drivers = Array.isArray(req.body.drivers) ? req.body.drivers : [];
    const dbName = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.tenantDb);
    const db = getConnection(req.tenantDb);

    let persistedBooking = null;
    try {
        const [rows] = await db.query("SELECT user_id, driver FROM bookings WHERE id = ?", [booking.id]);
        persistedBooking = rows[0] || null;
    } catch (dbErr) {
        console.error("Error fetching booking for cancellation notification:", dbErr.message);
    }

    let targetUserId = booking.user_id || persistedBooking?.user_id;
    const assignedDriverId = booking.driver || persistedBooking?.driver || null;

    if (targetUserId) {
        try {
            const userNotifTitle = "Ride Cancelled";
            const userNotifMessage = req.body.cancelled_by === 'user' ? `Your ride #${booking.booking_id} has been successfully cancelled.` : `Your ride #${booking.booking_id} has been cancelled by Admin or Dispatcher.`;
            await sendNotificationToUser(db, targetUserId, userNotifTitle, userNotifMessage, {
                booking_id: String(booking.id),
                type: "ride_cancelled"
            });
            await storeNotification(db, {
                user_type: 'rider',
                user_id: targetUserId,
                title: userNotifTitle,
                message: userNotifMessage
            });
            console.log("Cancel notification sent to user:", targetUserId);
        } catch (userNotifErr) {
            console.error("User Notification error in /change-cancel-ride:", userNotifErr.message);
        }
    }

    if (assignedDriverId) {
        const driverNotifTitle = "Ride Cancelled";
        const driverNotifMessage = req.body.cancelled_by === 'user' ? `Ride #${booking.booking_id} has been cancelled by customer` : `Ride #${booking.booking_id} has been cancelled`;

        try {
            await sendNotificationToDriver(db, assignedDriverId, driverNotifTitle, driverNotifMessage, {
                booking_id: String(booking.id),
                type: "ride_cancelled"
            });
            await storeNotification(db, {
                user_type: 'driver',
                user_id: assignedDriverId,
                title: driverNotifTitle,
                message: driverNotifMessage
            });
        } catch (driverNotifErr) {
            console.error("Driver Notification error in /change-cancel-ride:", driverNotifErr.message);
        }
    }

    let sentCount = 0;
    const socketDriverIds = Array.from(new Set([
        ...drivers.map((driverId) => String(driverId)),
        ...(assignedDriverId ? [String(assignedDriverId)] : []),
    ].filter(Boolean)));

    socketDriverIds.forEach(driverId => {
        const socketId = getTenantSocket(driverSockets, dbName, driverId);
        if (socketId) {
            io.to(socketId).emit("driver-ride-status-event", { status, booking });
            sentCount++;
        }
    });

    const cancelNotif = {
        booking_id: booking.id,
        id: booking.id,
        booking_reference: booking.booking_id,
        booking_status: 'cancelled',
        status: 'cancelled',
        booking: booking,
        message: req.body.cancelled_by === 'user' ? `Booking #${booking.booking_id} has been cancelled by customer` : `Booking #${booking.booking_id} has been cancelled`
    };
    emitTenantRooms(dbName, "booking-cancelled-event", cancelNotif);
    socketDriverIds.forEach(driverId => {
        const socketId = getTenantSocket(driverSockets, dbName, driverId);
        if (socketId) {
            io.to(socketId).emit("booking-cancelled-event", cancelNotif);
        }
    });

    if (req.tenantDb) {
        await broadcastDashboardCardsUpdate(req.tenantDb);
    }

    return res.json({ success: true, sent_to: sentCount });
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
    return res.json({ success: true, sent_to: sentCount });
});

app.post("/bid-accept", async (req, res) => {
    const { driverId, booking } = req.body;
    const dbName = toTenantSocketName(req.tenantDb || req.headers.database || req.headers['x-database']);
    const socketId = getTenantSocket(driverSockets, dbName, driverId);
    if (socketId) {
        io.to(socketId).emit("bid-accept-event", { ...booking, database: dbName });
    }

    if (req.tenantDb) {
        await broadcastDashboardCardsUpdate(req.tenantDb);
    }

    return res.json({ success: true });
});

app.post("/place-bid", (req, res) => {
    const { userId, bid } = req.body;
    const dbName = toTenantSocketName(req.tenantDb || req.headers.database || req.headers['x-database']);
    const socketId = getTenantSocket(userSockets, dbName, userId);
    if (socketId) {
        io.to(socketId).emit("place-bid-event", { ...bid, database: dbName });
    }
    return res.json({ success: true });
});

app.post("/waiting-time-event", (req, res) => {
    const { userId, status, booking } = req.body;
    const dbName = toTenantSocketName(req.tenantDb || req.headers.database || req.headers['x-database']);
    const socketId = getTenantSocket(userSockets, dbName, userId);
    if (socketId) {
        io.to(socketId).emit("waiting-time-event", { status, booking, database: dbName });
    }
    return res.json({ success: true });
});

const reconcileDriverStatusForRideEvent = async ({ db, database, booking, status }) => {
    if (!db || !booking?.id) return { driverId: booking?.driver || null };

    const [bookingRows] = await db.query(
        "SELECT id, booking_id, driver, booking_status FROM bookings WHERE id = ? LIMIT 1",
        [booking.id]
    );
    const persistedBooking = bookingRows[0] || {};
    const driverId = booking.driver || persistedBooking.driver;
    if (!driverId) return { driverId: null };

    const activeStatuses = ["arrived_driver", "ride_started"];
    const terminalStatuses = ["complete_current_ride", "no_show", "driver_no_show", "cancel_confirm_ride", "cancel_ride"];

    let nextDriverStatus = null;
    if (activeStatuses.includes(status)) {
        nextDriverStatus = "busy";
    } else if (terminalStatuses.includes(status)) {
        const [activeRows] = await db.query(
            `SELECT COUNT(*) AS active_count
             FROM bookings
             WHERE driver = ?
               AND id <> ?
               AND booking_status IN ('ongoing', 'arrived', 'started')`,
            [driverId, booking.id]
        );
        nextDriverStatus = Number(activeRows[0]?.active_count || 0) > 0 ? "busy" : "idle";
    }

    if (nextDriverStatus) {
        await db.query("UPDATE drivers SET driving_status = ?, updated_at = NOW() WHERE id = ?", [nextDriverStatus, driverId]);
        await emitDriverStatusForTenant({
            db,
            database,
            driverId,
            reason: `ride_${status}`,
        });
    }

    return { driverId, driverStatus: nextDriverStatus };
};

app.post("/change-ride-status", async (req, res) => {
    const { userId, status, booking } = req.body;
    const dbName = toTenantSocketName(req.tenantDb || req.headers.database || req.headers['x-database']);
    const normalizedStatus = status === "driver_no_show" ? "no_show" : status;
    const db = req.tenantDb ? getConnection(req.tenantDb) : null;
    if (status === "cancel_confirm_ride" || status === "cancel_ride") {
        const targetUserId = userId || booking.user_id;

        if (targetUserId) {
            try {
                const userNotifTitle = "Ride Cancelled";
                const userNotifMessage = status === "cancel_confirm_ride" ? `Your ride #${booking.booking_id} has been successfully cancelled.` : `Your ride #${booking.booking_id} has been cancelled.`;
                await sendNotificationToUser(db, targetUserId, userNotifTitle, userNotifMessage, {
                    booking_id: String(booking.id),
                    type: "ride_cancelled"
                });
                await storeNotification(db, {
                    user_type: 'rider',
                    user_id: targetUserId,
                    title: userNotifTitle,
                    message: userNotifMessage
                });
            } catch (err) {
                console.error("Notification error in /change-ride-status (user):", err.message);
            }
        }

        if (booking.driver) {
            try {
                await sendNotificationToDriver(db, booking.driver, "Ride Cancelled", `Ride #${booking.booking_id} has been cancelled`, {
                    booking_id: String(booking.id),
                    type: "ride_cancelled"
                });
                await storeNotification(db, {
                    user_type: 'driver',
                    user_id: booking.driver,
                    title: "Ride Cancelled",
                    message: `Ride #${booking.booking_id} has been cancelled`
                });
            } catch (err) {
                console.error("Notification error in /change-ride-status (driver):", err.message);
            }
        }
    }

    const socketId = getTenantSocket(userSockets, dbName, userId);
    if (socketId) {
        io.to(socketId).emit("user-ride-status-event", { status, booking, database: dbName });
    }

    if (booking?.id && ["no_show", "driver_no_show", "arrived_driver", "ride_started", "complete_current_ride"].includes(status)) {
        const bookingStatus =
            status === "arrived_driver" ? "arrived" :
            status === "ride_started" ? "started" :
            status === "complete_current_ride" ? "completed" :
            normalizedStatus;
        const driverState = db
            ? await reconcileDriverStatusForRideEvent({ db, database: dbName, booking, status })
            : { driverId: booking.driver };

        const statusPayload = {
            id: booking.id,
            booking_id: booking.id,
            booking_reference: booking.booking_id,
            booking_status: bookingStatus,
            status: bookingStatus,
            database: dbName,
            booking: { ...booking, driver: driverState.driverId || booking.driver, booking_status: bookingStatus, database: dbName },
            driver_id: driverState.driverId || booking.driver,
            driver_name: booking.driverDetail?.name || booking.driver_name,
            message: bookingStatus === "no_show"
                ? `Booking #${booking.booking_id} marked as no show`
                : `Booking #${booking.booking_id} status updated to ${bookingStatus}`,
        };

        emitTenantRooms(dbName, "booking-status-updated", statusPayload);
        emitTenantRooms(dbName, "booking-updated-event", statusPayload.booking);

        if (bookingStatus === "no_show") {
            emitTenantRooms(dbName, "booking-no-show-event", statusPayload);
        }
    }

    if (status === "cancel_confirm_ride" || status === "cancel_ride") {
        const cancelNotif = {
            booking_id: booking.id,
            id: booking.id,
            booking_reference: booking.booking_id,
            booking_status: 'cancelled',
            status: 'cancelled',
            booking,
            message: status === "cancel_confirm_ride" ? `Booking #${booking.booking_id} has been cancelled by customer` : `Booking #${booking.booking_id} has been cancelled`
        };
        emitTenantRooms(dbName, "booking-cancelled-event", cancelNotif);
    }

    if (req.tenantDb) {
        await broadcastDashboardCardsUpdate(req.tenantDb);
    }

    return res.json({ success: true });
});

app.post("/user-message-notification", (req, res) => {
    const { userId, chat } = req.body;
    const dbName = toTenantSocketName(req.tenantDb || req.headers.database || req.headers['x-database'] || req.body?.database || req.body?.tenantDb);
    const socketId = getTenantSocket(userSockets, dbName, userId);
    if (socketId) {
        io.to(socketId).emit("user-message-event", { ...chat, database: dbName });
    }
    return res.json({ success: true });
});

app.post("/driver-message-notification", (req, res) => {
    const { driverId, chat } = req.body;

    if (!driverId || !chat) {
        return res.status(400).json({ success: false, message: "Missing driverId or chat" });
    }

    const dbName = toTenantSocketName(req.tenantDb || req.headers.database || req.headers['x-database'] || req.body?.database || req.body?.tenantDb);
    const socketId = getTenantSocket(driverSockets, dbName, driverId);
    let delivered = false;

    if (socketId) {
        io.to(socketId).emit("driver-message-event", { ...chat, database: dbName });
        delivered = true;
    }

    return res.json({ success: true, delivered });
});

app.post("/driver-force-logout", async (req, res) => {
    try {
        const { driverId } = req.body;

        if (!driverId) {
            return res.status(400).json({ success: false, message: "Missing driverId" });
        }

        if (!req.tenantDb) {
            const dbHeader = req.headers['database'] || req.headers['x-database'];
            if (dbHeader) {
                req.tenantDb = toTenantDbName(dbHeader);
            }
        }

        if (!req.tenantDb) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const dbName = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.tenantDb);
        const db = getConnection(req.tenantDb);
        const driverIdStr = driverId.toString();

        await db.query("UPDATE drivers SET online_status = 'offline' WHERE id = ?", [driverId]);

        const [rows] = await db.query(
            "SELECT plot_id, name FROM drivers WHERE id = ? LIMIT 1",
            [driverId]
        );
        const plotId = rows[0]?.plot_id;
        const driverName = rows[0]?.name;

        await removeFromQueue(driverId, dbName);
        const driverRuntimeKey = tenantSocketKey(dbName, driverIdStr);
        if (driverRuntimeKey) {
            driverLastLocationTime.delete(driverRuntimeKey);
            knownDriverRuntimeKeys.delete(driverRuntimeKey);
            driverLocationCache.delete(driverRuntimeKey);
            driverLocationPersistTime.delete(driverRuntimeKey);
            driverLocationPersistCoalescer.clear(driverRuntimeKey);
        }

        if (driverRuntimeKey && driverDisconnectTimers.has(driverRuntimeKey)) {
            clearTimeout(driverDisconnectTimers.get(driverRuntimeKey));
            driverDisconnectTimers.delete(driverRuntimeKey);
        }

        if (plotId) {
            broadcastUpdatedQueue(plotId, dbName);
        }
        await broadcastFullQueueToDrivers(dbName);

        const driverSocketId = getTenantSocket(driverSockets, dbName, driverIdStr);
        if (driverSocketId) {
            io.to(driverSocketId).emit("driver-forced-offline", {
                driver_id: driverId,
                message: "You have been logged out by dispatch.",
                reason: "dispatcher_logout",
                token_revoked: true,
                auth_version: req.body.auth_version ?? null,
            });
        }

        const offlineData = {
            driver_id: driverId,
            driver_name: driverName,
            online_status: "offline",
            reason: "dispatcher_logout",
        };

        io.to(`dispatcher_${dbName}`).emit("driver-offline-event", offlineData);
        io.to(`admin_${dbName}`).emit("driver-offline-event", offlineData);
        io.to(`client_${dbName}`).emit("driver-offline-event", offlineData);

        return res.json({ success: true });
    } catch (error) {
        console.error("Driver force logout error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

const normalizeCompanyStatus = (status) => {
    const value = (status ?? "").toString().toLowerCase().trim();

    if (["inactive", "deactive", "disabled", "disable", "0", "false"].includes(value)) {
        return "inactive";
    }

    if (!value || ["active", "1", "true", "enable", "enabled"].includes(value)) {
        return "active";
    }

    return value;
};

const getRoomClientCount = (roomName) => {
    const room = io.sockets.adapter.rooms.get(roomName);
    return room ? room.size : 0;
};

const emitCompanyStatusChanged = (req, res) => {
    try {
        const authHeader = req.headers.authorization || "";
        const token = authHeader.startsWith("Bearer ") ? authHeader.slice(7) : "";
        if (!token || token !== process.env.NODE_INTERNAL_SECRET) {
            console.warn("Company status changed: unauthorized internal call");
            return res.status(401).json({ success: false, message: "Unauthorized" });
        }

        const clientId = req.body.client_id
            || req.headers["database"]
            || req.headers["x-database"];

        if (!clientId) {
            return res.status(400).json({ success: false, message: "client_id is required" });
        }

        const dbName = clientId.toString();
        const previousStatus = normalizeCompanyStatus(req.body.previous_status || "active");
        const newStatus = normalizeCompanyStatus(req.body.new_status || "inactive");

        if (previousStatus !== "active" || newStatus !== "inactive") {
            console.log("Company status changed skipped", {
                client_id: dbName,
                previous_status: previousStatus,
                new_status: newStatus,
            });
            return res.json({
                success: true,
                skipped: true,
                message: "No active-to-inactive transition to broadcast",
                client_id: dbName,
                previous_status: previousStatus,
                new_status: newStatus,
            });
        }

        const payload = {
            title: "Company deactivated",
            description: "Your company has been deactivated. You have been logged out.",
            message: "Your company has been deactivated. You have been logged out.",
            type: "force_logout",
            action: "force_logout",
            reason: "company_inactive",
            status: newStatus,
            previous_status: previousStatus,
            new_status: newStatus,
            token_revoked: true,
            source: "company_status",
            client_id: dbName,
            changed_at: req.body.changed_at || new Date().toISOString(),
        };

        const rooms = [
            `client_${dbName}`,
            `admin_${dbName}`,
            `dispatcher_${dbName}`,
            dbName,
        ];

        const roomCounts = {};
        for (const roomName of rooms) {
            io.to(roomName).emit("company-status-changed", payload);
            io.to(roomName).emit("company-inactive-logout", payload);
            roomCounts[roomName] = getRoomClientCount(roomName);
        }

        io.to(`dispatcher_${dbName}`).emit("dispatcher-forced-logout", {
            message: payload.message,
            reason: payload.reason,
            token_revoked: payload.token_revoked,
        });

        console.log("Company status changed emitted", {
            client_id: dbName,
            room_counts: roomCounts,
            payload,
        });

        return res.json({
            success: true,
            emitted: true,
            client_id: dbName,
            room_counts: roomCounts,
        });
    } catch (error) {
        console.error("Company status changed notification error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
};

app.post("/company/status-changed", emitCompanyStatusChanged);
app.post("/socket-api/company/status-changed", emitCompanyStatusChanged);
app.post("/company-inactive-logout", emitCompanyStatusChanged);
app.post("/socket-api/company-inactive-logout", emitCompanyStatusChanged);

app.post("/dispatch-settings-changed", async (req, res) => {
    try {
        const authHeader = req.headers.authorization || "";
        const token = authHeader.startsWith("Bearer ") ? authHeader.slice(7) : "";
        if (!token || token !== process.env.NODE_INTERNAL_SECRET) {
            return res.status(401).json({ success: false, message: "Unauthorized" });
        }

        const clientId = req.body.client_id
            || req.headers["database"]
            || req.headers["x-database"];

        if (!clientId) {
            return res.status(400).json({ success: false, message: "client_id is required" });
        }

        const dbName = clientId.toString();
        const excludeSocketId = req.body.exclude_socket_id || null;

        const payload = {
            title: "Dispatch settings updated",
            description: "Auto dispatch configuration was changed. Please refresh the page to load the latest settings.",
            message: "Auto dispatch configuration was changed. Please refresh the page to load the latest settings.",
            type: "refresh_required",
            action: "refresh_page",
            source: "dispatch_settings",
            client_id: dbName,
            changed_at: req.body.changed_at || new Date().toISOString(),
        };

        const room = io.to(`client_${dbName}`);
        if (excludeSocketId) {
            room.except(excludeSocketId).emit("dispatch-settings-changed", payload);
        } else {
            room.emit("dispatch-settings-changed", payload);
        }

        return res.json({ success: true });
    } catch (error) {
        console.error("Dispatch settings changed notification error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

app.post("/dispatcher-force-logout-all", async (req, res) => {
    try {
        const authHeader = req.headers.authorization || "";
        const token = authHeader.startsWith("Bearer ") ? authHeader.slice(7) : "";
        if (!token || token !== process.env.NODE_INTERNAL_SECRET) {
            return res.status(401).json({ success: false, message: "Unauthorized" });
        }

        if (!req.tenantDb) {
            const dbHeader = req.headers['database'] || req.headers['x-database'];
            if (dbHeader) {
                req.tenantDb = toTenantDbName(dbHeader);
            }
        }

        if (!req.tenantDb) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const dbName = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.tenantDb);

        io.to(`dispatcher_${dbName}`).emit("dispatcher-forced-logout", {
            message: "You have been logged out by admin",
            reason: "admin_logout_all",
            token_revoked: true,
        });

        return res.json({ success: true });
    } catch (error) {
        console.error("Dispatcher force logout all error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

app.post("/change-driver-ride-status", async (req, res) => {
    const { driverId, status, booking } = req.body;
    const dbName = toTenantSocketName(req.tenantDb || req.headers.database || req.headers['x-database'] || req.body?.database || req.body?.tenantDb);
    if (status === "cancel_confirm_ride" || status === "cancel_ride") {
        let targetUserId = booking.user_id;
        const db = req.tenantDb ? getConnection(req.tenantDb) : null;

        if (!db) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        if (!targetUserId) {
            try {
                const [rows] = await db.query("SELECT user_id FROM bookings WHERE id = ?", [booking.id]);
                if (rows.length > 0) {
                    targetUserId = rows[0].user_id;
                }
            } catch (dbErr) {
                console.error("Error fetching user_id for /change-driver-ride-status:", dbErr.message);
            }
        }

        if (targetUserId) {
            try {
                const userNotifTitle = "Ride Cancelled";
                const userNotifMessage = status === "cancel_confirm_ride" ? `Your ride #${booking.booking_id} has been successfully cancelled.` : `Your ride #${booking.booking_id} has been cancelled.`;
                await sendNotificationToUser(db, targetUserId, userNotifTitle, userNotifMessage, {
                    booking_id: String(booking.id),
                    type: "ride_cancelled"
                });
                await storeNotification(db, {
                    user_type: 'rider',
                    user_id: targetUserId,
                    title: userNotifTitle,
                    message: userNotifMessage
                });
                console.log("Cancel notification sent to user:", targetUserId);
            } catch (userNotifErr) {
                console.error("User Notification error in /change-driver-ride-status:", userNotifErr.message);
            }
        }
    }

    const socketId = getTenantSocket(driverSockets, dbName, driverId);
    if (socketId) {
        io.to(socketId).emit("driver-ride-status-event", { status, booking, database: dbName });
    }

    if (status === "cancel_confirm_ride" || status === "cancel_ride") {
        const cancelNotif = {
            booking_id: booking.id,
            id: booking.id,
            booking_reference: booking.booking_id,
            booking_status: 'cancelled',
            status: 'cancelled',
            booking: booking,
            database: dbName,
            message: status === "cancel_confirm_ride" ? `Booking #${booking.booking_id} has been cancelled by customer` : `Booking #${booking.booking_id} is cancelled by Admin or Dispatcher`
        };
        emitTenantRooms(dbName, "booking-cancelled-event", cancelNotif);
        if (socketId) {
            io.to(socketId).emit("booking-cancelled-event", cancelNotif);
        }
    }

    if (req.tenantDb) {
        await broadcastDashboardCardsUpdate(req.tenantDb);
    }

    return res.json({ success: true });
});

app.post("/on-job-driver", async (req, res) => {
    try {
        const { clientId, driver_id, driverName } = req.body;
        console.log(`🚕 On-Job Driver Request: clientId=${clientId}, driver_id=${driver_id}, driverName=${driverName}`);

        if (!req.tenantDb) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const db = getConnection(req.tenantDb);
        let finalDriverName = driverName;
        let finalDriverId = driver_id;

        if (driver_id) {
            const [driverRows] = await db.query("SELECT id, name FROM drivers WHERE id = ?", [driver_id]);
            if (driverRows.length > 0) {
                finalDriverName = driverRows[0].name;
                finalDriverId = driverRows[0].id;
            }
        }

        const dbName = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.tenantDb);

        const eventData = {
            driver_id: finalDriverId,
            driverName: finalDriverName,
            driver_name: finalDriverName,
            status: 'busy',
            driving_status: 'busy',
            database: dbName,
        };

        const socketId = getTenantSocket(clientSockets, dbName, clientId);
        if (socketId) {
            io.to(socketId).emit("on-job-driver-event", eventData);
        }

        if (dbName) {
            emitTenantOnJobDriver(dbName, eventData);

            if (finalDriverId) {
                const [plotRows] = await db.query('SELECT plot_id FROM drivers WHERE id = ? LIMIT 1', [finalDriverId]);
                const plotId = plotRows[0]?.plot_id;
                await removeFromQueue(finalDriverId, dbName);
                if (plotId) {
                    broadcastUpdatedQueue(plotId, dbName);
                }
            }
        }

        await broadcastDashboardCardsUpdate(req.tenantDb);

        return res.json({ success: true });
    } catch (error) {
        console.error("On-Job Driver Error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

app.post("/update-driver-rank", async (req, res) => {
    try {
        const { driver_id, plot_id, rank } = req.body;

        if (!req.tenantDb) {
            const dbHeader = req.headers['database'] || req.headers['x-database'];
            if (dbHeader) {
                req.tenantDb = toTenantDbName(dbHeader);
            }
        }

        if (!req.tenantDb) {
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        if (!driver_id || !plot_id || rank == null) {
            return res.status(400).json({ success: false, message: "Missing driver_id, plot_id, or rank" });
        }

        const dbName = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.tenantDb);
        const result = await applyDriverRankUpdate(dbName, plot_id, driver_id, rank);

        if (result.success !== 1) {
            return res.status(400).json({ success: 0, message: result.message });
        }

        return res.json(result);
    } catch (error) {
        console.error("Update Driver Rank Error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

app.post("/waiting-driver", async (req, res) => {
    try {
        const { clientId, driver_id } = req.body;

        if (!req.tenantDb) {
            const dbHeader = req.headers['database'] || req.headers['x-database'];
            if (dbHeader) {
                req.tenantDb = toTenantDbName(dbHeader);
            }
        }

        if (!req.tenantDb) {
            console.error("Waiting Driver: Missing req.tenantDb and database header");
            return res.status(400).json({ success: false, message: "Missing database header" });
        }

        const db = getConnection(req.tenantDb);

        const [driverRows] = await db.query(
            `SELECT d.id, d.name, d.driving_status, d.online_status, d.plot_id, d.latitude, d.longitude,
                    p.name AS plot_name, d.priority_plot, d.updated_at
             FROM drivers d
             LEFT JOIN plots p ON d.plot_id = p.id
             WHERE d.id = ? 
             LIMIT 1`,
            [driver_id]
        );

        if (!driverRows.length) {
            console.error(`Waiting Driver: Driver ${driver_id} not found in ${req.tenantDb}`);
            return res.status(404).json({ success: false, message: "Driver not found" });
        }

        const driver = driverRows[0];
        const plotId = driver.plot_id;
        const dbName = toTenantSocketName(req.headers['database'] || req.headers['x-database'] || req.tenantDb);

        // ✅ FIX: plot_name already comes from LEFT JOIN — real name like "USA"
        const plotName = driver.plot_name || (plotId ? `Plot #${plotId}` : "N/A");

        let rank = 1;
        if (plotId) {
            const [rankRows] = await db.query(
                `SELECT COUNT(*) as count 
                 FROM drivers 
                 WHERE plot_id = ? AND driving_status = ? AND (updated_at < ? OR (updated_at = ? AND id < ?))`,
                [plotId, driver.driving_status, driver.updated_at, driver.updated_at, driver.id]
            );
            rank = rankRows[0].count + 1;
        }

        const eventData = {
            driver_id: driver.id,
            driverName: driver.name,
            driver_name: driver.name,
            plot: plotId,
            plot_id: plotId,
            plot_name: plotName,   // ✅ Real name e.g. "USA"
            rank: rank,
            status: driver.driving_status,
            driving_status: driver.driving_status,
            online_status: driver.online_status,
            latitude: driver.latitude,
            longitude: driver.longitude,
            database: dbName,
        };

        const socketId = getTenantSocket(clientSockets, dbName, clientId);
        if (socketId) {
            io.to(socketId).emit("waiting-driver-event", eventData);
        }

        if (dbName) {
            emitTenantWaitingDriver(dbName, eventData);

            const driverSocketId = getTenantSocket(driverSockets, dbName, driver_id);
            if (driverSocketId) {
                io.to(driverSocketId).emit("waiting-driver-event", eventData);
            }
        }

        await broadcastDashboardCardsUpdate(req.tenantDb);

        return res.json({ success: true });
    }
    catch (error) {
        console.error("Waiting Driver Error:", error);
        return res.status(500).json({ success: false, message: "Internal server error" });
    }
});

app.post("/send-reminder", (req, res) => {
    const {
        clientId,
        tenantDb,
        database,
        title,
        description,
        message,
        booking_id,
        booking_reference,
        pickup_location,
        pickup_time,
        booking_date,
        reminder_minutes,
        driver_id,
    } = req.body;

    const dbName = (tenantDb || database || clientId || "").toString();
    const payload = {
        title,
        description: description || message,
        message: message || description,
        booking_id,
        booking_reference,
        pickup_location,
        pickup_time,
        booking_date,
        reminder_minutes,
    };

    if (clientId) {
        const socketId = getTenantSocket(clientSockets, dbName, clientId);
        if (socketId) {
            io.to(socketId).emit("send-reminder", payload);
        }
    }

    if (dbName) {
        io.to(`dispatcher_${dbName}`).emit("send-reminder", payload);
        io.to(`admin_${dbName}`).emit("send-reminder", payload);
        io.to(`client_${dbName}`).emit("send-reminder", payload);
    }

    if (driver_id) {
        const driverSocketId = getTenantSocket(driverSockets, dbName, driver_id);
        if (driverSocketId) {
            io.to(driverSocketId).emit("send-reminder", payload);
        }
    }

    return res.json({ success: true });
});

app.post("/voip-webhook", async (req, res) => {
    try {
        const { token, events } = req.body;

        if (token !== process.env.VIP_WEBHOOK_TOKEN) {
            console.log("Invalid VOIP token");
            return res.status(403).json({ success: false });
        }

        if (!Array.isArray(events)) {
            return res.status(400).json({ success: false, message: "Invalid events format" });
        }

        for (const event of events) {

            const { callId, dialledNumber, extension, callerId, status, time } = event;

            const voipData = {
                callId,
                dialledNumber,
                extension,
                callerId,
                status,
                time: new Date(time * 1000),
            };

            dispatcherSockets.forEach((socketId) => {
                io.to(socketId).emit("voip-call-update", voipData);
            });

            adminSockets.forEach((socketId) => {
                io.to(socketId).emit("voip-call-update", voipData);
            });
        }

        return res.status(200).json({ success: true });
    } catch (error) {
        console.error("Webhook Error:", error);
        return res.status(500).json({ success: false });
    }
});

app.get("/contact-us", async (req, res) => {
    try {
        const db = getConnection(req.tenantDb);

        const { type, status, search, page = 1, limit = 10 } = req.query;

        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const offset = (pageNum - 1) * limitNum;

        let baseQuery = `FROM contact_us WHERE 1=1`;
        const params = [];

        if (type && ['user', 'driver'].includes(type)) {
            baseQuery += ` AND user_type = ?`;
            params.push(type);
        }

        if (status) {
            baseQuery += ` AND status = ?`;
            params.push(status);
        }

        if (search) {
            baseQuery += ` AND message LIKE ?`;
            params.push(`%${search}%`);
        }

        const dataQuery = `SELECT * ${baseQuery} ORDER BY id DESC LIMIT ? OFFSET ?`;
        const [list] = await db.query(dataQuery, [...params, limitNum, offset]);

        const countQuery = `SELECT COUNT(*) AS total ${baseQuery}`;
        const [[{ total }]] = await db.query(countQuery, params);

        return res.json({
            success: true,
            data: list,
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
        console.error("Contact Us fetch error:", error);
        return res.status(500).json({ success: false, error: error.message });
    }
});

app.get("/driver/:id/riding-details", async (req, res) => {
    try {
        const { id } = req.params;
        const {
            page = 1,
            limit = 10,
            start_date,
            end_date,
            status
        } = req.query;

        const databaseHeader =
            req.headers["x-database"] ||
            req.headers["database"] ||
            req.query.database;

        if (!databaseHeader) {
            return res.status(400).json({
                success: false,
                message: "Database header is required"
            });
        }

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [driverRows] = await db.query(
            `SELECT id, name, email, phone_no, profile_image, driving_status,
                    plot_id, priority_plot, wallet_balance, last_settlement_date,
                    created_at
             FROM drivers WHERE id = ?`,
            [id]
        );

        if (!driverRows.length) {
            return res.status(404).json({
                success: false,
                message: "Driver not found"
            });
        }

        const driver = driverRows[0];

        const [revenueSummary] = await db.query(
            `SELECT
                COUNT(*) AS total_rides,

                COUNT(CASE WHEN booking_status = 'completed' THEN 1 END) AS completed_rides,
                COUNT(CASE WHEN booking_status = 'cancelled' THEN 1 END) AS cancelled_rides,
                COUNT(CASE WHEN booking_status = 'ongoing' THEN 1 END) AS ongoing_rides,
                COUNT(CASE WHEN booking_status = 'arrived' THEN 1 END) AS arrived_rides,
                COUNT(CASE WHEN booking_status = 'no_show' THEN 1 END) AS no_show_rides,
                COUNT(CASE WHEN booking_status = 'pending' THEN 1 END) AS pending_rides,

                COALESCE(SUM(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE 0
                        END
                    ELSE 0 END
                ), 0) AS total_revenue,

                COALESCE(SUM(
                    CASE
                        WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                        WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                        WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                        ELSE 0
                    END
                ), 0) AS gross_fare_all_rides,

                COALESCE(AVG(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE NULL
                        END
                    ELSE NULL END
                ), 0) AS average_fare

             FROM bookings
             WHERE driver = ?`,
            [id]
        );

        const revenue = revenueSummary[0];

        const [accountCounts] = await db.query(
            `SELECT
                b.account AS account_id,
                a.name AS account_name,
                a.company AS account_company,
                a.email AS account_email,
                COUNT(*) AS job_count,
                COUNT(CASE WHEN b.booking_status = 'completed' THEN 1 END) AS completed,
                COUNT(CASE WHEN b.booking_status = 'cancelled' THEN 1 END) AS cancelled,
                COUNT(CASE WHEN b.booking_status = 'pending' THEN 1 END) AS pending,
                COUNT(CASE WHEN b.booking_status = 'ongoing' THEN 1 END) AS ongoing,
                COUNT(CASE WHEN b.booking_status = 'arrived' THEN 1 END) AS arrived,
                COUNT(CASE WHEN b.booking_status = 'no_show' THEN 1 END) AS no_show
             FROM bookings b
             LEFT JOIN accounts a ON a.id = b.account
             WHERE (b.driver = ? OR b.pending_driver_id = ?)
               AND b.account IS NOT NULL
               AND TRIM(b.account) != ''
             GROUP BY b.account, a.name, a.company, a.email
             ORDER BY job_count DESC, b.account ASC`,
            [id, id]
        );

        const accountJobCount = accountCounts.reduce(
            (sum, row) => sum + Number(row.job_count || 0),
            0
        );

        const [todayStats] = await db.query(
            `SELECT
                COUNT(*) AS today_total_rides,
                COUNT(CASE WHEN booking_status = 'completed' THEN 1 END) AS today_completed,
                COALESCE(SUM(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE 0
                        END
                    ELSE 0 END
                ), 0) AS today_revenue
             FROM bookings
             WHERE driver = ? AND DATE(booking_date) = CURDATE()`,
            [id]
        );

        const [weekStats] = await db.query(
            `SELECT
                COUNT(*) AS week_total_rides,
                COUNT(CASE WHEN booking_status = 'completed' THEN 1 END) AS week_completed,
                COALESCE(SUM(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE 0
                        END
                    ELSE 0 END
                ), 0) AS week_revenue
             FROM bookings
             WHERE driver = ? AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)`,
            [id]
        );

        const [monthStats] = await db.query(
            `SELECT
                COUNT(*) AS month_total_rides,
                COUNT(CASE WHEN booking_status = 'completed' THEN 1 END) AS month_completed,
                COALESCE(SUM(
                    CASE WHEN booking_status = 'completed' THEN
                        CASE
                            WHEN booking_amount IS NOT NULL AND booking_amount > 0 THEN booking_amount
                            WHEN offered_amount IS NOT NULL AND offered_amount > 0 THEN offered_amount
                            WHEN recommended_amount IS NOT NULL AND recommended_amount > 0 THEN recommended_amount
                            ELSE 0
                        END
                    ELSE 0 END
                ), 0) AS month_revenue
             FROM bookings
             WHERE driver = ?
               AND MONTH(booking_date) = MONTH(CURDATE())
               AND YEAR(booking_date) = YEAR(CURDATE())`,
            [id]
        );

        const pageNum = Math.max(parseInt(page) || 1, 1);
        const limitNum = Math.max(parseInt(limit) || 10, 1);
        const offset = (pageNum - 1) * limitNum;

        let rideWhereClause = `WHERE b.driver = ?`;
        const rideParams = [id];

        if (start_date) {
            rideWhereClause += ` AND DATE(b.booking_date) >= ?`;
            rideParams.push(start_date);
        }
        if (end_date) {
            rideWhereClause += ` AND DATE(b.booking_date) <= ?`;
            rideParams.push(end_date);
        }
        if (status) {
            rideWhereClause += ` AND b.booking_status = ?`;
            rideParams.push(status);
        }

        const [rides] = await db.query(
            `SELECT
                b.id,
                b.booking_id,
                b.name AS passenger_name,
                b.phone_no AS passenger_phone,
                b.email AS passenger_email,
                b.pickup_location,
                b.destination_location,
                b.booking_date,
                b.pickup_time,
                b.booking_status,
                b.booking_amount,
                b.offered_amount,
                b.recommended_amount,
                b.cancel_reason,
                b.created_at,
                b.updated_at,
                vt.vehicle_type_name,
                vt.vehicle_type_service,
                sc.name AS sub_company_name,
                CASE
                    WHEN b.booking_amount IS NOT NULL AND b.booking_amount > 0 THEN b.booking_amount
                    WHEN b.offered_amount IS NOT NULL AND b.offered_amount > 0 THEN b.offered_amount
                    WHEN b.recommended_amount IS NOT NULL AND b.recommended_amount > 0 THEN b.recommended_amount
                    ELSE 0
                END AS effective_fare
             FROM bookings b
             LEFT JOIN vehicle_types vt ON b.vehicle = vt.id
             LEFT JOIN sub_companies sc ON b.sub_company = sc.id
             ${rideWhereClause}
             ORDER BY b.booking_date DESC, b.id DESC
             LIMIT ? OFFSET ?`,
            [...rideParams, limitNum, offset]
        );

        const [[{ total }]] = await db.query(
            `SELECT COUNT(*) AS total FROM bookings b ${rideWhereClause}`,
            rideParams
        );

        const totalPages = Math.ceil(total / limitNum);

        return res.json({
            success: true,
            data: {
                driver: {
                    id: driver.id,
                    name: driver.name,
                    email: driver.email,
                    phone_no: driver.phone_no,
                    profile_image: driver.profile_image,
                    driving_status: driver.driving_status,
                    plot_id: driver.plot_id,
                    priority_plot: driver.priority_plot,
                    wallet_balance: parseFloat(driver.wallet_balance || 0).toFixed(2),
                    last_settlement_date: driver.last_settlement_date,
                    member_since: driver.created_at
                },

                revenue_summary: {
                    total_revenue: parseFloat(revenue.total_revenue).toFixed(2),
                    gross_fare_all_rides: parseFloat(revenue.gross_fare_all_rides).toFixed(2),
                    average_fare: parseFloat(revenue.average_fare).toFixed(2),
                    today: {
                        rides: todayStats[0].today_total_rides,
                        completed: todayStats[0].today_completed,
                        revenue: parseFloat(todayStats[0].today_revenue).toFixed(2)
                    },
                    this_week: {
                        rides: weekStats[0].week_total_rides,
                        completed: weekStats[0].week_completed,
                        revenue: parseFloat(weekStats[0].week_revenue).toFixed(2)
                    },
                    this_month: {
                        rides: monthStats[0].month_total_rides,
                        completed: monthStats[0].month_completed,
                        revenue: parseFloat(monthStats[0].month_revenue).toFixed(2)
                    }
                },

                ride_statistics: {
                    total_rides: revenue.total_rides,
                    completed: revenue.completed_rides,
                    cancelled: revenue.cancelled_rides,
                    ongoing: revenue.ongoing_rides,
                    arrived: revenue.arrived_rides,
                    no_show: revenue.no_show_rides,
                    pending: revenue.pending_rides,
                    account_job_count: accountJobCount,
                    account_counts: accountCounts.map((row) => ({
                        account_id: row.account_id,
                        account: row.account_id,
                        name: row.account_name,
                        company: row.account_company,
                        email: row.account_email,
                        job_count: row.job_count,
                        completed: row.completed,
                        cancelled: row.cancelled,
                        pending: row.pending,
                        ongoing: row.ongoing,
                        arrived: row.arrived,
                        no_show: row.no_show,
                    })),
                    completion_rate: revenue.total_rides > 0
                        ? ((revenue.completed_rides / revenue.total_rides) * 100).toFixed(1) + "%"
                        : "0.0%"
                },

                rides: rides,

                pagination: {
                    total,
                    page: pageNum,
                    limit: limitNum,
                    total_pages: totalPages,
                    hasNext: pageNum < totalPages,
                    hasPrev: pageNum > 1
                }
            }
        });

    } catch (error) {
        console.error("Driver riding details error:", error);
        return res.status(500).json({
            success: false,
            message: error.message
        });
    }
});

app.post("/account/collect-and-email", async (req, res) => {
    try {
        const { account_id } = req.body;
        if (!account_id) {
            return res.status(400).json({ success: 0, message: "Account ID is required" });
        }

        const databaseHeader = req.headers["x-database"] || req.headers["database"];
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [accountRows] = await db.query("SELECT * FROM accounts WHERE id = ?", [account_id]);
        if (!accountRows.length) {
            return res.status(404).json({ success: 0, message: "Account not found" });
        }
        const account = accountRows[0];

        const [bookings] = await db.query(`
            SELECT id as booking_id, booking_date as date, 
                   COALESCE(booking_amount, offered_amount, recommended_amount, 0) as amount,
                   CONCAT(pickup_location, ' to ', destination_location) as route
            FROM bookings 
            WHERE account = ? AND account_payment = 'no'
        `, [account_id]);

        if (!bookings.length) {
            return res.status(400).json({ success: 0, message: "No uncollected rides found for this account" });
        }

        const totalAmount = bookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);
        const dateOfCollection = new Date().toLocaleDateString();

        const ridesTableRows = bookings.map(b => `
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: left;">#${b.booking_id}</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: left;">${new Date(b.date).toLocaleString()}</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: left;">${b.route || 'N/A'}</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: #333;">$${parseFloat(b.amount || 0).toFixed(2)}</td>
            </tr>
        `).join('');

        const htmlContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
                .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .header { background-color: #2c3e50; color: #ffffff; padding: 20px; text-align: center; }
                .header h2 { margin: 0; font-size: 24px; letter-spacing: 1px; }
                .body-content { padding: 30px; }
                .account-info { margin-bottom: 25px; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #3498db; border-radius: 4px; }
                .account-info p { margin: 5px 0; color: #555; font-size: 15px; }
                .total-amount-box { text-align: center; background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; padding: 20px; border-radius: 8px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(46, 204, 113, 0.3); }
                .total-amount-box h3 { margin: 0; font-size: 16px; font-weight: normal; text-transform: uppercase; letter-spacing: 1px; }
                .total-amount-box h1 { margin: 10px 0 0; font-size: 38px; }
                .rides-table-container { overflow-x: auto; }
                .rides-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                .rides-table th { background-color: #ecf0f1; padding: 12px 10px; text-align: left; font-size: 14px; color: #34495e; border-bottom: 2px solid #bdc3c7; }
                .rides-table td { font-size: 14px; color: #555; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #aaa; border-top: 1px solid #eee; background-color: #fafafa; }
                @media only screen and (max-width: 600px) {
                    .body-content { padding: 15px; }
                    .total-amount-box h1 { font-size: 32px; }
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="header">
                    <h2>Invoice & Ride Collection</h2>
                </div>
                
                <div class="body-content">
                    <div class="account-info">
                        <p><strong>Account Name:</strong> ${account.name}</p>
                        <p><strong>Email:</strong> ${account.email}</p>
                        ${account.company ? `<p><strong>Company:</strong> ${account.company}</p>` : ''}
                        <p><strong>Collection Date:</strong> ${dateOfCollection}</p>
                    </div>

                    <div class="total-amount-box">
                        <h3>Total Collected Amount</h3>
                        <h1>$${totalAmount.toFixed(2)}</h1>
                    </div>
                </div>

                <div class="footer">
                    <p>Thank you for choosing our services.</p>
                    <p>&copy; ${new Date().getFullYear()} Cabify IT. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        `;

        const mailOptions = {
            from: getMailFrom(),
            to: account.email,
            subject: 'Invoice & Ride Collection Summary',
            html: htmlContent
        };

        await transporter.sendMail(mailOptions);

        await db.query(`
            UPDATE bookings 
            SET account_payment = 'yes' 
            WHERE account = ? AND account_payment = 'no'
        `, [account_id]);

        return res.json({
            success: 1,
            message: "Email sent successfully and rides marked as collected."
        });

    } catch (err) {
        console.error("Account Collect & Email Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/driver/send-invoice', async (req, res) => {
    try {
        const { driver_id } = req.body;
        if (!driver_id) return res.status(400).json({ success: 0, message: "Driver ID is required" });

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) return res.status(400).json({ success: 0, message: "Database header is required" });

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [driverRows] = await db.query("SELECT * FROM drivers WHERE id = ?", [driver_id]);
        if (!driverRows.length) return res.status(404).json({ success: 0, message: "Driver not found" });
        const driver = driverRows[0];

        if (!driver.email) return res.status(400).json({ success: 0, message: "Driver email not found" });

        const [rides] = await db.query(`
            SELECT id, booking_id, booking_date, pickup_location, destination_location, booking_amount 
            FROM bookings 
            WHERE driver = ? AND booking_status = 'completed' 
            ORDER BY booking_date DESC LIMIT 50
        `, [driver_id]);

        let totalAmount = rides.reduce((sum, r) => sum + parseFloat(r.booking_amount || 0), 0);

        const doc = new PDFDocument({ margin: 50 });
        let buffers = [];
        doc.on('data', buffers.push.bind(buffers));
        doc.on('end', async () => {
            try {
                let pdfData = Buffer.concat(buffers);

                const mailOptions = {
                    from: getMailFrom(),
                    to: driver.email,
                    subject: 'Your Driver Invoice',
                    text: 'Please find attached your invoice details and completed rides.',
                    attachments: [{
                        filename: `invoice-${driver_id}.pdf`,
                        content: pdfData,
                        contentType: 'application/pdf'
                    }]
                };

                await transporter.sendMail(mailOptions);
                return res.json({
                    success: 1,
                    message: "Invoice sent successfully",
                    pdf_base64: pdfData.toString('base64')
                });
            } catch (mailErr) {
                console.error("Email Sending Error:", mailErr);
                return res.status(500).json({ success: 0, message: "Failed to send email" });
            }
        });

        doc.fontSize(20).text('Driver Invoice', { align: 'center' });
        doc.moveDown();
        doc.fontSize(12).text(`Driver Name: ${driver.name}`);
        doc.text(`Email: ${driver.email}`);
        doc.text(`Phone: ${driver.phone_no || 'N/A'}`);
        doc.text(`Date: ${new Date().toLocaleDateString()}`);
        doc.moveDown();

        doc.fontSize(14).text('Recent Completed Rides Summary', { underline: true });
        doc.moveDown();

        rides.forEach((r, idx) => {
            doc.fontSize(10).text(`${idx + 1}. Booking ID: ${r.booking_id} | Date: ${new Date(r.booking_date).toLocaleDateString()} | Amount: $${(parseFloat(r.booking_amount) || 0).toFixed(2)}`);
        });

        doc.moveDown();
        doc.fontSize(14).text(`Total Amount: $${totalAmount.toFixed(2)}`, { align: 'right' });

        doc.end();

    } catch (err) {
        console.error("Driver Invoice Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/user/send-invoice', async (req, res) => {
    try {
        const { user_id } = req.body;
        if (!user_id) return res.status(400).json({ success: 0, message: "User ID is required" });

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) return res.status(400).json({ success: 0, message: "Database header is required" });

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [userRows] = await db.query("SELECT * FROM users WHERE id = ?", [user_id]);
        if (!userRows.length) return res.status(404).json({ success: 0, message: "User not found" });
        const user = userRows[0];

        if (!user.email) return res.status(400).json({ success: 0, message: "User email not found" });

        const [rides] = await db.query(`
            SELECT id, booking_id, booking_date, pickup_location, destination_location, booking_amount 
            FROM bookings 
            WHERE user_id = ? AND booking_status = 'completed' 
            ORDER BY booking_date DESC LIMIT 50
        `, [user_id]);

        let totalAmount = rides.reduce((sum, r) => sum + parseFloat(r.booking_amount || 0), 0);

        const doc = new PDFDocument({ margin: 50 });
        let buffers = [];
        doc.on('data', buffers.push.bind(buffers));
        doc.on('end', async () => {
            try {
                let pdfData = Buffer.concat(buffers);

                const mailOptions = {
                    from: getMailFrom(),
                    to: user.email,
                    subject: 'Your User Invoice',
                    text: 'Please find attached your invoice details and completed rides.',
                    attachments: [{
                        filename: `invoice-${user_id}.pdf`,
                        content: pdfData,
                        contentType: 'application/pdf'
                    }]
                };

                await transporter.sendMail(mailOptions);
                return res.json({
                    success: 1,
                    message: "Invoice sent successfully",
                    pdf_base64: pdfData.toString('base64')
                });
            } catch (mailErr) {
                console.error("Email Sending Error:", mailErr);
                return res.status(500).json({ success: 0, message: "Failed to send email" });
            }
        });

        doc.fontSize(20).text('User Invoice', { align: 'center' });
        doc.moveDown();
        doc.fontSize(12).text(`User Name: ${user.name || user.first_name || 'N/A'}`);
        doc.text(`Email: ${user.email}`);
        doc.text(`Phone: ${user.phone || user.mobile || 'N/A'}`);
        doc.text(`Date: ${new Date().toLocaleDateString()}`);
        doc.moveDown();

        doc.fontSize(14).text('Recent Completed Rides Summary', { underline: true });
        doc.moveDown();

        rides.forEach((r, idx) => {
            doc.fontSize(10).text(`${idx + 1}. Booking ID: ${r.booking_id} | Date: ${new Date(r.booking_date).toLocaleDateString()} | Amount: $${(parseFloat(r.booking_amount) || 0).toFixed(2)}`);
        });

        doc.moveDown();
        doc.fontSize(14).text(`Total Amount: $${totalAmount.toFixed(2)}`, { align: 'right' });

        doc.end();

    } catch (err) {
        console.error("User Invoice Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/account/send-invoice', async (req, res) => {
    try {
        const { account_id } = req.body;
        if (!account_id) {
            return res.status(400).json({ success: 0, message: "Account ID is required" });
        }

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [accountRows] = await db.query("SELECT * FROM accounts WHERE id = ?", [account_id]);
        if (!accountRows.length) {
            return res.status(404).json({ success: 0, message: "Account not found" });
        }
        const account = accountRows[0];

        const [bookings] = await db.query(`
            SELECT 
                id as booking_id, 
                booking_id as booking_reference,
                booking_date, 
                pickup_time,
                pickup_location, 
                destination_location,
                COALESCE(booking_amount, offered_amount, recommended_amount, 0) as amount,
                booking_status,
                name as passenger_name,
                phone_no as passenger_phone
            FROM bookings 
            WHERE account = ? 
            ORDER BY booking_date DESC, id DESC
        `, [account_id]);

        if (!bookings.length) {
            return res.status(400).json({ success: 0, message: "No bookings found for this account" });
        }

        const totalAmount = bookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);
        const completedBookings = bookings.filter(b => b.booking_status === 'completed');
        const completedAmount = completedBookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);

        const doc = new PDFDocument({ margin: 50 });
        let buffers = [];
        doc.on('data', buffers.push.bind(buffers));
        doc.on('end', async () => {
            try {
                let pdfData = Buffer.concat(buffers);

                if (account.email) {
                    try {
                        const mailOptions = {
                            from: getMailFrom(),
                            to: account.email,
                            subject: `Invoice for Account - ${account.name || account.company}`,
                            text: 'Please find attached your invoice with all booking details.',
                            attachments: [{
                                filename: `account-invoice-${account_id}.pdf`,
                                content: pdfData,
                                contentType: 'application/pdf'
                            }]
                        };

                        await transporter.sendMail(mailOptions);
                        console.log(`Invoice email sent to account: ${account.email}`);
                    } catch (emailErr) {
                        console.error("Email sending failed:", emailErr.message);
                    }
                }
                return res.json({
                    success: 1,
                    message: "Account invoice sent successfully",
                    account_id: account_id,
                    account_name: account.name || account.company,
                    email: account.email,
                    total_bookings: bookings.length,
                    total_amount: totalAmount.toFixed(2),
                    completed_bookings: completedBookings.length,
                    completed_amount: completedAmount.toFixed(2),
                    pdf_base64: pdfData.toString('base64')
                });

            } catch (err) {
                console.error("Account Invoice Error:", err);
                return res.status(500).json({ success: 0, message: "Failed to generate PDF" });
            }
        });

        doc.fontSize(20).text('Account Invoice', { align: 'center' });
        doc.moveDown();

        doc.fontSize(14).text('Account Information', { underline: true });
        doc.fontSize(12);
        doc.text(`Account Name: ${account.name || 'N/A'}`);
        if (account.company) doc.text(`Company: ${account.company}`);
        doc.text(`Email: ${account.email}`);
        if (account.phone) doc.text(`Phone: ${account.phone}`);
        doc.text(`Invoice Date: ${new Date().toLocaleDateString()}`);
        doc.moveDown();

        doc.fontSize(14).text('Summary', { underline: true });
        doc.fontSize(12);
        doc.text(`Total Bookings: ${bookings.length}`);
        doc.text(`Completed Bookings: ${completedBookings.length}`);
        doc.text(`Total Amount: $${totalAmount.toFixed(2)}`);
        doc.text(`Completed Amount: $${completedAmount.toFixed(2)}`);
        doc.moveDown();

        doc.fontSize(14).text('Booking Details', { underline: true });
        doc.moveDown();

        bookings.forEach((booking, idx) => {
            doc.fontSize(10);
            doc.text(`${idx + 1}. Booking ID: ${booking.booking_reference}`);
            doc.text(`   Date: ${new Date(booking.booking_date).toLocaleDateString()} ${booking.pickup_time || ''}`);
            doc.text(`   Route: ${booking.pickup_location} -> ${booking.destination_location}`);
            doc.text(`   Passenger: ${booking.passenger_name} (${booking.passenger_phone})`);
            doc.text(`   Status: ${booking.booking_status}`);
            doc.text(`   Amount: $${parseFloat(booking.amount || 0).toFixed(2)}`);
            doc.moveDown(0.5);
        });

        doc.moveDown();
        doc.fontSize(10).text('This is a computer-generated invoice.', { align: 'center' });
        doc.text(`Generated on: ${new Date().toLocaleString()}`, { align: 'center' });

        doc.end();

    } catch (err) {
        console.error("Account Invoice Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/sub-company/send-invoice', async (req, res) => {
    try {
        const { sub_company_id } = req.body;
        if (!sub_company_id) {
            return res.status(400).json({ success: 0, message: "Sub Company ID is required" });
        }

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) {
            return res.status(400).json({ success: 0, message: "Database header is required" });
        }

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [subCompanyRows] = await db.query("SELECT * FROM sub_companies WHERE id = ?", [sub_company_id]);
        if (!subCompanyRows.length) {
            return res.status(404).json({ success: 0, message: "Sub company not found" });
        }
        const subCompany = subCompanyRows[0];

        const [bookings] = await db.query(`
            SELECT 
                id as booking_id, 
                booking_id as booking_reference,
                booking_date, 
                pickup_time,
                pickup_location, 
                destination_location,
                COALESCE(booking_amount, offered_amount, recommended_amount, 0) as amount,
                booking_status,
                name as passenger_name,
                phone_no as passenger_phone
            FROM bookings 
            WHERE sub_company = ? 
            ORDER BY booking_date DESC, id DESC
        `, [sub_company_id]);

        if (!bookings.length) {
            return res.status(400).json({ success: 0, message: "No bookings found for this sub company" });
        }

        const totalAmount = bookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);
        const completedBookings = bookings.filter(b => b.booking_status === 'completed');
        const completedAmount = completedBookings.reduce((sum, b) => sum + parseFloat(b.amount || 0), 0);

        const doc = new PDFDocument({ margin: 50 });
        let buffers = [];
        doc.on('data', buffers.push.bind(buffers));
        doc.on('end', async () => {
            try {
                let pdfData = Buffer.concat(buffers);

                if (subCompany.email) {
                    try {
                        const mailOptions = {
                            from: getMailFrom(),
                            to: subCompany.email,
                            subject: `Invoice for Sub Company - ${subCompany.name || 'N/A'}`,
                            text: 'Please find attached your invoice with all booking details.',
                            attachments: [{
                                filename: `sub-company-invoice-${sub_company_id}.pdf`,
                                content: pdfData,
                                contentType: 'application/pdf'
                            }]
                        };

                        await transporter.sendMail(mailOptions);
                        console.log(`Invoice email sent to sub company: ${subCompany.email}`);
                    } catch (emailErr) {
                        console.error("Email sending failed:", emailErr.message);
                    }
                }
                return res.json({
                    success: 1,
                    message: "Sub company invoice sent successfully",
                    sub_company_id: sub_company_id,
                    sub_company_name: subCompany.name,
                    email: subCompany.email,
                    total_bookings: bookings.length,
                    total_amount: totalAmount.toFixed(2),
                    completed_bookings: completedBookings.length,
                    completed_amount: completedAmount.toFixed(2),
                    pdf_base64: pdfData.toString('base64')
                });

            } catch (err) {
                console.error("Sub Company Invoice Error:", err);
                return res.status(500).json({ success: 0, message: "Failed to generate PDF" });
            }
        });

        doc.fontSize(20).text('Sub Company Invoice', { align: 'center' });
        doc.moveDown();

        doc.fontSize(14).text('Sub Company Information', { underline: true });
        doc.fontSize(12);
        doc.text(`Company Name: ${subCompany.name || 'N/A'}`);
        doc.text(`Email: ${subCompany.email || 'N/A'}`);
        if (subCompany.phone) doc.text(`Phone: ${subCompany.phone}`);
        if (subCompany.address) doc.text(`Address: ${subCompany.address}`);
        doc.text(`Invoice Date: ${new Date().toLocaleDateString()}`);
        doc.moveDown();

        doc.fontSize(14).text('Summary', { underline: true });
        doc.fontSize(12);
        doc.text(`Total Bookings: ${bookings.length}`);
        doc.text(`Completed Bookings: ${completedBookings.length}`);
        doc.text(`Total Amount: $${totalAmount.toFixed(2)}`);
        doc.text(`Completed Amount: $${completedAmount.toFixed(2)}`);
        doc.moveDown();

        doc.fontSize(14).text('Booking Details', { underline: true });
        doc.moveDown();

        bookings.forEach((booking, idx) => {
            doc.fontSize(10);
            doc.text(`${idx + 1}. Booking ID: ${booking.booking_reference}`);
            doc.text(`   Date: ${new Date(booking.booking_date).toLocaleDateString()} ${booking.pickup_time || ''}`);
            doc.text(`   Route: ${booking.pickup_location} -> ${booking.destination_location}`);
            doc.text(`   Passenger: ${booking.passenger_name} (${booking.passenger_phone || 'N/A'})`);
            doc.text(`   Status: ${booking.booking_status}`);
            doc.text(`   Amount: $${parseFloat(booking.amount || 0).toFixed(2)}`);
            doc.moveDown(0.5);
        });

        doc.moveDown();
        doc.fontSize(10).text('This is a computer-generated invoice.', { align: 'center' });
        doc.text(`Generated on: ${new Date().toLocaleString()}`, { align: 'center' });

        doc.end();

    } catch (err) {
        console.error("Sub Company Invoice Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

app.post('/driver/send-package-history', async (req, res) => {
    try {
        const { driver_id } = req.body;
        if (!driver_id) return res.status(400).json({ success: 0, message: "Driver ID is required" });

        const databaseHeader = req.headers["x-database"] || req.headers["database"] || req.query.database;
        if (!databaseHeader) return res.status(400).json({ success: 0, message: "Database header is required" });

        const tenantDb = toTenantDbName(databaseHeader);
        const db = getConnection(tenantDb);

        const [settingsRows] = await db.query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
        if (!settingsRows.length) return res.status(404).json({ success: 0, message: "Company settings not found" });
        const settings = settingsRows[0];

        const [driverRows] = await db.query("SELECT * FROM drivers WHERE id = ?", [driver_id]);
        if (!driverRows.length) return res.status(404).json({ success: 0, message: "Driver not found" });
        const driver = driverRows[0];

        if (!driver.email) return res.status(400).json({ success: 0, message: "Driver email not found" });

        const packageTypeMapping = {
            "per_ride_commission_top_up": "Per Ride Commission (Top Up)",
            "packages_top_up": "Packages (Top Up)",
            "commission_without_topup": "Commission without Top Up Settled Later",
            "packages_post_paid": "Packages Post Paid"
        };
        const packageTypeFormatted = packageTypeMapping[settings.package_type] || settings.package_type;

        let allEntries = [];
        if (settings.package_type === "packages_post_paid") {
            allEntries = await calculatePostPaidEntries(driver, settings, db);
        } else if (settings.package_type === "commission_without_topup") {
            allEntries = await calculatePercentageEntries(driver, settings, db);
        }

        const totalAmount = allEntries.reduce((sum, e) => sum + parseFloat(e.amount || 0), 0);

        const entriesHtml = allEntries.length > 0 ? allEntries.map((e, idx) => `
            <tr>
                <td>${idx + 1}</td>
                <td>${e.cycle_start_date} to ${e.cycle_end_date}</td>
                <td>${e.description}</td>
                <td>$${(parseFloat(e.amount) || 0).toFixed(2)}</td>
            </tr>
        `).join('') : '<tr><td colspan="4" style="text-align:center;">No history available</td></tr>';

        const htmlContent = `
            <h2>Driver Package History</h2>
            <p><strong>Driver Name:</strong> ${driver.name || 'N/A'}</p>
            <p><strong>Email:</strong> ${driver.email}</p>
            <p><strong>Phone:</strong> ${driver.phone_no || 'N/A'}</p>
            <p><strong>Package Type:</strong> ${packageTypeFormatted}</p>
            <p><strong>Date:</strong> ${new Date().toLocaleDateString()}</p>
            <h3>Package Details Summary</h3>
            <table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">
                <tr>
                    <th>#</th>
                    <th>Period</th>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
                ${entriesHtml}
            </table>
            <h3>Total Package Amount: $${totalAmount.toFixed(2)}</h3>
        `;

        const mailOptions = {
            from: getMailFrom(),
            to: driver.email,
            subject: 'Your Driver Package History',
            html: htmlContent
        };

        try {
            await transporter.sendMail(mailOptions);
            return res.json({
                success: 1,
                message: "Package history sent successfully",
                package_type: packageTypeFormatted,
                data: allEntries
            });
        } catch (mailErr) {
            console.error("Email Sending Error:", mailErr);
            return res.status(500).json({ success: 0, message: "Failed to send email" });
        }

    } catch (err) {
        console.error("Package History Error:", err);
        return res.status(500).json({ success: 0, message: err.message });
    }
});

setInterval(async () => {
    const now = Date.now();

    for (const [plotKey, queue] of plotDriverQueues.entries()) {
        if (!queue || queue.length === 0) continue;

        const parts = plotKey.split('_');
        if (parts.length < 2) continue;
        const plotId = parts[0];
        const database = parts.slice(1).join('_');

        try {
            const db = getConnection(toTenantDbName(database));
            const driverIds = queue.map(d => d.driver_id);

            const [drivers] = await db.query(
                `SELECT id, driving_status, online_status, plot_id FROM drivers WHERE id IN (?)`,
                [driverIds]
            );

            let queueChanged = false;

            for (const item of [...queue]) {
                const driverIdStr = item.driver_id;
                const runtimeKey = tenantSocketKey(database, driverIdStr);
                const driverDb = drivers.find(d => d.id.toString() === driverIdStr);

                const lastLocationTime = runtimeKey ? (driverLastLocationTime.get(runtimeKey) || 0) : 0;
                const isTimeout = lastLocationTime > 0 && (now - lastLocationTime) > LOCATION_TIMEOUT_MS;

                let shouldRemove = false;
                let reason = "";

                if (!driverDb) {
                    shouldRemove = true;
                    reason = "not found in database";
                } else if (driverDb.online_status !== 'online') {
                    shouldRemove = true;
                    reason = `online_status is '${driverDb.online_status}'`;
                } else if (driverDb.driving_status !== 'idle') {
                    shouldRemove = true;
                    reason = `driving_status is '${driverDb.driving_status}'`;
                } else if (isTimeout) {
                    shouldRemove = true;
                    reason = "no location update for 15+ min";
                }

                if (shouldRemove) {
                    console.log(`[QueueCheck] Removing driver #${driverIdStr} from ${plotKey}: ${reason}`);
                    await removeFromQueue(driverIdStr, database);
                    if (runtimeKey) driverLastLocationTime.delete(runtimeKey);
                    queueChanged = true;

                    if (isTimeout) {
                        try {
                            await db.query(
                                "UPDATE drivers SET online_status = 'offline' WHERE id = ?",
                                [driverIdStr]
                            );
                            console.log(`[QueueCheck] Driver #${driverIdStr} → offline (15 min location timeout)`);

                            const driverSocketId = getTenantSocket(driverSockets, database, driverIdStr);
                            if (driverSocketId) {
                                io.to(driverSocketId).emit("driver-forced-offline", {
                                    driver_id: driverIdStr,
                                    message: "You have been marked offline due to no location update for 15 minutes."
                                });
                            }

                            io.to(`dispatcher_${database}`).emit("driver-offline-event", {
                                driver_id: driverIdStr,
                                online_status: 'offline',
                                reason: "15 min location timeout"
                            });
                            io.to(`admin_${database}`).emit("driver-offline-event", {
                                driver_id: driverIdStr,
                                online_status: 'offline',
                                reason: "15 min location timeout"
                            });
                        } catch (offlineErr) {
                            console.error(`[QueueCheck] Offline update error for #${driverIdStr}:`, offlineErr.message);
                        }
                    }
                }
            }

            if (queueChanged) {
                broadcastUpdatedQueue(plotId, database);
                await broadcastFullQueueToDrivers(database);
            }

        } catch (err) {
            console.error(`[QueueCheck] Error for ${plotKey}:`, err.message);
        }
    }

}, 15 * 1000);



server.listen(SOCKET_SERVER_PORT, "0.0.0.0", SOCKET_LISTEN_BACKLOG, () => {
    console.log(`🚀 Socket server running on port ${SOCKET_SERVER_PORT} (backlog ${SOCKET_LISTEN_BACKLOG})`);
});
