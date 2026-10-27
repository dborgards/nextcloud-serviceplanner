<?php

declare(strict_types=1);

use OCA\ServicePlanner\Service\Tables\TablesAdapter;
use OCA\Tables\Dto\Column as ColumnDto;
use OCA\Tables\Service\ColumnService;
use OCA\Tables\Service\RowService;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

function fixtureCheck(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT');
fixtureCheck(is_string($nextcloudRoot) && $nextcloudRoot !== '', 'NEXTCLOUD_ROOT is required');
fixtureCheck(isset($argv[1], $argv[2], $argv[3]), 'A command, table ID and user ID are required');
$command = $argv[1];
$tableId = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$uid = $argv[3];
fixtureCheck(is_int($tableId), 'Table ID must be a positive integer');
fixtureCheck($uid !== '', 'User ID is required');

define('OC_CONSOLE', true);
define('PHPUNIT_RUN', 1);
$_SERVER['SCRIPT_FILENAME'] = $nextcloudRoot . '/occ';
$_SERVER['SCRIPT_NAME'] = '/occ';
require $nextcloudRoot . '/lib/base.php';
require __DIR__ . '/../bootstrap.php';

$user = Server::get(IUserManager::class)->get($uid);
fixtureCheck($user !== null, 'Fixture user does not exist');
Server::get(IUserSession::class)->setUser($user);
$columnService = Server::get(ColumnService::class);

$columnByTitle = static function (string $title) use ($columnService, $tableId, $uid) {
	foreach ($columnService->findAllByTable($tableId, $uid) as $column) {
		if ($column->getTitle() === $title) {
			return $column;
		}
	}
	throw new RuntimeException("Tables column $title does not exist");
};

switch ($command) {
	case 'seed-row':
		fixtureCheck(isset($argv[4]) && $argv[4] !== '', 'A row title is required');
		$row = Server::get(RowService::class)->create($tableId, null, [
			['columnId' => (int)$columnByTitle('Date')->getId(), 'value' => '2027-01-10'],
			['columnId' => (int)$columnByTitle('Title')->getId(), 'value' => $argv[4]],
			['columnId' => (int)$columnByTitle('Notes')->getId(), 'value' => 'Seed note'],
			['columnId' => (int)$columnByTitle('Team')->getId(), 'value' => 'Ada, Grace'],
		], $uid);
		echo $row->getId() . "\n";
		break;
	case 'change-team-number':
		$teamId = (int)$columnByTitle('Team')->getId();
		$columnService->update($teamId, $uid, ColumnDto::createFromArray(['type' => 'number', 'subtype' => '']));
		echo "Team column $teamId changed to number\n";
		break;
	case 'delete-team':
		$teamId = (int)$columnByTitle('Team')->getId();
		$columnService->delete($teamId, false, $uid);
		echo "Team column $teamId deleted\n";
		break;
	case 'assert-row':
		fixtureCheck(isset($argv[4]) && $argv[4] !== '', 'A row title is required');
		$titleId = (int)$columnByTitle('Title')->getId();
		$rows = Server::get(TablesAdapter::class)->previewRows($tableId, $uid, [$titleId], 10);
		foreach ($rows as $row) {
			if (($row['cells'][$titleId] ?? null) === $argv[4]) {
				echo "Found seeded row {$row['id']}: {$argv[4]}\n";
				exit(0);
			}
		}
		throw new RuntimeException('No Tables row exactly matches the supplied title');
	default:
		throw new RuntimeException("Unknown fixture command: $command");
}
