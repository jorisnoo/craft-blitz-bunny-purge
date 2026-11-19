#!/usr/bin/env bash
# A private socket and data directory: never connect to a developer's Craft DB.
set -euo pipefail
work=$(mktemp -d /tmp/blitz-bunny-mysql.XXXXXXXX)
server=''
cleanup() {
    if [[ -n "$server" ]]; then
        kill "$server" 2>/dev/null || true
        wait "$server" 2>/dev/null || true
    fi
    rm -rf "$work"
}
trap cleanup EXIT
export BUNNY_TEST_MYSQL_SOCKET="$work/mysql.sock"
args=(--no-defaults --datadir="$work/data" --log-error="$work/mysql.log" --secure-file-priv=NULL)
mysqld "${args[@]}" --initialize-insecure || { cat "$work/mysql.log" >&2; exit 1; }
mysqld "${args[@]}" --skip-networking --mysqlx=OFF --socket="$BUNNY_TEST_MYSQL_SOCKET" --pid-file="$work/mysql.pid" &
server=$!
for ((i=0; i<120; i++)); do
    [[ -S "$BUNNY_TEST_MYSQL_SOCKET" ]] && break
    if ! kill -0 "$server" 2>/dev/null; then cat "$work/mysql.log" >&2; exit 1; fi
    sleep 0.25
done
mysql --no-defaults --user=root --socket="$BUNNY_TEST_MYSQL_SOCKET" --execute='CREATE DATABASE bunny_queue_test'
"$@"
