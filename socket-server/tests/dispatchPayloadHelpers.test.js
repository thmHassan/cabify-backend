const test = require('node:test');
const assert = require('node:assert/strict');
const {
    normalizeDistanceUnit,
    buildDistanceDisplayFieldsFromKm,
    buildDistanceDisplayFieldsFromMeters,
    driverRankSupported,
} = require('../dispatchPayloadHelpers');

test('normalizeDistanceUnit maps only miles-like settings to miles', () => {
    assert.equal(normalizeDistanceUnit('miles'), 'miles');
    assert.equal(normalizeDistanceUnit(' Miles '), 'miles');
    assert.equal(normalizeDistanceUnit('km'), 'km');
    assert.equal(normalizeDistanceUnit(null), 'km');
});

test('buildDistanceDisplayFieldsFromKm preserves km and converts miles display values', () => {
    assert.deepEqual(
        buildDistanceDisplayFieldsFromKm(10, 'km'),
        { distance_value: 10, distance_unit: 'km' }
    );
    assert.deepEqual(
        buildDistanceDisplayFieldsFromKm(1.609344, 'miles'),
        { distance_value: 1, distance_unit: 'miles' }
    );
});

test('buildDistanceDisplayFieldsFromMeters preserves raw-unit compatibility while adding display fields', () => {
    assert.deepEqual(
        buildDistanceDisplayFieldsFromMeters(2500, 'km'),
        { distance_value: 2.5, distance_unit: 'km' }
    );
    assert.deepEqual(
        buildDistanceDisplayFieldsFromMeters(1609.344, 'miles'),
        { distance_value: 1, distance_unit: 'miles' }
    );
    assert.deepEqual(
        buildDistanceDisplayFieldsFromMeters('not-a-number', 'miles'),
        { distance_value: null, distance_unit: 'miles' }
    );
});

test('driverRankSupported only enables rank for plot-based dispatch', async () => {
    const db = {
        query: async () => [[{ dispatch_system: 'auto_dispatch_plot_base' }]],
    };

    assert.equal(await driverRankSupported(db), true);
});

test('driverRankSupported hides rank for nearest dispatch', async () => {
    const db = {
        query: async () => [[{ dispatch_system: 'auto_dispatch_nearest_driver' }]],
    };

    assert.equal(await driverRankSupported(db), false);
});

test('driverRankSupported preserves legacy rank when setting lookup fails', async () => {
    const db = {
        query: async () => {
            throw new Error('missing dispatch_system table');
        },
    };

    assert.equal(await driverRankSupported(db), true);
});
