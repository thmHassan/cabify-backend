#!/usr/bin/env bash
set -euo pipefail

release_dir="${1:-$(pwd)}"
shared_public="${2:-/var/www/shared/cabify/backend/public}"

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

if [ ! -d "$release_dir/public" ]; then
  echo "Release public directory not found: $release_dir/public" >&2
  exit 1
fi

run mkdir -p "$shared_public"

# These are mutable upload roots. They must survive timestamped releases.
upload_roots=(
  pictures
  profile_image
  profile_pictures
  vehicle_image
)

for root in "${upload_roots[@]}"; do
  if [ -d "$release_dir/public/$root" ] && [ ! -L "$release_dir/public/$root" ]; then
    run mkdir -p "$shared_public/$root"
    run rsync -a --ignore-existing "$release_dir/public/$root/" "$shared_public/$root/"
  fi
done

# Existing tenant/company upload folders should also be shared across releases.
for candidate in "$release_dir"/public/*; do
  [ -d "$candidate" ] || continue
  [ ! -L "$candidate" ] || continue
  name="$(basename "$candidate")"
  case "$name" in
    *.release-backup-*)
      continue
      ;;
    build|css|js|vendor|fonts|images|assets|storage|api|index.php)
      continue
      ;;
  esac
  run mkdir -p "$shared_public/$name"
  run rsync -a --ignore-existing "$candidate/" "$shared_public/$name/"
done

# Known folders used by driver registration/document upload. More tenant folders
# are linked dynamically from whatever already exists in shared_public.
default_company="${DEFAULT_COMPANY_CODE:-testcompany134}"
for subdir in front_photo back_photo profile_photo driver_documents profile_image vehicle_image pictures; do
  run mkdir -p "$shared_public/$default_company/$subdir"
done

run chown -R www-data:www-data "$shared_public"
run find "$shared_public" -type d -exec chmod 2775 {} +
run find "$shared_public" -type f -exec chmod 664 {} +

stamp="$(date +%Y%m%d%H%M%S)"
for shared_path in "$shared_public"/*; do
  [ -e "$shared_path" ] || continue
  name="$(basename "$shared_path")"
  case "$name" in
    *.release-backup-*)
      continue
      ;;
  esac
  target="$release_dir/public/$name"

  if [ -L "$target" ]; then
    run ln -sfn "$shared_path" "$target"
  elif [ -e "$target" ]; then
    run mv "$target" "$target.release-backup-$stamp"
    run ln -s "$shared_path" "$target"
  else
    run ln -s "$shared_path" "$target"
  fi
done

run_as_www_data "test -w '$shared_public/$default_company/front_photo'"
