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
