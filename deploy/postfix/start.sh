#!/bin/sh
set -eu
: "${MX_HOST:?required}" "${SMTP_LOOKUP_PASSWORD:?required}"
case "$MX_HOST" in *[!a-zA-Z0-9.-]*) echo "Invalid MX hostname" >&2; exit 1;; esac
cd /var/www
message_size_limit=$(php artisan mail:parse --check-limit) || { echo "$message_size_limit" >&2; exit 1; }

umask 0077
host_history=/var/spool/postfix/.mailmm-mx-hosts
touch "$host_history"
awk -F '[,[:space:]]+' '{
    for (i = 1; i <= NF; i++) {
        host = tolower($i)
        if (host != "" && !seen[host]++) print host
    }
}' "$host_history" - > "$host_history.new" <<EOF
$MX_HOST
${MX_PREVIOUS_HOSTS:-}
EOF
mv "$host_history.new" "$host_history"
MAILMM_SPOOL_HOSTS=$(paste -sd, "$host_history")
export MAILMM_SPOOL_HOSTS

domain_map=/etc/postfix/mailmm-domains.cf
cat > "$domain_map" <<EOF
hosts = $DB_HOST:$DB_PORT
dbname = $DB_DATABASE
user = mailmm_smtp_lookup
password = $SMTP_LOOKUP_PASSWORD
query = SELECT 1 FROM public.mailbox_domains WHERE name = lower('%s')
EOF
chown root:postfix "$domain_map"
chmod 0640 "$domain_map"
unset SMTP_LOOKUP_PASSWORD

umask 0027
php artisan config:cache
chgrp www-data bootstrap/cache/config.php
chmod 0640 bootstrap/cache/config.php
umask 0022

virtual_domains="proxy:pgsql:$domain_map"
if [ -n "${FORWARD_BOUNCE_DOMAIN:-}" ]; then
    case "$FORWARD_BOUNCE_DOMAIN" in *[!a-zA-Z0-9.-]*) echo "Invalid bounce domain" >&2; exit 1;; esac
    virtual_domains="$virtual_domains, $FORWARD_BOUNCE_DOMAIN"
fi
postconf -e "myhostname = $MX_HOST" "mydestination =" "relay_domains =" \
    "virtual_mailbox_domains = $virtual_domains" "virtual_mailbox_maps = static:1" \
    "virtual_transport = mailmm" "mailmm_destination_recipient_limit = 1" "enable_long_queue_ids = yes" \
    "smtpd_relay_restrictions = reject_unauth_destination" \
    "message_size_limit = $message_size_limit" \
    "inet_interfaces = all" "maillog_file = /dev/stdout" "smtpd_tls_security_level = none"
postconf -P 'smtp/inet/max_use=1'
postconf -M 'mailmm/unix=mailmm unix - n n - - pipe flags=q null_sender=<> user=www-data argv=/usr/local/bin/php /var/www/artisan mail:parse --postfix --recipient=${recipient} --mail-from=${sender} --client-ip=${client_address} --helo=${client_helo}'
if [ -r /etc/letsencrypt/live/mailmm/fullchain.pem ]; then
    postconf -e 'smtpd_tls_security_level = may' \
        'smtpd_tls_cert_file = /etc/letsencrypt/live/mailmm/fullchain.pem' \
        'smtpd_tls_key_file = /etc/letsencrypt/live/mailmm/privkey.pem'
fi
exec postfix start-fg
