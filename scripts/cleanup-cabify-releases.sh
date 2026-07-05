#!/usr/bin/env bash
set -euo pipefail

ssh_host="${CABIFY_SSH_HOST:-cabifyit-prod}"
keep_releases="${KEEP_RELEASES:-5}"

if ! [[ "$keep_releases" =~ ^[0-9]+$ ]] || [ "$keep_releases" -lt 2 ]; then
  echo "KEEP_RELEASES must be a number >= 2" >&2
  exit 1
fi

ssh "$ssh_host" "KEEP_RELEASES='$keep_releases' bash -s" <<'REMOTE'
set -euo pipefail

cleanup_app() {
  app="$1"
  live="$2"
  base="/var/www/releases/cabify/$app"

  [ -d "$base" ] || return 0

  current="$(readlink -f "$live" || true)"
  current_release="$current"
  case "$app" in
    clientadmin|dispatcher)
      current_release="$(dirname "$current")"
      ;;
  esac

  mapfile -t releases < <(find "$base" -maxdepth 1 -mindepth 1 -type d -printf '%T@ %p\n' | sort -rn | awk '{print $2}')
  count=0

  for release in "${releases[@]}"; do
    if [ "$release" = "$current_release" ]; then
      count=$((count + 1))
      continue
    fi

    count=$((count + 1))
    if [ "$count" -le "$KEEP_RELEASES" ]; then
      continue
    fi

    echo "Removing old $app release: $release"
    sudo rm -rf "$release"
  done
}

cleanup_app backend /var/www/html/backend.cabifyit.com
cleanup_app clientadmin /var/www/html/clientadmin.cabifyit.com
cleanup_app dispatcher /var/www/html/dispatcher.cabifyit.com

current_backend="$(readlink -f /var/www/html/backend.cabifyit.com || true)"
if [ -n "$current_backend" ] && [ -d "$current_backend/public" ]; then
  find "$current_backend/public" -maxdepth 1 -mindepth 1 -name '*.release-backup-*' -print -exec sudo rm -rf {} +
fi
REMOTE
