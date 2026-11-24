#!/bin/sh
set -eu
: "${MX_HOST:?}" "${FORWARD_SIGNING_DOMAIN:?}"
case "$MX_HOST$FORWARD_SIGNING_DOMAIN" in *[!a-zA-Z0-9.-]*) exit 2;; esac
test -s /etc/letsencrypt/live/mailmm/fullchain.pem
test -s /etc/letsencrypt/live/mailmm/privkey.pem

# The only two SMTP listeners are on the dedicated worker network. Keep the
# queue host distinct from the public MX so DSNs can route back to that MX.
postconf -MX smtp/inet
postconf -e "myhostname = submission.$FORWARD_SIGNING_DOMAIN" "smtp_helo_name = $MX_HOST" \
    'mydestination =' 'relay_domains =' 'inet_protocols = ipv4' \
    'mynetworks = 172.29.252.3/32' 'smtpd_relay_restrictions = permit_mynetworks,reject' \
    'smtpd_client_restrictions = permit_mynetworks,reject' 'smtpd_recipient_limit = 1' \
    'smtpd_tls_security_level = encrypt' 'smtpd_tls_cert_file = /etc/letsencrypt/live/mailmm/fullchain.pem' \
    'smtpd_tls_key_file = /etc/letsencrypt/live/mailmm/privkey.pem' 'smtp_tls_security_level = may' \
    'smtp_tls_CApath = /etc/ssl/certs' 'smtp_tls_loglevel = 1' 'message_size_limit = 41943040' \
    'smtpd_milters = inet:127.0.0.1:11332' 'milter_default_action = tempfail' 'milter_protocol = 6' \
    'smtpd_discard_ehlo_keywords = chunking' 'always_add_missing_headers = no' \
    'disable_mime_output_conversion = yes' 'enable_long_queue_ids = yes' 'maillog_file = /dev/stdout'
postconf -M '2465/inet=2465 inet n - n - - smtpd' '2466/inet=2466 inet n - n - - smtpd'
# Docker supplies the live resolver and trust store outside the persistent spool.
postconf -F 'smtp/unix/chroot=n' 'relay/unix/chroot=n'
postconf -P '2465/inet/milter_macro_daemon_name=mailmm-manual' '2466/inet/milter_macro_daemon_name=mailmm-auto'
exec postfix start-fg
