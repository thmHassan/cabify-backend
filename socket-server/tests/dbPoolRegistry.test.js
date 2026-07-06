process.env.NODE_ENV = 'test';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createPoolRegistry } = require('../db');

const makeFakeMysql = () => {
    const createdPools = [];

    return {
        createdPools,
        createPool(config) {
            const pool = {
                config,
                ended: false,
                _allConnections: [],
                _freeConnections: [],
                _connectionQueue: [],
                promise() {
                    return {
                        query: async () => [[], []],
                        getConnection: async () => ({ release: () => {} }),
                    };
                },
                end: async () => {
                    pool.ended = true;
                },
            };
            createdPools.push(pool);
            return pool;
        },
    };
};

const testEnv = {
    DB_HOST: '127.0.0.1',
    DB_PORT: '3306',
    DB_DATABASE: 'central',
    DB_USERNAME: 'root',
    DB_PASSWORD: 'secret',
    SOCKET_DB_POOL_LIMIT: '2',
    SOCKET_DB_CENTRAL_POOL_LIMIT: '5',
    SOCKET_DB_POOL_IDLE_MS: '1000',
    SOCKET_DB_MAX_TENANT_POOLS: '1',
    SOCKET_DB_QUEUE_LIMIT: '7',
    SOCKET_DB_POOL_EVICT_INTERVAL_MS: '5000',
};

test('pool registry uses small tenant pools and larger central pool', async () => {
    const mysql = makeFakeMysql();
    const registry = createPoolRegistry({
        mysqlModule: mysql,
        env: testEnv,
        now: () => 0,
        setIntervalFn: () => ({ unref: () => {} }),
        clearIntervalFn: () => {},
    });

    await registry.getConnection().query('SELECT 1');
    await registry.getConnection('tenantalpha31').query('SELECT 1');

    assert.equal(mysql.createdPools[0].config.database, 'central');
    assert.equal(mysql.createdPools[0].config.connectionLimit, 5);
    assert.equal(mysql.createdPools[1].config.database, 'tenantalpha31');
    assert.equal(mysql.createdPools[1].config.connectionLimit, 2);
    assert.equal(mysql.createdPools[1].config.queueLimit, 7);

    await registry.shutdown();
});

test('pool registry evicts idle tenant pools before opening more tenants', async () => {
    let nowMs = 0;
    const mysql = makeFakeMysql();
    const registry = createPoolRegistry({
        mysqlModule: mysql,
        env: testEnv,
        now: () => nowMs,
        setIntervalFn: () => ({ unref: () => {} }),
        clearIntervalFn: () => {},
    });

    await registry.getConnection('tenantalpha31').query('SELECT 1');
    nowMs = 2000;
    await registry.getConnection('tenantbahria_taxi34').query('SELECT 1');

    assert.equal(mysql.createdPools.length, 2);
    assert.equal(mysql.createdPools[0].ended, true);

    const stats = registry.getPoolStats();
    assert.equal(stats.counters.evicted, 1);
    assert.deepEqual(stats.pools.map((pool) => pool.dbName), ['tenantbahria_taxi34']);

    await registry.shutdown();
});
