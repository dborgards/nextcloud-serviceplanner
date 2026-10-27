#!/usr/bin/env bash
set -euo pipefail

: "${NEXTCLOUD_ROOT:?Set NEXTCLOUD_ROOT to the disposable Nextcloud root}"
: "${OCC_USER?Set OCC_USER to the web-server user, or to empty for the current user}"
: "${NEXTCLOUD_URL:?Set NEXTCLOUD_URL to the disposable Nextcloud origin}"
: "${SERVICEPLANNER_ADMIN_USER:?Set the Tables manager username}"
: "${SERVICEPLANNER_ADMIN_PASSWORD:?Set the Tables manager password}"
: "${SERVICEPLANNER_TEST_USER:?Set the non-Tables manager username}"
: "${SERVICEPLANNER_TEST_PASSWORD:?Set the non-Tables manager password}"
[[ "$NEXTCLOUD_ROOT" = /* ]] || { echo "NEXTCLOUD_ROOT must be absolute" >&2; exit 1; }

APP_DIR="$NEXTCLOUD_ROOT/apps/serviceplanner"
RUN_ID="$(date +%s%N)-$$-$RANDOM"
MANAGER_GROUP="planner-admins-$RUN_ID"
VISIBLE_GROUP="volunteers-$RUN_ID"
APP_LINK_CREATED=0

if [[ -e "$APP_DIR" ]]; then
    echo "Refusing to replace existing $APP_DIR" >&2
    exit 1
fi

run_occ() {
    if [[ -n "$OCC_USER" ]]; then
        sudo -u "$OCC_USER" php "$NEXTCLOUD_ROOT/occ" "$@"
    else
        php "$NEXTCLOUD_ROOT/occ" "$@"
    fi
}

cleanup() {
    local status=$?
    if [[ $APP_LINK_CREATED -eq 1 && -L "$APP_DIR" ]]; then
        rm "$APP_DIR"
    fi
    run_occ group:delete "$MANAGER_GROUP" >/dev/null 2>&1 || true
    run_occ group:delete "$VISIBLE_GROUP" >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT

ensure_user() {
    local username=$1
    local password=$2
    if run_occ user:info "$username" >/dev/null 2>&1; then
        OC_PASS="$password" run_occ user:resetpassword --password-from-env "$username"
    else
        OC_PASS="$password" run_occ user:add --password-from-env "$username"
    fi
}

ensure_user "$SERVICEPLANNER_ADMIN_USER" "$SERVICEPLANNER_ADMIN_PASSWORD"
ensure_user "$SERVICEPLANNER_TEST_USER" "$SERVICEPLANNER_TEST_PASSWORD"
run_occ group:add "$MANAGER_GROUP"
run_occ group:add "$VISIBLE_GROUP"
run_occ group:adduser "$MANAGER_GROUP" "$SERVICEPLANNER_ADMIN_USER"
run_occ group:adduser "$VISIBLE_GROUP" "$SERVICEPLANNER_ADMIN_USER"
run_occ group:adduser "$MANAGER_GROUP" "$SERVICEPLANNER_TEST_USER"
export SERVICEPLANNER_MANAGER_GROUP="$MANAGER_GROUP"
export SERVICEPLANNER_VISIBLE_GROUP="$VISIBLE_GROUP"
export NEXTCLOUD_ROOT NEXTCLOUD_URL
export SERVICEPLANNER_ADMIN_USER SERVICEPLANNER_ADMIN_PASSWORD
export SERVICEPLANNER_TEST_USER SERVICEPLANNER_TEST_PASSWORD

php -v
node --version
npm --version
npm ci
npm test
npm run build
composer test
NEXTCLOUD_ROOT="$NEXTCLOUD_ROOT" OCC_USER="$OCC_USER" tests/Integration/smoke.sh
NEXTCLOUD_ROOT="$NEXTCLOUD_ROOT" php -r '
require getenv("NEXTCLOUD_ROOT") . "/lib/base.php";
$nextcloud = OCP\Util::getVersion();
$tables = OCP\Server::get(OCP\App\IAppManager::class)->getAppVersion("tables");
if ($nextcloud[0] !== 35 || $tables !== "2.3.2") {
    fwrite(STDERR, "Requires Nextcloud 35 and Tables 2.3.2; got PHP " . PHP_VERSION . ", Nextcloud " . implode(".", $nextcloud) . ", Tables $tables\n");
    exit(1);
}
'
NEXTCLOUD_ROOT="$NEXTCLOUD_ROOT" SERVICEPLANNER_TEST_USER="$SERVICEPLANNER_ADMIN_USER" php tests/Integration/tables_adapter.php

ln -s "$(pwd)" "$APP_DIR"
APP_LINK_CREATED=1
run_occ app:enable serviceplanner
npx playwright install chromium
npm run test:e2e
git diff --check
