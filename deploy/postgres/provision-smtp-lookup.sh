#!/bin/sh
set -eu
: "${SMTP_LOOKUP_PASSWORD:?required}"
if [ "$MAILMM_DB_USER" = mailmm_smtp_lookup ]; then
    echo "The application and SMTP lookup require separate database roles." >&2
    exit 1
fi

psql -X -v ON_ERROR_STOP=1 <<'SQL'
\getenv lookup_password SMTP_LOOKUP_PASSWORD
BEGIN;
SELECT 'CREATE ROLE mailmm_smtp_lookup LOGIN'
    WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'mailmm_smtp_lookup') \gexec
ALTER ROLE mailmm_smtp_lookup PASSWORD :'lookup_password';
SELECT format('GRANT CONNECT ON DATABASE %I TO mailmm_smtp_lookup', current_database()) \gexec
GRANT USAGE ON SCHEMA public TO mailmm_smtp_lookup;
GRANT SELECT (name) ON public.mailbox_domains TO mailmm_smtp_lookup;
COMMIT;
SQL
