#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
backend_root="$(cd "$script_dir/.." && pwd)"
ssh_host="${CABIFY_SSH_HOST:-cabifyit-prod}"
ssh_connect_timeout="${CABIFY_SSH_CONNECT_TIMEOUT:-10}"
capture_dir="${CABIFY_STATE_CAPTURE_DIR:-$backend_root/storage/logs}"
capture_id="${CABIFY_STATE_CAPTURE_ID:-$(date +%Y%m%d%H%M%S)}"
capture_file="${CABIFY_STATE_CAPTURE_FILE:-$capture_dir/cabify-scale-state-$capture_id.txt}"

mkdir -p "$capture_dir"

run_remote() {
  ssh -o BatchMode=yes -o ConnectTimeout="$ssh_connect_timeout" "$ssh_host" "$@"
}

previous_backend="$(run_remote "readlink -f /var/www/html/backend.cabifyit.com 2>/dev/null || true" 2>/dev/null || true)"

{
  echo "Cabify scale pre-deploy state"
  echo "captured_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "ssh_host=$ssh_host"
  echo

  echo "[local-git]"
  git -C "$backend_root" rev-parse --abbrev-ref HEAD || true
  git -C "$backend_root" rev-parse HEAD || true
  git -C "$backend_root" status --short || true
  echo

  echo "[remote-symlinks]"
  run_remote "set -e
    echo backend=\$(readlink -f /var/www/html/backend.cabifyit.com 2>/dev/null || true)
    echo dispatcher=\$(readlink -f /var/www/html/dispatcher.cabifyit.com 2>/dev/null || true)
    echo clientadmin=\$(readlink -f /var/www/html/clientadmin.cabifyit.com 2>/dev/null || true)
  " || true
  echo

  echo "[pm2-project-socket]"
  run_remote "sudo -u dev pm2 describe project-socket | grep -E 'status|script path|exec cwd|restart time|unstable restarts' || true" || true
  echo

  echo "[pm2-queue-worker]"
  run_remote "if sudo test -d /var/www/.pm2; then sudo -u www-data env PM2_HOME=/var/www/.pm2 pm2 describe cabify-queue-worker | grep -E 'status|script path|exec cwd|restart time|unstable restarts' || true; else echo 'cabify-queue-worker=not-configured'; fi" || true
  echo

  echo "[redis]"
  run_remote "redis-cli ping 2>/dev/null || true; systemctl is-active redis-server 2>/dev/null || systemctl is-active redis 2>/dev/null || true" || true
  echo

  echo "[nginx-cabify-routing]"
  run_remote "sudo nginx -T 2>/dev/null | grep -E 'server_name|proxy_pass|root /var/www/html/.*cabify|backend\\.cabifyit\\.com|dispatcher\\.cabifyit\\.com|clientadmin\\.cabifyit\\.com' || true" || true
  echo

  echo "[scale-env-keys]"
  run_remote "grep -E '^(QUEUE_CONNECTION|REDIS_QUEUE|SOCKET_DB_|SOCKET_GPS_|SOCKET_ENABLE_TENANT_SCAN_RESOLUTION|NODE_INTERNAL_SECRET)=' /var/www/html/backend.cabifyit.com/.env 2>/dev/null | sed -E 's/^(NODE_INTERNAL_SECRET)=.*/\\1=<set-redacted>/' || true" || true
  echo

  echo "[mysql-threads]"
  run_remote "php <<'PHP' 2>/dev/null || mysql -e \"SHOW STATUS LIKE 'Threads_connected';\" 2>/dev/null || true
<?php
\$env = [];
foreach (file('/var/www/html/backend.cabifyit.com/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as \$line) {
    if (preg_match('/^\\s*#/', \$line)) {
        continue;
    }
    if (preg_match('/^\\s*([A-Z0-9_]+)\\s*=\\s*(.*)\\s*$/', \$line, \$matches)) {
        \$env[\$matches[1]] = trim(\$matches[2], \"\\\"'\");
    }
}
\$password = trim((string) (\$env['DB_PASSWORD'] ?? ''), \"\\\"'\");
\$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s',
    \$env['DB_HOST'] ?? '127.0.0.1',
    \$env['DB_PORT'] ?? '3306',
    \$env['DB_DATABASE'] ?? ''
);
\$pdo = new PDO(\$dsn, \$env['DB_USERNAME'] ?? '', \$password, [PDO::ATTR_TIMEOUT => 3]);
\$stmt = \$pdo->query(\"SHOW STATUS LIKE 'Threads_connected'\");
\$row = \$stmt ? \$stmt->fetch(PDO::FETCH_ASSOC) : null;
if (\$row) {
    echo \$row['Variable_name'] . '=' . \$row['Value'] . PHP_EOL;
}
PHP" || true
  echo

  echo "[rollback-template]"
  echo "previous_backend=$previous_backend"
  echo "ssh $ssh_host 'sudo ln -sfn $previous_backend /var/www/html/backend.cabifyit.com'"
  echo "ssh $ssh_host 'sudo systemctl reload php8.1-fpm || sudo systemctl reload php8.2-fpm || true'"
  echo "ssh $ssh_host 'sudo -u dev pm2 reload project-socket --update-env || sudo -u dev pm2 restart project-socket'"
  echo "ssh $ssh_host 'bash /var/www/html/backend.cabifyit.com/scripts/manage-cabify-queue-worker.sh /var/www/html/backend.cabifyit.com'"
} | tee "$capture_file"

echo "State captured to $capture_file"
