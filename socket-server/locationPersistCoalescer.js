const defaultMergeSnapshot = (existing, next) => ({
    ...existing,
    ...next,
    status: next.status || existing?.status,
    onlineStatus: next.onlineStatus || existing?.onlineStatus,
    force: Boolean(next.force || existing?.force),
});

const createLatestPerKeyCoalescer = ({
    persist,
    mergeSnapshot = defaultMergeSnapshot,
    maxConcurrent = Infinity,
    onCoalesced = () => {},
    onPendingPersisted = () => {},
    onError = () => {},
} = {}) => {
    if (typeof persist !== 'function') {
        throw new Error('persist function is required');
    }

    const concurrency = Number.isFinite(Number(maxConcurrent))
        ? Math.max(1, Math.floor(Number(maxConcurrent)))
        : Infinity;
    const inFlight = new Set();
    const queued = new Map();
    const pending = new Map();

    const runPersist = async (key, snapshot) => {
        let current = snapshot;
        while (current) {
            await persist(current);
            current = pending.get(key);
            if (current) {
                pending.delete(key);
                onPendingPersisted(current);
            }
        }
    };

    const start = (key, snapshot) => {
        inFlight.add(key);
        Promise.resolve()
            .then(() => runPersist(key, snapshot))
            .catch(onError)
            .finally(() => {
                inFlight.delete(key);
                drain();
            });
    };

    const drain = () => {
        while (inFlight.size < concurrency && queued.size > 0) {
            const [key, snapshot] = queued.entries().next().value;
            queued.delete(key);
            if (inFlight.has(key)) {
                pending.set(key, mergeSnapshot(pending.get(key), snapshot));
                onCoalesced(snapshot);
                continue;
            }
            start(key, snapshot);
        }
    };

    const schedule = (key, snapshot) => {
        if (!key) {
            Promise.resolve()
                .then(() => persist(snapshot))
                .catch(onError);
            return false;
        }

        if (inFlight.has(key)) {
            pending.set(key, mergeSnapshot(pending.get(key), snapshot));
            onCoalesced(snapshot);
            return true;
        }

        if (queued.has(key)) {
            queued.set(key, mergeSnapshot(queued.get(key), snapshot));
            onCoalesced(snapshot);
            return true;
        }

        if (inFlight.size >= concurrency) {
            queued.set(key, snapshot);
            return false;
        }

        start(key, snapshot);

        return false;
    };

    const clear = (key) => {
        pending.delete(key);
        queued.delete(key);
    };

    const stats = () => ({
        inFlight: inFlight.size,
        pending: pending.size,
        queued: queued.size,
    });

    return {
        schedule,
        clear,
        stats,
        _inFlight: inFlight,
        _queued: queued,
        _pending: pending,
    };
};

module.exports = {
    createLatestPerKeyCoalescer,
    defaultMergeSnapshot,
};
