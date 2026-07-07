const normalizeDistanceUnit = (units) => (
    String(units || '').trim().toLowerCase() === 'miles' ? 'miles' : 'km'
);

const buildDistanceDisplayFieldsFromKm = (distanceKm, unit = 'km') => {
    const km = Number.parseFloat(distanceKm);
    const normalizedUnit = normalizeDistanceUnit(unit);
    if (!Number.isFinite(km)) {
        return {
            distance_value: null,
            distance_unit: normalizedUnit,
        };
    }

    const value = normalizedUnit === 'miles' ? km / 1.609344 : km;
    return {
        distance_value: Number(value.toFixed(2)),
        distance_unit: normalizedUnit,
    };
};

const buildDistanceDisplayFieldsFromMeters = (distanceMeters, unit = 'km') => {
    const meters = Number.parseFloat(distanceMeters);
    const normalizedUnit = normalizeDistanceUnit(unit);
    if (!Number.isFinite(meters)) {
        return {
            distance_value: null,
            distance_unit: normalizedUnit,
        };
    }

    const value = normalizedUnit === 'miles' ? meters / 1609.344 : meters / 1000;
    return {
        distance_value: Number(value.toFixed(2)),
        distance_unit: normalizedUnit,
    };
};

const driverRankSupported = async (db) => {
    try {
        const [rows] = await db.query(
            `SELECT dispatch_system FROM dispatch_system
             WHERE status = 'enable'
             ORDER BY priority IS NULL, priority ASC, id ASC
             LIMIT 1`
        );
        if (rows.length > 0) {
            return rows[0].dispatch_system === 'auto_dispatch_plot_base';
        }
    } catch (error) {
        console.warn('[Rank] Dispatch system lookup failed, preserving rank support:', error.message);
    }

    return true;
};

module.exports = {
    normalizeDistanceUnit,
    buildDistanceDisplayFieldsFromKm,
    buildDistanceDisplayFieldsFromMeters,
    driverRankSupported,
};
