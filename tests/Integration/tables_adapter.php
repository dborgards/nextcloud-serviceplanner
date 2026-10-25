<?php

declare(strict_types=1);

use OCA\ServicePlanner\Service\SetupException;
use OCA\ServicePlanner\Service\Tables\TablesAdapter;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\ShareService;
use OCA\Tables\Service\ValueObject\ShareCreate;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT');
$uid = getenv('SERVICEPLANNER_TEST_USER');
check(is_string($nextcloudRoot) && $nextcloudRoot !== '', 'NEXTCLOUD_ROOT is required');
check(is_string($uid) && $uid !== '', 'SERVICEPLANNER_TEST_USER is required');

define('OC_CONSOLE', true);
define('PHPUNIT_RUN', 1);
$_SERVER['SCRIPT_FILENAME'] = $nextcloudRoot . '/occ';
$_SERVER['SCRIPT_NAME'] = '/occ';
require $nextcloudRoot . '/lib/base.php';
require __DIR__ . '/../bootstrap.php';

$user = Server::get(IUserManager::class)->get($uid);
check($user !== null, 'integration user exists');
Server::get(IUserSession::class)->setUser($user);

$groupManager = Server::get(IGroupManager::class);
if (!$groupManager->groupExists('serviceplanner-adapter-group')) {
	$groupManager->createGroup('serviceplanner-adapter-group');
}

$appManager = Server::get(IAppManager::class);
try {
	$adapter = Server::get(TablesAdapter::class);
	$title = 'ServicePlanner adapter ' . bin2hex(random_bytes(4));
	$table = $adapter->createServiceTable($title, '📋', 'adapter integration', $uid);
	check($table['id'] > 0, 'created numeric table ID');
	check(count($table['columns']) === 4, 'date, title, notes and team columns');
	check($table['columns'][0]['type'] === 'datetime', 'date type');
	check($table['columns'][0]['subtype'] === 'date', 'date subtype');
	check($table['permissions']['canManage'] === true, 'creator manages table');
	check($table['permissions']['canUpdateRows'] === true, 'creator updates rows');
	check($table['tablesUrl'] === '/apps/tables/#/table/' . $table['id'], 'deep link');

	$listed = array_column($adapter->listTables($uid), null, 'id');
	check(isset($listed[$table['id']]), 'existing table selection');
	$columnIds = array_column($table['columns'], 'id');
	$row = Server::get(RowService::class)->create($table['id'], null, [
		['columnId' => $columnIds[0], 'value' => '2027-01-10'],
		['columnId' => $columnIds[1], 'value' => 'Real preview title'],
		['columnId' => $columnIds[3], 'value' => 'Ada, Grace'],
	], $uid);
	$preview = $adapter->previewRows($table['id'], $uid, [$columnIds[1], $columnIds[3]], 10);
	check(count($preview) === 1, 'one real row');
	check($preview[0]['cells'][$columnIds[1]] === 'Real preview title', 'real title value');
	check(!isset($preview[0]['cells'][$columnIds[0]]), 'unrequested column omitted');

	Server::get(ShareService::class)->create(new ShareCreate(
		$table['id'], 'table', 'serviceplanner-adapter-group', 'group',
		true, false, false, false, false, 0,
	));
	$withShares = $adapter->metadata($table['id'], $uid);
	check($withShares['permissions']['sharesVisible'] === true, 'manager share inspection');
	check($withShares['shares'][0]['receiver'] === 'serviceplanner-adapter-group', 'group share');
	check(!array_key_exists('token', $withShares['shares'][0]), 'no token leak');
	check(!array_key_exists('password', $withShares['shares'][0]), 'no password leak');
	$listedWithShares = array_column($adapter->listTables($uid), null, 'id');
	check($listedWithShares[$table['id']]['shares'] === [], 'table selection omits shares');
	check($listedWithShares[$table['id']]['permissions']['sharesVisible'] === false, 'table selection defers share inspection');

	$appManager->disableApp('tables');
	check($adapter->isAvailable($uid) === false, 'disabled Tables is unavailable');
	$unavailableStatus = null;
	try {
		$adapter->metadata($table['id'], $uid);
	} catch (SetupException $error) {
		$unavailableStatus = $error->getStatus();
	}
	check($unavailableStatus === 503, 'disabled Tables returns safe 503');

	echo "Tables adapter integration OK\n";
} finally {
	if (!$appManager->isEnabledForUser('tables', $user)) {
		$appManager->enableApp('tables');
	}
}
