#!/bin/sh
set -eu

root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
scratch=$(mktemp -d)
trap 'rm -f "$scratch/docker" "$scratch/calls" "$scratch"/*.pending; rmdir "$scratch"' EXIT
export PATH="$scratch:$PATH" STATE_DIRECTORY="$scratch"

cat > "$scratch/docker" <<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$STATE_DIRECTORY/calls"
case " $* " in
    *' certbot renew '*)
        if [ "$ISSUE_CERT" = yes ]; then
            while [ "$1" != --deploy-hook ]; do shift; done
            shift
            for marker in ${1#touch }; do
                touch "$STATE_DIRECTORY/${marker##*/}"
            done
        fi ;;
    ' ps '* )
        if [ "$OUTBOUND_STATE" = running ]; then
            echo outbound-id
        else
            case " $* " in
                *' status=running '*) ;;
                *) echo outbound-id ;;
            esac
        fi ;;
    ' exec outbound-id postfix reload ')
        [ "$OUTBOUND_STATE" = running ] && [ "$OUTBOUND_RELOAD" = ok ] ;;
esac
SH
chmod +x "$scratch/docker"

ISSUE_CERT=yes OUTBOUND_STATE=stopped OUTBOUND_RELOAD=fail sh "$root/ops/systemd/mailmm-cert-renew"
test -z "$(find "$scratch" -name '*.pending' -print)"
test "$(grep -c 'exec -T smtp postfix reload' "$scratch/calls")" -eq 1
if grep -q '^exec outbound-id postfix reload$' "$scratch/calls"; then
    exit 1
fi

: > "$scratch/calls"
touch "$scratch/postfix.pending"
if ISSUE_CERT=no OUTBOUND_STATE=running OUTBOUND_RELOAD=fail sh "$root/ops/systemd/mailmm-cert-renew"; then
    exit 1
fi
test -f "$scratch/outbound.pending"
test ! -f "$scratch/postfix.pending"
test "$(grep -c 'exec -T smtp postfix reload' "$scratch/calls")" -eq 1

ISSUE_CERT=no OUTBOUND_STATE=running OUTBOUND_RELOAD=ok sh "$root/ops/systemd/mailmm-cert-renew"
test -z "$(find "$scratch" -name '*.pending' -print)"
test "$(grep -c 'exec -T smtp postfix reload' "$scratch/calls")" -eq 1
test "$(grep -c '^exec outbound-id postfix reload$' "$scratch/calls")" -eq 2
