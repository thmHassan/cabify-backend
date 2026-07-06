# cabifyit

sudo systemctl reload php8.1-fpm

## Scale rollout notes

This backend scale path keeps current behavior unless scale-specific env keys are explicitly enabled.

Safe defaults:

- Existing `.env` DB, Firebase, mail, upload, domain, and notification values stay unchanged.
- `QUEUE_CONNECTION=sync` keeps Laravel queue behavior as-is and disables the PM2 queue worker.
- `QUEUE_CONNECTION=redis` enables the `cabify-queue-worker` PM2 process during backend deploy.
- Redis queues are for Laravel background jobs first. Socket.IO Redis adapter should only be enabled after multiple socket workers are intentionally introduced.

Optional additive scale keys:

```env
QUEUE_CONNECTION=redis
REDIS_QUEUE=default
SOCKET_DB_POOL_LIMIT=2
SOCKET_DB_CENTRAL_POOL_LIMIT=5
SOCKET_DB_POOL_IDLE_MS=60000
SOCKET_DB_MAX_TENANT_POOLS=40
SOCKET_DB_QUEUE_LIMIT=200
SOCKET_GPS_IDLE_PERSIST_MS=30000
SOCKET_GPS_ACTIVE_PERSIST_MS=5000
SOCKET_GPS_IDLE_MIN_MOVEMENT_METERS=75
SOCKET_GPS_ACTIVE_MIN_MOVEMENT_METERS=15
SOCKET_GPS_PERSIST_CONCURRENCY=10
SOCKET_GPS_LIVE_BROADCAST_FLUSH_MS=250
SOCKET_QUEUE_FULL_BROADCAST_COALESCE_MS=2000
SOCKET_LISTEN_BACKLOG=8192
SOCKET_PING_INTERVAL_MS=25000
SOCKET_PING_TIMEOUT_MS=60000
```

Pre-deploy state capture:

```bash
scripts/capture-cabify-scale-state.sh
```

Timestamped backend deploy:

```bash
CABIFY_RELEASE_ID="$(date +%Y%m%d%H%M%S)" scripts/deploy-cabify.sh backend
```

The deploy copies the current live `.env`, restores shared uploads/Firebase storage symlinks, switches the backend symlink, reloads `project-socket`, and runs `scripts/manage-cabify-queue-worker.sh`.

Disable Redis queue worker:

```bash
ssh cabifyit-prod 'bash /var/www/html/backend.cabifyit.com/scripts/manage-cabify-queue-worker.sh /var/www/html/backend.cabifyit.com'
```

Backend rollback, replacing `<previous-release>` with the captured path:

```bash
ssh cabifyit-prod 'sudo ln -sfn <previous-release> /var/www/html/backend.cabifyit.com'
ssh cabifyit-prod 'sudo systemctl reload php8.1-fpm || sudo systemctl reload php8.2-fpm || true'
ssh cabifyit-prod 'sudo -u dev pm2 reload project-socket --update-env || sudo -u dev pm2 restart project-socket'
ssh cabifyit-prod 'bash /var/www/html/backend.cabifyit.com/scripts/manage-cabify-queue-worker.sh /var/www/html/backend.cabifyit.com'
```

Socket/Nginx capacity settings applied during scale testing:

```bash
# /etc/nginx/nginx.conf
worker_rlimit_nofile 65535;
worker_connections 8192;

# /etc/sysctl.d/99-cabify-scale.conf
net.core.somaxconn = 8192
net.ipv4.tcp_max_syn_backlog = 8192
```

Rollback those host-level settings by restoring the captured Nginx backup and removing the sysctl drop-in:

```bash
ssh cabifyit-prod 'sudo cp /etc/nginx/nginx.conf.cabify-scale-backup-20260705235818 /etc/nginx/nginx.conf && sudo nginx -t && sudo systemctl reload nginx'
ssh cabifyit-prod 'sudo rm -f /etc/sysctl.d/99-cabify-scale.conf && sudo sysctl --system'
```

Post-deploy checks:

