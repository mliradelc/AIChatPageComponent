#!/usr/bin/env bash
#
# Helper for the local ILIAS test stack defined in docker-compose.yml.
#
#   scripts/ilias-dev.sh up                 start the stack and refresh plugin artifacts
#   scripts/ilias-dev.sh refresh            rebuild ILIAS autoload/artifacts so the plugin is found
#   scripts/ilias-dev.sh snapshot [name]    save database + ILIAS data directories (default name: base)
#   scripts/ilias-dev.sh restore [name]     restore a snapshot and refresh (default name: base)
#   scripts/ilias-dev.sh list               list snapshots
#
# Named Docker volumes already keep data across `docker compose stop/start` and `down/up`.
# Snapshots are a second, portable copy (e.g. after a Docker reset or on another Mac).
# Snapshots are stored OUTSIDE the repository, because the repository is mounted inside
# ILIAS's web root and must not expose database dumps or user uploads.

set -euo pipefail
umask 077

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SNAP_ROOT="${ILIAS_SNAPSHOT_DIR:-$HOME/.local/share/aichat-ilias/snapshots}"
cd "$ROOT"

dc() { docker compose "$@"; }

wait_for_ilias() {
  local tries=0
  until dc logs ilias 2>&1 | grep -qE 'ILIAS installed successfully|ILIAS already installed|AH00094|Fatal error'; do
    tries=$((tries + 1))
    if [ "$tries" -gt 120 ]; then
      echo "Timed out waiting for ILIAS setup. Check: docker compose logs ilias" >&2
      exit 1
    fi
    sleep 5
  done
  if dc logs ilias 2>&1 | grep -q 'Fatal error'; then
    echo "ILIAS setup failed. Check: docker compose logs ilias" >&2
    exit 1
  fi
}

wait_for_http() {
  local tries=0
  until curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/login.php | grep -qE '^(200|302)$'; do
    tries=$((tries + 1))
    if [ "$tries" -gt 120 ]; then
      echo "ILIAS does not answer on http://localhost:8080. Check: docker compose logs ilias" >&2
      exit 1
    fi
    sleep 5
  done
}

refresh() {
  # ILIAS discovers plugins through generated artifacts and a Composer classmap.
  # The container layer does not keep them across `docker compose down`, so rebuild them.
  dc exec -T ilias sh -c 'cd /var/www/html && composer dump-autoload -o >/tmp/refresh.log 2>&1 || { cat /tmp/refresh.log; exit 1; }; echo "ILIAS artifacts refreshed"'
}

snapshot() {
  local name="${1:-base}"
  local dir="$SNAP_ROOT/$name"
  mkdir -p "$dir"
  chmod 700 "$SNAP_ROOT" "$dir"
  echo "Dumping database to $dir/db.sql"
  dc exec -T mysql sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --databases ilias --add-drop-database --single-transaction --routines' > "$dir/db.sql"
  echo "Archiving ILIAS data directories"
  dc exec -T ilias tar -C /var/www/html -czf - data > "$dir/ilias-data.tgz"
  dc exec -T ilias tar -C /var/iliasdata/ilias -czf - . > "$dir/ilias-iliasdata.tgz"
  date -u +%Y-%m-%dT%H:%M:%SZ > "$dir/created-at.txt"
  echo "Snapshot '$name' saved in $dir"
}

restore() {
  local name="${1:-base}"
  local dir="$SNAP_ROOT/$name"
  if [ ! -f "$dir/db.sql" ]; then
    echo "No snapshot named '$name' in $SNAP_ROOT" >&2
    exit 1
  fi
  echo "Restoring snapshot '$name' (replaces the current ILIAS database and data directories)"
  # Stop ILIAS while the database is replaced, so no open sessions block the import.
  dc stop ilias
  dc up -d --wait mysql
  dc exec -T mysql sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' < "$dir/db.sql"
  # Use one-off containers with the same volumes to replace the data directories.
  dc run --rm -T --no-deps --entrypoint sh ilias -c 'find /var/www/html/data -mindepth 1 -delete; find /var/iliasdata/ilias -mindepth 1 -delete' >/dev/null
  dc run --rm -T --no-deps --entrypoint sh ilias -c 'tar -C /var/www/html -xzf -' < "$dir/ilias-data.tgz"
  dc run --rm -T --no-deps --entrypoint sh ilias -c 'tar -C /var/iliasdata/ilias -xzf -' < "$dir/ilias-iliasdata.tgz"
  dc up -d ilias
  wait_for_http
  refresh
  echo "Restored snapshot '$name'"
}

case "${1:-}" in
  up)
    dc up -d
    wait_for_ilias
    refresh
    echo "ILIAS: http://localhost:8080"
    ;;
  refresh) refresh ;;
  snapshot) snapshot "${2:-}" ;;
  restore) restore "${2:-}" ;;
  list) ls -1 "$SNAP_ROOT" 2>/dev/null || echo "(no snapshots yet in $SNAP_ROOT)" ;;
  *)
    sed -n '2,13p' "$0"
    exit 1
    ;;
esac
