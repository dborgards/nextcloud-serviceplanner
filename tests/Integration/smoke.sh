#!/usr/bin/env bash
set -euo pipefail

: "${NEXTCLOUD_ROOT:?Set NEXTCLOUD_ROOT to a disposable Nextcloud 35 or 36 checkout}"
: "${OCC_USER?Set OCC_USER to the web-server user, or to empty for the current user}"
APP_DIR="$NEXTCLOUD_ROOT/apps/serviceplanner"

if [[ -e "$APP_DIR" ]]; then
    echo "Refusing to replace existing $APP_DIR" >&2
    exit 1
fi
ln -s "$(pwd)" "$APP_DIR"
trap 'rm "$APP_DIR"' EXIT

run_occ() {
    if [[ -n "$OCC_USER" ]]; then
        sudo -u "$OCC_USER" php "$NEXTCLOUD_ROOT/occ" "$@"
    else
        php "$NEXTCLOUD_ROOT/occ" "$@"
    fi
}

run_occ app:disable serviceplanner >/dev/null 2>&1 || true
run_occ app:enable serviceplanner
run_occ serviceplanner:create --name "Smoke plan" --table-id 1
run_occ serviceplanner:list | grep -F 'Smoke plan'
set +e
duplicate_output=$(run_occ serviceplanner:create --name "Smoke plan" --table-id 2 2>&1)
duplicate_status=$?
set -e
[[ $duplicate_status -ne 0 ]]
grep -F 'A plan with this name already exists.' <<<"$duplicate_output"
run_occ app:disable serviceplanner