```bash
ssh cabifyit-prod 'curl -fsS https://backend.cabifyit.com/ >/dev/null && echo backend-http-ok'
ssh cabifyit-prod 'sudo -u dev pm2 describe project-socket | grep status'
ssh cabifyit-prod 'sudo -u www-data test -w /var/www/shared/cabify/backend/public/testcompany134/front_photo'
ssh cabifyit-prod 'sudo -u www-data test -r /var/www/html/backend.cabifyit.com/storage/app/firebase/firebase.json'
ssh cabifyit-prod 'mysql -e "SHOW STATUS LIKE '\''Threads_connected'\'';"'
ssh cabifyit-prod 'redis-cli ping || true'
```

Run staged socket stress after those checks: 500, 1,500, 3,000, then 5,000 drivers plus 300 non-driver sockets. At each stage, confirm `Threads_connected` stays bounded and drops near baseline within 2 minutes after disconnect.

Latest scale proof from production testing:

- 2026-07-06 release `/var/www/releases/cabify/backend/20260706061101` held 5,017 driver sockets, 300 user/customer sockets, and 5 dispatcher sockets through Nginx.
- Same run accepted, broadcast, and persisted 5,015 GPS events with `gps.dbErrors=0`; MySQL pool queues returned to 0 immediately after the burst.
- GPS live map broadcasts are coalesced by `SOCKET_GPS_LIVE_BROADCAST_FLUSH_MS` without changing the existing `driver-location-update` event name or payload shape.
- Queue/full-panel refresh broadcasts are coalesced by `SOCKET_QUEUE_FULL_BROADCAST_COALESCE_MS`; Laravel queue mode remains whatever the live `.env` sets.

Local stress harness:

```bash
cd socket-server
SOCKET_STRESS_HEALTH_TOKEN="$NODE_INTERNAL_SECRET" SOCKET_STRESS_DRIVERS=500 SOCKET_STRESS_DISPATCHERS=30 SOCKET_STRESS_CUSTOMERS=270 npm run stress:scale
SOCKET_STRESS_HEALTH_TOKEN="$NODE_INTERNAL_SECRET" SOCKET_STRESS_DRIVERS=1500 SOCKET_STRESS_DISPATCHERS=30 SOCKET_STRESS_CUSTOMERS=270 npm run stress:scale
SOCKET_STRESS_HEALTH_TOKEN="$NODE_INTERNAL_SECRET" SOCKET_STRESS_DRIVERS=3000 SOCKET_STRESS_DISPATCHERS=30 SOCKET_STRESS_CUSTOMERS=270 npm run stress:scale
SOCKET_STRESS_HEALTH_TOKEN="$NODE_INTERNAL_SECRET" SOCKET_STRESS_DRIVERS=5000 SOCKET_STRESS_DISPATCHERS=30 SOCKET_STRESS_CUSTOMERS=270 npm run stress:scale
```

Barrier-mode sharded stress harness for larger runs from one load machine:

```bash
cd socket-server
SOCKET_STRESS_URL=https://backend.cabifyit.com \
SOCKET_STRESS_HEALTH_URL=none \
SOCKET_STRESS_TENANTS=alpha31 \
SOCKET_STRESS_SHARDS=5 \
SOCKET_STRESS_ID_OFFSET=13000000 \
SOCKET_STRESS_DRIVERS=5000 \
SOCKET_STRESS_DISPATCHERS=0 \
SOCKET_STRESS_CUSTOMERS=300 \
SOCKET_STRESS_GPS_EVENTS=5000 \
SOCKET_STRESS_HOLD_MS=45000 \
SOCKET_STRESS_BATCH=25 \
SOCKET_STRESS_BATCH_DELAY_MS=400 \
SOCKET_STRESS_CONNECT_TIMEOUT_MS=45000 \
SOCKET_STRESS_CONNECT_SETTLE_MS=120000 \
SOCKET_STRESS_SHARD_LAUNCH_DELAY_MS=8000 \
SOCKET_STRESS_SHARD_READY_TIMEOUT_MS=300000 \
SOCKET_STRESS_START_WAIT_MS=420000 \
npm run stress:scale:sharded
```

When the health endpoint is not public, use `SOCKET_STRESS_HEALTH_COMMAND` to sample it over SSH from the server:

```bash
SOCKET_STRESS_HEALTH_COMMAND='ssh cabifyit-prod '"'"'secret=$(grep "^NODE_INTERNAL_SECRET=" /var/www/html/backend.cabifyit.com/.env | cut -d= -f2-); curl -fsS -H "Authorization: Bearer $secret" http://127.0.0.1:3001/socket-health'"'"'' npm run stress:scale
```
