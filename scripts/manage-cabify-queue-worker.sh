#!/usr/bin/env bash
set -euo pipefail

release_dir="${1:-/var/www/html/backend.cabifyit.com}"
process_name="${CABIFY_QUEUE_PM2_NAME:-cabify-queue-worker}"
pm2_home="${CABIFY_QUEUE_PM2_HOME:-/var/www/.pm2}"

if [ ! -d "$release_dir" ]; then
  echo "Backend release directory not found: $release_dir" >&2
  exit 1
fi

if [ ! -f "$release_dir/artisan" ] || [ ! -f "$release_dir/.env" ]; then
  echo "Backend release is missing artisan or .env: $release_dir" >&2
  exit 1
fi

read_env_key() {
  local key="$1"
  local fallback="$2"
  cd "$release_dir"
  php -r '
    $key = $argv[1];
    $fallback = $argv[2];
    $path = ".env";
    $env = [];
    $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    foreach ($lines as $line) {
        if (preg_match("/^\s*#/", $line)) {
            continue;
        }
        if (preg_match("/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/", $line, $matches)) {
            $env[$matches[1]] = trim($matches[2], "\"'\''");
        }
    }
    $value = $env[$key] ?? $fallback;
    $value = trim((string) $value);
    $value = trim($value, "\"'\''");
    echo $value === "" ? $fallback : $value;
  ' "$key" "$fallback"
}

run_pm2() {
  sudo -u www-data env PM2_HOME="$pm2_home" pm2 "$@"
}

queue_connection="$(read_env_key QUEUE_CONNECTION sync)"

if [ "$queue_connection" != "redis" ]; then
  if [ -d "$pm2_home" ] && command -v pm2 >/dev/null 2>&1 && run_pm2 describe "$process_name" >/dev/null 2>&1; then
    run_pm2 delete "$process_name" >/dev/null
    echo "Stopped $process_name because QUEUE_CONNECTION=$queue_connection"
  else
    echo "Queue worker disabled because QUEUE_CONNECTION=$queue_connection"
  fi
  exit 0
fi

if ! command -v pm2 >/dev/null 2>&1; then
  echo "pm2 is required to run the Redis queue worker" >&2
  exit 1
fi

redis_queue="$(read_env_key REDIS_QUEUE default)"
sleep_seconds="${CABIFY_QUEUE_SLEEP:-1}"
tries="${CABIFY_QUEUE_TRIES:-1}"
timeout="${CABIFY_QUEUE_TIMEOUT:-90}"
memory="${CABIFY_QUEUE_MEMORY:-256}"

sudo mkdir -p "$pm2_home"
sudo chown -R www-data:www-data "$pm2_home"

cd "$release_dir"
sudo -u www-data php artisan queue:restart || true

if run_pm2 describe "$process_name" >/dev/null 2>&1; then
  run_pm2 reload "$process_name" --update-env
else
  run_pm2 start php \
    --name "$process_name" \
    --time \
    -- artisan queue:work redis \
      --queue="$redis_queue" \
      --sleep="$sleep_seconds" \
      --tries="$tries" \
      --timeout="$timeout" \
      --memory="$memory"
fi

run_pm2 save >/dev/null || true
run_pm2 describe "$process_name" | grep -E 'status|script path|exec cwd' || true
