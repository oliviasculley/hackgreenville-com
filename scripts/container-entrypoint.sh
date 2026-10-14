#!/bin/bash
# Report an unexpected container exit to Slack, then pass that exit code on.
#
# serversideup's entrypoint execs /init, so a crash inside s6 (the staging
# boot failure is one example) never reaches Laravel. This wrapper stays in
# front of that entrypoint, keeps the output flowing to the container log,
# and on an unexpected exit records it with `php artisan container:report-failure`.
# That command uses Log::error, which already posts to the Slack channel
# configured by LOG_SLACK_WEBHOOK_URL.
#
# Railway stops this image with SIGQUIT. A stop is not a failure, so signals
# are forwarded and not reported.

set -u

entrypoint_bin="${CONTAINER_ENTRYPOINT_BIN:-docker-php-serversideup-entrypoint}"
php_bin="${CONTAINER_PHP_BIN:-php}"

if [[ -n "${CONTAINER_APP_DIR:-}" ]]; then
    app_dir="$CONTAINER_APP_DIR"
elif [[ -f /var/www/html/artisan ]]; then
    app_dir=/var/www/html
else
    app_dir="$(pwd)"
fi

child=""
tee_pid=""
fifo=""
log_file=""
stopping=0

cleanup_files() {
    local preserved=$?

    [[ -n "$fifo" ]] && rm -f "$fifo"
    [[ -n "$log_file" ]] && rm -f "$log_file"

    exit "$preserved"
}

forward_stop() {
    local signal="$1"

    stopping=1

    if [[ -n "$child" ]] && kill -0 "$child" 2>/dev/null; then
        kill "-${signal}" "$child" 2>/dev/null || true
    fi
}

trap cleanup_files EXIT
trap 'forward_stop QUIT' QUIT
trap 'forward_stop TERM' TERM
trap 'forward_stop INT' INT

log_file="$(mktemp)"
fifo="$(mktemp -u)"
mkfifo "$fifo"

if command -v stdbuf >/dev/null 2>&1; then
    stdbuf -oL -eL tee "$log_file" < "$fifo" &
else
    tee "$log_file" < "$fifo" &
fi
tee_pid=$!

"$entrypoint_bin" "$@" > "$fifo" 2>&1 &
child=$!

status=0
while true; do
    wait "$child"
    status=$?

    if ! kill -0 "$child" 2>/dev/null; then
        break
    fi
done

if [[ -n "$tee_pid" ]]; then
    wait "$tee_pid" || true
fi

# 130 SIGINT, 131 SIGQUIT, 143 SIGTERM. 137 (SIGKILL, including OOM) is reported.
if [[ "$stopping" -eq 0 && "$status" -ne 0 && "$status" -ne 130 && "$status" -ne 131 && "$status" -ne 143 ]]; then
    if [[ -d "$app_dir" ]]; then
        if command -v timeout >/dev/null 2>&1; then
            (cd "$app_dir" && timeout 30 "$php_bin" artisan container:report-failure --no-interaction --exit-code="$status" --log="$log_file") || true
        else
            (cd "$app_dir" && "$php_bin" artisan container:report-failure --no-interaction --exit-code="$status" --log="$log_file") || true
        fi
    fi
fi

exit "$status"
