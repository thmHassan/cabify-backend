const test = require('node:test');
const assert = require('node:assert/strict');
const { createLatestPerKeyCoalescer, defaultMergeSnapshot } = require('../locationPersistCoalescer');

const deferred = () => {
    let resolve;
    const promise = new Promise((done) => {
        resolve = done;
    });
    return { promise, resolve };
};

test('latest per-key coalescer persists current and latest pending snapshot only', async () => {
    const firstPersist = deferred();
    const persisted = [];
    let coalesced = 0;
    let pendingPersisted = 0;

    const scheduler = createLatestPerKeyCoalescer({
        persist: async (snapshot) => {
            persisted.push(snapshot);
            if (persisted.length === 1) {
                await firstPersist.promise;
            }
        },
        onCoalesced: () => {
            coalesced += 1;
        },
        onPendingPersisted: () => {
            pendingPersisted += 1;
        },
    });

    assert.equal(scheduler.schedule('driver:1', { latitude: 1 }), false);
    assert.equal(scheduler.schedule('driver:1', { latitude: 2, status: 'busy' }), true);
    assert.equal(scheduler.schedule('driver:1', { latitude: 3, onlineStatus: 'online' }), true);

    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(persisted.length, 1);
    assert.equal(coalesced, 2);
    assert.equal(scheduler.stats().pending, 1);

    firstPersist.resolve();
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(persisted.length, 2);
    assert.deepEqual(persisted.map((snapshot) => snapshot.latitude), [1, 3]);
    assert.equal(persisted[1].status, 'busy');
    assert.equal(persisted[1].onlineStatus, 'online');
    assert.equal(pendingPersisted, 1);
    assert.deepEqual(scheduler.stats(), { inFlight: 0, pending: 0, queued: 0 });
});

test('default merge preserves force and state transitions while keeping latest location', () => {
    const merged = defaultMergeSnapshot(
        { latitude: 10, longitude: 20, status: 'busy', force: true },
        { latitude: 11, longitude: 21, onlineStatus: 'online' }
    );

    assert.equal(merged.latitude, 11);
    assert.equal(merged.longitude, 21);
    assert.equal(merged.status, 'busy');
    assert.equal(merged.onlineStatus, 'online');
    assert.equal(merged.force, true);
});

test('coalescer limits different-key concurrency and merges queued snapshots', async () => {
    const firstWave = deferred();
    const persisted = [];
    let coalesced = 0;

    const scheduler = createLatestPerKeyCoalescer({
        maxConcurrent: 2,
        persist: async (snapshot) => {
            persisted.push(snapshot);
            if (persisted.length <= 2) {
                await firstWave.promise;
            }
        },
        onCoalesced: () => {
            coalesced += 1;
        },
    });

    assert.equal(scheduler.schedule('driver:1', { driverId: 1, latitude: 1 }), false);
    assert.equal(scheduler.schedule('driver:2', { driverId: 2, latitude: 2 }), false);
    assert.equal(scheduler.schedule('driver:3', { driverId: 3, latitude: 3 }), false);
    assert.equal(scheduler.schedule('driver:4', { driverId: 4, latitude: 4 }), false);
    assert.equal(scheduler.schedule('driver:3', { driverId: 3, latitude: 33, status: 'busy' }), true);

    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(scheduler.stats(), { inFlight: 2, pending: 0, queued: 2 });
    assert.equal(coalesced, 1);
    assert.deepEqual(persisted.map((snapshot) => snapshot.driverId), [1, 2]);

    firstWave.resolve();
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));

    assert.deepEqual(persisted.map((snapshot) => snapshot.driverId), [1, 2, 3, 4]);
    assert.equal(persisted[2].latitude, 33);
    assert.equal(persisted[2].status, 'busy');
    assert.deepEqual(scheduler.stats(), { inFlight: 0, pending: 0, queued: 0 });
});
