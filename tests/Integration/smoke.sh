#!/usr/bin/env bash
set -euo pipefail

: "${NEXTCLOUD_ROOT:?Set NEXTCLOUD_ROOT to a disposable Nextcloud 35 or 36 checkout}"
: "${OCC_USER?Set OCC_USER to the web-server user, or to empty for the current user}"
APP_DIR="$NEXTCLOUD_ROOT/apps/serviceplanner"
RACE_DIR=''
RUN_ID="$(date +%s%N)-$$-$RANDOM"
SMOKE_NAME="Smoke plan $RUN_ID"
SMOKE_CASE_NAME="sMOKE pLAN $RUN_ID"
CONCURRENT_NAME="Concurrent plan $RUN_ID"
CONCURRENT_CASE_NAME="cONCURRENT PLAN $RUN_ID"

if [[ -e "$APP_DIR" ]]; then
    echo "Refusing to replace existing $APP_DIR" >&2
    exit 1
fi
ln -s "$(pwd)" "$APP_DIR"
trap 'rm "$APP_DIR"; if [[ -n "$RACE_DIR" ]]; then rm -rf "$RACE_DIR"; fi' EXIT

run_occ() {
    if [[ -n "$OCC_USER" ]]; then
        sudo -u "$OCC_USER" php "$NEXTCLOUD_ROOT/occ" "$@"
    else
        php "$NEXTCLOUD_ROOT/occ" "$@"
    fi
}

run_occ app:disable serviceplanner >/dev/null 2>&1 || true
run_occ app:enable serviceplanner
run_occ serviceplanner:create --name "$SMOKE_NAME" --table-id 1
run_occ serviceplanner:list | grep -F "$SMOKE_NAME"
set +e
duplicate_output=$(run_occ serviceplanner:create --name "$SMOKE_NAME" --table-id 2 2>&1)
duplicate_status=$?
set -e
[[ $duplicate_status -ne 0 ]]
grep -F 'A plan with this name already exists.' <<<"$duplicate_output"
set +e
case_duplicate_output=$(run_occ serviceplanner:create --name "$SMOKE_CASE_NAME" --table-id 2 2>&1)
case_duplicate_status=$?
set -e
[[ $case_duplicate_status -ne 0 ]]
grep -F 'A plan with this name already exists.' <<<"$case_duplicate_output"

RACE_DIR=$(mktemp -d)
run_occ serviceplanner:create --name "$CONCURRENT_NAME" --table-id 3 >"$RACE_DIR/first.log" 2>&1 &
first_pid=$!
run_occ serviceplanner:create --name "$CONCURRENT_CASE_NAME" --table-id 4 >"$RACE_DIR/second.log" 2>&1 &
second_pid=$!
set +e
wait "$first_pid"
first_status=$?
wait "$second_pid"
second_status=$?
set -e
cat "$RACE_DIR/first.log" "$RACE_DIR/second.log"
[[ $first_status -eq 0 && $second_status -ne 0 || $first_status -ne 0 && $second_status -eq 0 ]]
if [[ $first_status -ne 0 ]]; then
    race_failure_output=$(<"$RACE_DIR/first.log")
else
    race_failure_output=$(<"$RACE_DIR/second.log")
fi
grep -F 'A plan with this name already exists.' <<<"$race_failure_output"
if grep -E 'SQLSTATE|UniqueConstraintViolation|Doctrine\\|database is locked|An exception occurred' <<<"$race_failure_output"; then
    echo "Concurrent duplicate exposed a raw database error" >&2
    exit 1
fi
run_occ serviceplanner:list | grep -iF "$CONCURRENT_NAME"
run_occ app:disable serviceplanner
