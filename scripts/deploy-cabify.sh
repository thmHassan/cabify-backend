#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
backend_root="$(cd "$script_dir/.." && pwd)"
workspace_root="$(cd "$backend_root/.." && pwd)"

ssh_host="${CABIFY_SSH_HOST:-cabifyit-prod}"
release_id="${CABIFY_RELEASE_ID:-$(date +%Y%m%d%H%M%S)}"
keep_releases="${KEEP_RELEASES:-5}"
node_path="${NODE_PATH_FOR_BUILDS:-/Users/Hassan_1/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:/Users/Hassan_1/.cache/codex-runtimes/codex-primary-runtime/dependencies/bin:$PATH}"

apps=("$@")
if [ "${#apps[@]}" -eq 0 ]; then
  apps=(backend clientadmin dispatcher)
fi

has_app() {
  local target="$1"
  for app in "${apps[@]}"; do
    if [ "$app" = "$target" ]; then
      return 0
    fi
  done
  return 1
}

for app in "${apps[@]}"; do
  case "$app" in
    backend|clientadmin|dispatcher) ;;
    *)
      echo "Unsupported app '$app'. Allowed: backend clientadmin dispatcher" >&2
      exit 1
      ;;
  esac
done

remote() {
  ssh "$ssh_host" "$@"
}

prepare_remote_dir() {
  local path="$1"
  remote "set -e; sudo mkdir -p '$path'; sudo chown -R \$(whoami):\$(id -gn) '$path'"
}

build_frontend() {
  local app_dir="$1"
  (
    cd "$app_dir"
    PATH="$node_path" npm run build
  )
}

deploy_backend() {
  local release="/var/www/releases/cabify/backend/$release_id"

  prepare_remote_dir "$release"
  rsync -az --delete \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.env' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='storage' \
    --exclude='bootstrap/cache/*' \
    --exclude='tests' \
    "$backend_root/" "$ssh_host:$release/"

  remote "set -euo pipefail
    release='$release'
    current='/var/www/html/backend.cabifyit.com'
    test -f \"\$current/.env\"
    cp \"\$current/.env\" \"\$release/.env\"
    mkdir -p \"\$release/storage/framework/cache/data\" \"\$release/storage/framework/sessions\" \"\$release/storage/framework/views\" \"\$release/storage/logs\" \"\$release/bootstrap/cache\"
    cd \"\$release\"
    composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
    sudo -u www-data php artisan migrate --force
    sudo -u www-data php artisan tenants:migrate --path=database/migrations/tenant --force
    cd \"\$release/socket-server\"
    npm install --omit=dev
    cd \"\$release\"
    bash scripts/restore-public-upload-symlinks.sh \"\$release\"
    sudo chown -R www-data:www-data \"\$release/storage\" \"\$release/bootstrap/cache\"
    sudo find \"\$release/storage\" \"\$release/bootstrap/cache\" -type d -exec chmod 2775 {} +
    sudo find \"\$release/storage\" \"\$release/bootstrap/cache\" -type f -exec chmod 664 {} +
    sudo -u www-data php artisan optimize:clear
    sudo -u www-data php artisan config:cache
    sudo -u www-data php artisan route:cache
    sudo -u www-data php artisan view:cache
    sudo ln -sfn \"\$release\" /var/www/html/backend.cabifyit.com
    sudo systemctl reload php8.1-fpm || sudo systemctl reload php8.2-fpm || true
    sudo -u dev pm2 reload project-socket --update-env || sudo -u dev pm2 restart project-socket
    (sudo crontab -l 2>/dev/null | grep -v 'php artisan schedule:run' || true; echo \"* * * * * cd /var/www/html/backend.cabifyit.com && su -s /bin/sh www-data -c 'php artisan schedule:run' >> /dev/null 2>&1\") | sudo crontab -
    sudo nginx -t
    sudo systemctl reload nginx
    sudo -u www-data test -w /var/www/shared/cabify/backend/public/testcompany134/front_photo
  "
}

deploy_static() {
  local app="$1"
  local local_dir="$2"
  local release="/var/www/releases/cabify/$app/$release_id/dist"

  build_frontend "$local_dir"
  prepare_remote_dir "$release"
  rsync -az --delete "$local_dir/dist/" "$ssh_host:$release/"

  remote "set -euo pipefail
    sudo ln -sfn '$release' /var/www/html/${app}.cabifyit.com
    sudo nginx -t
    sudo systemctl reload nginx
  "
}

if has_app backend; then
  deploy_backend
fi

if has_app clientadmin; then
  deploy_static clientadmin "$workspace_root/cabify-clientadmin"
fi

if has_app dispatcher; then
  deploy_static dispatcher "$workspace_root/cabify-dispatch"
fi

KEEP_RELEASES="$keep_releases" CABIFY_SSH_HOST="$ssh_host" "$script_dir/cleanup-cabify-releases.sh"

remote "set -e
  echo backend=\$(readlink -f /var/www/html/backend.cabifyit.com)
  echo clientadmin=\$(readlink -f /var/www/html/clientadmin.cabifyit.com)
  echo dispatcher=\$(readlink -f /var/www/html/dispatcher.cabifyit.com)
  sudo -u dev pm2 describe project-socket | grep status || true
  sudo -u www-data test -w /var/www/shared/cabify/backend/public/testcompany134/front_photo
"
