#!/usr/bin/env bash
set -euo pipefail

release_dir="${1:-$(pwd)}"
shared_storage="${2:-/var/www/shared/cabify/backend/storage}"

run() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  else
    sudo "$@"
  fi
}

run_as_www_data() {
  if [ "$(id -u)" -eq 0 ]; then
    su -s /bin/sh www-data -c "$1"
  else
    sudo -u www-data sh -c "$1"
  fi
}

if [ ! -d "$release_dir/storage" ]; then
  echo "Release storage directory not found: $release_dir/storage" >&2
  exit 1
fi

run mkdir -p "$shared_storage/app/firebase"

# Firebase service credentials are private mutable runtime files. They must
# survive timestamped releases, but must not be copied into git artifacts.
if [ -d "$release_dir/storage/app/firebase" ] && [ ! -L "$release_dir/storage/app/firebase" ]; then
  run rsync -a --ignore-existing "$release_dir/storage/app/firebase/" "$shared_storage/app/firebase/"
fi

run mkdir -p "$release_dir/storage/app"
if [ -L "$release_dir/storage/app/firebase" ]; then
  run ln -sfn "$shared_storage/app/firebase" "$release_dir/storage/app/firebase"
elif [ -e "$release_dir/storage/app/firebase" ]; then
  stamp="$(date +%Y%m%d%H%M%S)"
  run mv "$release_dir/storage/app/firebase" "$release_dir/storage/app/firebase.release-backup-$stamp"
  run ln -s "$shared_storage/app/firebase" "$release_dir/storage/app/firebase"
else
  run ln -s "$shared_storage/app/firebase" "$release_dir/storage/app/firebase"
fi

run chown -R www-data:www-data "$shared_storage/app/firebase"
run find "$shared_storage/app/firebase" -type d -exec chmod 2775 {} +
run find "$shared_storage/app/firebase" -type f -exec chmod 664 {} +
run_as_www_data "test -r '$release_dir/storage/app/firebase/firebase.json'"
