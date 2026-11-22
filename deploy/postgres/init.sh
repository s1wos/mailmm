#!/bin/sh
set -eu
psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    -v app_user="$MAILMM_DB_USER" -v app_password="$MAILMM_DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'app_user', :'app_password') \gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', current_database(), :'app_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA public TO %I', :'app_user') \gexec
SQL
