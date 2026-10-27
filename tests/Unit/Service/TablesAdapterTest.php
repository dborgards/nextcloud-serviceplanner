<?php

declare(strict_types=1);

namespace OCA\Tables\Service {
	class TableService {
		public array $tables = [];
		public function findAll(string $userId, bool $skipColumns, bool $skipShares, bool $skipRows): array {
			return $this->tables;
		}
		public function find(int $tableId, bool $includeShares, string $userId): object {
			return $this->tables[0];
		}
	}
	class ColumnService {
		public function findAllByTable(int $tableId, string $userId, object $table): array {
			return [];
		}
	}
	class RowService {
	}
	class ShareService {
		public int $calls = 0;
		public function findAll(string $type, int $tableId, string $userId): array {
			$this->calls++;
			return [new AdapterShare()];
		}
	}
	class PermissionsService {
		public function canManageTableById(int $tableId, string $userId): bool {
			return true;
		}
		public function canUpdateRowsByTableId(int $tableId, string $userId): bool {
			return true;
		}
	}
	class AdapterShare {
		public function getReceiverType(): string { return 'group'; }
		public function getReceiver(): string { return 'volunteers'; }
		public function getReceiverDisplayName(): string { return 'Volunteers'; }
		public function getPermissionRead(): bool { return true; }
		public function getPermissionCreate(): bool { return false; }
		public function getPermissionUpdate(): bool { return true; }
		public function getPermissionDelete(): bool { return false; }
		public function getPermissionManage(): bool { return false; }
	}
}

namespace OCP\App {
	interface IAppManager {
		public function isEnabledForUser(string $appId, object $user): bool;
	}
}

namespace OCP {
	interface IUserManager {
		public function get(string $userId): ?object;
	}
	final class Server {
		public static array $services = [];
		public static function get(string $class): object {
			return self::$services[$class];
		}
	}
}

namespace OCA\ServicePlanner\Tests\Unit\Service {
	use OCA\ServicePlanner\Service\Tables\TablesAdapter;
	use OCA\Tables\Service\ColumnService;
	use OCA\Tables\Service\PermissionsService;
	use OCA\Tables\Service\RowService;
	use OCA\Tables\Service\ShareService;
	use OCA\Tables\Service\TableService;
	use OCP\App\IAppManager;
	use OCP\IUserManager;
	use OCP\Server;
	use PHPUnit\Framework\TestCase;

	final class TablesAdapterTest extends TestCase {
		private ShareService $shares;
		private TablesAdapter $adapter;

		protected function setUp(): void {
			$table = new class {
				public function getId(): int { return 42; }
				public function getTitle(): string { return 'Sunday services'; }
				public function getEmoji(): string { return '📋'; }
				public function getDescription(): string { return 'Services'; }
				public function getOwnership(): string { return 'alice'; }
			};
			$tables = new TableService();
			$tables->tables = [$table];
			$this->shares = new ShareService();
			Server::$services = [
				TableService::class => $tables,
				ColumnService::class => new ColumnService(),
				RowService::class => new RowService(),
				ShareService::class => $this->shares,
				PermissionsService::class => new PermissionsService(),
			];
			$user = (object)['uid' => 'alice'];
			$appManager = new class implements IAppManager {
				public function isEnabledForUser(string $appId, object $user): bool { return true; }
			};
			$userManager = new class($user) implements IUserManager {
				public function __construct(private object $user) {
				}
				public function get(string $userId): ?object { return $this->user; }
			};
			$this->adapter = new TablesAdapter($appManager, $userManager);
		}

		public function testListTablesDoesNotQueryDiscardedShares(): void {
			$result = $this->adapter->listTables('alice');

			self::assertSame(0, $this->shares->calls);
			self::assertSame([], $result[0]['shares']);
			self::assertFalse($result[0]['permissions']['sharesVisible']);
		}

		public function testMetadataStillLoadsCompleteShares(): void {
			$result = $this->adapter->metadata(42, 'alice');

			self::assertSame(1, $this->shares->calls);
			self::assertTrue($result['permissions']['sharesVisible']);
			self::assertSame('volunteers', $result['shares'][0]['receiver']);
			self::assertSame('Volunteers', $result['shares'][0]['receiverDisplayName']);
		}
	}
}
