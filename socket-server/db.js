const path = require("path");
const mysql = require("mysql2");
require("dotenv").config({ path: path.join(__dirname, "../.env") });

const REQUIRED_ENVS = [
    "DB_HOST",
    "DB_PORT",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD",
];

const validateRequiredEnv = (env, exitOnError = false) => {
    for (const key of REQUIRED_ENVS) {
        if (env[key] === undefined || env[key] === null) {
            const message = `Missing ENV variable: ${key}`;
            if (exitOnError) {
                console.error(message);
                process.exit(1);
            }
            throw new Error(message);
        }
    }
};

if (process.env.NODE_ENV !== "test") {
    validateRequiredEnv(process.env, true);
}

const intEnv = (env, name, fallback, min = 0) => {
    const raw = env[name];
    const parsed = Number.parseInt(raw, 10);
    if (Number.isNaN(parsed) || parsed < min) return fallback;
    return parsed;
};

const poolQueueSize = (pool, key) => {
    const value = pool?.[key];
    if (!value) return 0;
    if (typeof value.length === "number") return value.length;
    if (typeof value.size === "number") return value.size;
    return 0;
};

const createPoolRegistry = ({
    mysqlModule = mysql,
    env = process.env,
    now = () => Date.now(),
    setIntervalFn = setInterval,
    clearIntervalFn = clearInterval,
    unrefTimers = true,
} = {}) => {
    validateRequiredEnv(env);

    const centralDbName = env.DB_DATABASE;
    const tenantPoolLimit = intEnv(env, "SOCKET_DB_POOL_LIMIT", 2, 1);
    const centralPoolLimit = intEnv(env, "SOCKET_DB_CENTRAL_POOL_LIMIT", 5, 1);
    const idleMs = intEnv(env, "SOCKET_DB_POOL_IDLE_MS", 60_000, 1_000);
    const maxTenantPools = intEnv(env, "SOCKET_DB_MAX_TENANT_POOLS", 40, 1);
    const queueLimit = intEnv(env, "SOCKET_DB_QUEUE_LIMIT", 200, 0);
    const evictionIntervalMs = intEnv(env, "SOCKET_DB_POOL_EVICT_INTERVAL_MS", 15_000, 1_000);

    const pools = new Map();
    const pendingPools = new Map();
    const stats = {
        created: 0,
        evicted: 0,
        rejected: 0,
    };

    const isCentralDb = (dbName) => dbName === centralDbName;

    const poolConfigFor = (dbName) => ({
        host: env.DB_HOST,
        port: Number(env.DB_PORT) || 3306,
        user: env.DB_USERNAME,
        password: env.DB_PASSWORD,
        database: dbName,
        waitForConnections: true,
        connectionLimit: isCentralDb(dbName) ? centralPoolLimit : tenantPoolLimit,
        queueLimit,
        enableKeepAlive: true,
        keepAliveInitialDelay: 0,
    });

    const getPoolCounts = (pool) => {
        const total = poolQueueSize(pool, "_allConnections");
        const free = poolQueueSize(pool, "_freeConnections");
        const queued = poolQueueSize(pool, "_connectionQueue");
        return {
            total,
            free,
            active: Math.max(total - free, 0),
            queued,
        };
    };

    const canEvict = (entry, force = false) => {
        if (!entry || entry.isCentral) return false;
        if (!force && now() - entry.lastUsedAt < idleMs) return false;
        return getPoolCounts(entry.pool).active === 0;
    };

    const closeEntry = async (dbName, entry, reason = "idle") => {
        if (!entry || entry.closing) return false;
        entry.closing = true;
        pools.delete(dbName);
        try {
            await entry.pool.end();
            stats.evicted += 1;
            console.log(`[DB] Closed MySQL pool for ${dbName} (${reason})`);
            return true;
        } catch (error) {
            console.error(`[DB] Failed closing pool for ${dbName}:`, error.message);
            return false;
        }
    };

    const evictIdlePools = async ({ forceOldest = false } = {}) => {
        const entries = [...pools.entries()]
            .filter(([, entry]) => !entry.isCentral)
            .sort((a, b) => a[1].lastUsedAt - b[1].lastUsedAt);

        let evicted = 0;
        for (const [dbName, entry] of entries) {
            if (canEvict(entry, forceOldest)) {
                if (await closeEntry(dbName, entry, forceOldest ? "capacity" : "idle")) {
                    evicted += 1;
                }
            }
        }
        return evicted;
    };

    const activeTenantPoolCount = () =>
        [...pools.values()].filter((entry) => !entry.isCentral).length;

    const enforceTenantPoolLimit = async () => {
        if (activeTenantPoolCount() < maxTenantPools) return;

        const evicted = await evictIdlePools({ forceOldest: false });
        if (evicted > 0 || activeTenantPoolCount() < maxTenantPools) return;

        await evictIdlePools({ forceOldest: true });
        if (activeTenantPoolCount() < maxTenantPools) return;

        stats.rejected += 1;
        const error = new Error("Tenant DB pool limit reached");
        error.code = "SOCKET_DB_POOL_LIMIT_REACHED";
        throw error;
    };

    const createPoolEntry = async (dbName) => {
        if (!isCentralDb(dbName)) {
            await enforceTenantPoolLimit();
        }

        console.log(`[DB] Creating MySQL pool for database: ${dbName}`);
        const pool = mysqlModule.createPool(poolConfigFor(dbName));
        const entry = {
            dbName,
            pool,
            promisePool: pool.promise(),
            isCentral: isCentralDb(dbName),
            createdAt: now(),
            lastUsedAt: now(),
            closing: false,
        };
        pools.set(dbName, entry);
        stats.created += 1;
        return entry;
    };

    const getOrCreatePoolEntry = (dbName) => {
        const existing = pools.get(dbName);
        if (existing && !existing.closing) {
            existing.lastUsedAt = now();
            return Promise.resolve(existing);
        }

        if (!pendingPools.has(dbName)) {
            pendingPools.set(
                dbName,
                createPoolEntry(dbName).finally(() => pendingPools.delete(dbName))
            );
        }

        return pendingPools.get(dbName);
    };

    const getConnection = (databaseName) => {
        if (!databaseName) {
            console.warn(
                "Warning: getConnection called without a databaseName. Falling back to default central DB:",
                centralDbName
            );
        }

        const dbName = databaseName || centralDbName;
        const lazyPromisePool = new Proxy({}, {
            get(_target, prop) {
                return async (...args) => {
                    const entry = await getOrCreatePoolEntry(dbName);
                    entry.lastUsedAt = now();
                    const value = entry.promisePool[prop];
                    return typeof value === "function" ? value.apply(entry.promisePool, args) : value;
                };
            },
        });

        return lazyPromisePool;
    };

    const getPoolStats = () => ({
        config: {
            tenantPoolLimit,
            centralPoolLimit,
            idleMs,
            maxTenantPools,
            queueLimit,
            evictionIntervalMs,
        },
        counters: { ...stats },
        pools: [...pools.entries()].map(([dbName, entry]) => ({
            dbName,
            isCentral: entry.isCentral,
            ageMs: now() - entry.createdAt,
            idleMs: now() - entry.lastUsedAt,
            ...getPoolCounts(entry.pool),
        })),
        pendingPoolCount: pendingPools.size,
    });

    const shutdown = async () => {
        if (evictionTimer) {
            clearIntervalFn(evictionTimer);
            evictionTimer = null;
        }

        await Promise.all(
            [...pools.entries()].map(([dbName, entry]) => closeEntry(dbName, entry, "shutdown"))
        );
    };

    let evictionTimer = setIntervalFn(() => {
        evictIdlePools().catch((error) => {
            console.error("[DB] Pool eviction error:", error.message);
        });
    }, evictionIntervalMs);

    if (unrefTimers && typeof evictionTimer.unref === "function") {
        evictionTimer.unref();
    }

    return {
        getConnection,
        getPoolStats,
        evictIdlePools,
        shutdown,
        _pools: pools,
    };
};

let registry = process.env.NODE_ENV === "test" ? null : createPoolRegistry();

const getDefaultRegistry = () => {
    if (!registry) {
        registry = createPoolRegistry();
    }
    return registry;
};

if (process.env.NODE_ENV !== "test") {
    (async () => {
        try {
            const db = getDefaultRegistry().getConnection();
            await db.query("SELECT 1");
            console.log("MySQL connected successfully");
        } catch (err) {
            console.error("MySQL connection failed:", err.message);
            process.exit(1);
        }
    })();
}

module.exports = {
    getConnection: (...args) => getDefaultRegistry().getConnection(...args),
    getPoolStats: (...args) => getDefaultRegistry().getPoolStats(...args),
    evictIdlePools: (...args) => getDefaultRegistry().evictIdlePools(...args),
    createPoolRegistry,
};
