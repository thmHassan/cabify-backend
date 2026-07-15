const test = require('node:test');
const assert = require('node:assert/strict');
const { pointInPolygon, createWaitingQueueService } = require('../waitingQueueService');

test('pointInPolygon detects point inside rectangle polygon', () => {
    const polygon = [
        [0, 0],
        [0, 10],
        [10, 10],
        [10, 0],
    ];

    assert.equal(pointInPolygon(5, 5, polygon), true);
    assert.equal(pointInPolygon(50, 50, polygon), false);
});

test('createWaitingQueueService validates rank bounds', async () => {
    const plotDriverQueues = new Map();
    const service = createWaitingQueueService({
        io: { to: () => ({ emit: () => {} }) },
        plotDriverQueues,
        driverLastLocationTime: new Map(),
        getConnection: () => ({
            query: async () => [[], []],
        }),
        RECONNECTING_THRESHOLD_MS: 10000,
    });

    plotDriverQueues.set('7_tenant1', [
        { driver_id: '1', rank: 1 },
        { driver_id: '2', rank: 2 },
    ]);

    const invalid = await service.applyDriverRankUpdate('tenant1', 7, 1, 5);
    assert.equal(invalid.success, 0);
});

test('removeFromQueue cleans persisted plot queues that are not loaded in memory', async () => {
    const plotDriverQueues = new Map();
    const deletedPlots = [];
    const insertedRows = [];
    const db = {
        query: async (sql, params) => {
            if (sql.startsWith('SELECT DISTINCT plot_id')) {
                return [[{ plot_id: 2 }], []];
            }

            if (sql.startsWith('SELECT driver_id')) {
                return [[
                    { driver_id: 10, rank: 1 },
                    { driver_id: 11, rank: 2 },
                ], []];
            }

            if (sql.startsWith('DELETE FROM plot_driver_queues')) {
                deletedPlots.push(params[0]);
                return [[], []];
            }

            if (sql.startsWith('INSERT INTO plot_driver_queues')) {
                insertedRows.push(params);
                return [[], []];
            }

            return [[], []];
        },
    };

    const service = createWaitingQueueService({
        io: { to: () => ({ emit: () => {} }) },
        plotDriverQueues,
        driverLastLocationTime: new Map(),
        getConnection: () => db,
        RECONNECTING_THRESHOLD_MS: 10000,
    });

    const changedPlots = await service.removeFromQueue(db, 10, 'tenant1');

    assert.deepEqual(changedPlots, ['2']);
    assert.deepEqual(plotDriverQueues.get('2_tenant1'), [{ driver_id: '11', rank: 1 }]);
    assert.deepEqual(deletedPlots, ['2']);
    assert.deepEqual(insertedRows, [['2', '11', 1]]);
});

test('removeFromOtherQueues keeps the current plot queue intact', async () => {
    const plotDriverQueues = new Map([
        ['5_tenant1', [{ driver_id: '10', rank: 1 }]],
        ['7_tenant1', [{ driver_id: '10', rank: 1 }, { driver_id: '11', rank: 2 }]],
    ]);
    const deletedPlots = [];
    const insertedRows = [];
    const db = {
        query: async (sql, params) => {
            if (sql.startsWith('SELECT DISTINCT plot_id')) {
                assert.deepEqual(params, ['10', '5']);
                return [[{ plot_id: 7 }], []];
            }

            if (sql.startsWith('SELECT driver_id')) {
                return [[
                    { driver_id: 10, rank: 1 },
                    { driver_id: 11, rank: 2 },
                ], []];
            }

            if (sql.startsWith('DELETE FROM plot_driver_queues')) {
                deletedPlots.push(params[0]);
                return [[], []];
            }

            if (sql.startsWith('INSERT INTO plot_driver_queues')) {
                insertedRows.push(params);
                return [[], []];
            }

            return [[], []];
        },
    };

    const service = createWaitingQueueService({
        io: { to: () => ({ emit: () => {} }) },
        plotDriverQueues,
        driverLastLocationTime: new Map(),
        getConnection: () => db,
        RECONNECTING_THRESHOLD_MS: 10000,
    });

    const changedPlots = await service.removeFromOtherQueues(db, 10, 'tenant1', 5);

    assert.deepEqual(changedPlots, ['7']);
    assert.deepEqual(plotDriverQueues.get('5_tenant1'), [{ driver_id: '10', rank: 1 }]);
    assert.deepEqual(plotDriverQueues.get('7_tenant1'), [{ driver_id: '11', rank: 1 }]);
    assert.deepEqual(deletedPlots, ['7']);
    assert.deepEqual(insertedRows, [['7', '11', 1]]);
});
