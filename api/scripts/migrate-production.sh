#!/usr/bin/env bash
# Runs pending migrations on the production database (Supabase). Vercel's build doesn't run them,
# so run this after pulling new migrations and before (or right after) deploying.
#
#   DB_URL="postgresql://…" scripts/migrate-production.sh   # or leave DB_URL unset to be asked
#
# The connection string is read from the environment or typed in hidden; it is never written to a file.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -z "${DB_URL:-}" ]; then
  read -rsp "Supabase connection string (postgresql://…): " DB_URL
  echo
fi
[ -n "$DB_URL" ] || { echo "No connection string given." >&2; exit 1; }
export DB_CONNECTION=pgsql DB_URL

php artisan migrate:status --pending
read -rp "Run these migrations on production? [y/N] " answer
[ "$answer" = "y" ] || [ "$answer" = "Y" ] || { echo "Nothing changed."; exit 0; }
php artisan migrate --force
