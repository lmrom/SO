#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

DB="${1:-gate.db}"

sqlite3 "$DB" ".read init.sql" ".read seed.sql"
echo "Base creada en $DB"
