const PLOT_DISPATCH_ACTIVE_PREFIX = 'PLOT_DISPATCH_ACTIVE|';

const activeAction = (message) => `${PLOT_DISPATCH_ACTIVE_PREFIX}${message}`;

const startedAction = (plotId) => activeAction(
    `Started plot-based dispatch for primary plot #${plotId} — in progress`
);

const dispatchedPlotAction = (plotId, isBackup) => {
    const label = isBackup ? 'backup plot' : 'primary plot';
    return activeAction(`Dispatched to ${label} #${plotId} — plot dispatch in progress`);
};

const broadcastAction = (driverCount, plotId, isBackup, timeoutSeconds) => {
    const plotLabel = isBackup ? 'backup plot' : 'plot';
    return activeAction(
        `Broadcast to ${driverCount} driver(s) in ${plotLabel} #${plotId} — waiting up to ${timeoutSeconds}s`
    );
};

const singleOfferAction = (driverId, rank, plotId, isBackup, timeoutSeconds) => {
    const plotLabel = isBackup ? 'backup plot' : 'primary plot';
    return activeAction(
        `Offered to driver #${driverId} rank ${rank} in ${plotLabel} #${plotId} — waiting up to ${timeoutSeconds}s`
    );
};

const exhaustedAction = () => (
    'Plot dispatch failed — no driver accepted across primary/backup plots. Available for manual dispatch.'
);

const exhaustedBiddingAction = () => (
    'Plot dispatch failed — no driver accepted across primary/backup plots. Available for manual dispatch and fixed-fare bidding.'
);

const missingPickupPlotAction = () => (
    'Pickup is outside all service plots. Manual dispatch required.'
);

const acceptedAction = (driverName, driverId = null) => {
    const label = driverName || (driverId ? `driver #${driverId}` : 'driver');
    return `Plot-based dispatch - accepted by ${label}`;
};

const isPlotDispatchInProgress = (dispatcherAction) => {
    if (typeof dispatcherAction !== 'string' || dispatcherAction === '') {
        return false;
    }

    if (dispatcherAction.startsWith(PLOT_DISPATCH_ACTIVE_PREFIX)) {
        return true;
    }

    const normalized = dispatcherAction.toLowerCase();

    if ([
        'no driver accepted',
        'plot dispatch failed',
        'all plots exhausted',
        'available for manual',
        'accepted by driver',
    ].some((needle) => normalized.includes(needle))) {
        return false;
    }

    return [
        'started plot-based dispatch',
        'broadcast to',
        'driver(s) in plot',
        'driver(s) in backup plot',
        'offered to driver',
        'dispatched to primary plot',
        'dispatched to backup plot',
        'plot dispatch',
        'in progress',
    ].some((needle) => normalized.includes(needle));
};

const plotDispatchHideSql = (alias = '') => {
    const col = (name) => (alias ? `${alias}.${name}` : name);

    return `(
        ${col('dispatcher_action')} IS NULL
        OR (
            ${col('dispatcher_action')} NOT LIKE '${PLOT_DISPATCH_ACTIVE_PREFIX}%'
            AND LOWER(${col('dispatcher_action')}) NOT LIKE '%started plot-based dispatch%'
            AND LOWER(${col('dispatcher_action')}) NOT LIKE '%broadcast to %driver(s) in plot%'
            AND LOWER(${col('dispatcher_action')}) NOT LIKE '%broadcast to %driver(s) in backup plot%'
            AND LOWER(${col('dispatcher_action')}) NOT LIKE '%offered to driver%'
            AND LOWER(${col('dispatcher_action')}) NOT LIKE '%dispatched to primary plot%'
            AND LOWER(${col('dispatcher_action')}) NOT LIKE '%dispatched to backup plot%'
            AND LOWER(${col('dispatcher_action')}) NOT LIKE '%plot dispatch%in progress%'
        )
    )`;
};

const freshAsapAwaitingDispatchHideSql = (alias = '') => {
    const col = (name) => (alias ? `${alias}.${name}` : name);

    return `NOT (
        ${col('booking_status')} = 'pending'
        AND ${col('driver')} IS NULL
        AND ${col('dispatcher_action')} IS NULL
        AND (${col('pickup_time')} = 'asap' OR ${col('pickup_time_type')} = 'asap')
        AND NOT (
            ${col('is_scheduled')} = 1
            AND ${col('pickup_time_type')} = 'time'
            AND ${col('dispatch_released')} = 0
            AND (
                DATE(${col('booking_date')}) > CURDATE()
                OR (
                    DATE(${col('booking_date')}) = CURDATE()
                    AND ${col('pickup_time')} != 'asap'
                    AND TIMESTAMP(${col('booking_date')}, ${col('pickup_time')}) > NOW()
                )
            )
        )
    )`;
};

const todaysBookingVisibilitySql = (alias = '') => {
    const col = (name) => (alias ? `${alias}.${name}` : name);

    return `(
        ${col('booking_status')} IS NULL
        OR ${col('booking_status')} NOT IN ('completed', 'no_show', 'cancelled')
    )`;
};

module.exports = {
    PLOT_DISPATCH_ACTIVE_PREFIX,
    activeAction,
    startedAction,
    dispatchedPlotAction,
    broadcastAction,
    singleOfferAction,
    exhaustedAction,
    exhaustedBiddingAction,
    missingPickupPlotAction,
    acceptedAction,
    isPlotDispatchInProgress,
    plotDispatchHideSql,
    freshAsapAwaitingDispatchHideSql,
    todaysBookingVisibilitySql,
};
