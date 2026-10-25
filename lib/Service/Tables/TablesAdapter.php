<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service\Tables;

use OCA\ServicePlanner\Service\SetupException;
use OCA\Tables\Dto\Column as ColumnDto;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Service\ColumnService;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\ShareService;
use OCA\Tables\Service\TableService;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Server;
use Throwable;

final class TablesAdapter implements TablesGateway {
	public function __construct(private IAppManager $appManager, private IUserManager $userManager) {
	}

	public function isAvailable(?string $userId): bool {
		if ($userId === null) {
			return false;
		}
		$user = $this->userManager->get($userId);
		return $user !== null && $this->appManager->isEnabledForUser('tables', $user);
	}

	public function listTables(string $userId): array {
		try {
			$tables = $this->tableService($userId)->findAll($userId, true, false, false);
			return array_map(function ($table) use ($userId): array {
				$dto = $this->tableDto($table, [], $userId);
				$dto['shares'] = [];
				$dto['permissions']['sharesVisible'] = false;
				return $dto;
			}, $tables);
		} catch (SetupException $error) {
			throw $error;
		} catch (NotFoundError|PermissionError $error) {
			throw new SetupException('Tables table is not available.', 404, [], $error);
		} catch (Throwable $error) {
			throw new SetupException('Tables operation failed.', 500, [], $error);
		}
	}

	public function createServiceTable(string $title, string $emoji, string $description, string $userId): array {
		try {
			$table = $this->tableService($userId)->create($title, 'custom', $emoji, $description, $userId);
		} catch (SetupException $error) {
			throw $error;
		} catch (NotFoundError|PermissionError $error) {
			throw new SetupException('Tables table is not available.', 404, [], $error);
		} catch (Throwable $error) {
			throw new SetupException('Tables operation failed.', 500, [], $error);
		}

		$tableId = (int)$table->getId();
		$definitions = [
			['title' => 'Date', 'type' => 'datetime', 'subtype' => 'date', 'mandatory' => true, 'description' => 'Service date'],
			['title' => 'Title', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'description' => 'Service title'],
			['title' => 'Notes', 'type' => 'text', 'subtype' => 'long', 'mandatory' => false, 'description' => 'Additional information'],
			['title' => 'Team', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'description' => 'Comma, semicolon or newline separated display names'],
		];
		try {
			foreach ($definitions as $definition) {
				$this->columnService($userId)->create($userId, $tableId, null, ColumnDto::createFromArray($definition));
			}
		} catch (Throwable $error) {
			throw new SetupException("Table $tableId was created, but its template is incomplete; select it to inspect the structure.", 500, [], $error);
		}
		return $this->metadata($tableId, $userId);
	}

	public function metadata(int $tableId, string $userId): array {
		try {
			$table = $this->tableService($userId)->find($tableId, true, $userId);
			$columns = array_map(static fn ($column): array => [
				'id' => (int)$column->getId(),
				'title' => $column->getTitle(),
				'type' => $column->getType(),
				'subtype' => $column->getSubtype(),
				'mandatory' => $column->getMandatory(),
				'description' => $column->getDescription(),
			], $this->columnService($userId)->findAllByTable($tableId, $userId, $table));
			return $this->tableDto($table, $columns, $userId);
		} catch (SetupException $error) {
			throw $error;
		} catch (NotFoundError|PermissionError $error) {
			throw new SetupException('Tables table is not available.', 404, [], $error);
		} catch (Throwable $error) {
			throw new SetupException('Tables operation failed.', 500, [], $error);
		}
	}

	public function previewRows(int $tableId, string $userId, array $columnIds, int $limit = 10): array {
		try {
			$metadata = $this->metadata($tableId, $userId);
			$entities = $this->rowService($userId)->findAllByTable($tableId, $userId, min(10, max(1, $limit)), 0);
			$wanted = array_fill_keys(array_intersect($columnIds, array_column($metadata['columns'], 'id')), true);
			$rows = [];
			foreach ($entities as $entity) {
				$cells = [];
				foreach ($entity->getData() ?? [] as $cell) {
					$id = (int)$cell['columnId'];
					if (isset($wanted[$id])) {
						$cells[$id] = $cell['value'];
					}
				}
				$rows[] = ['id' => (int)$entity->getId(), 'cells' => $cells];
			}
			return $rows;
		} catch (SetupException $error) {
			throw $error;
		} catch (NotFoundError|PermissionError $error) {
			throw new SetupException('Tables table is not available.', 404, [], $error);
		} catch (Throwable $error) {
			throw new SetupException('Tables operation failed.', 500, [], $error);
		}
	}

	public function canManage(int $tableId, string $userId): bool {
		try {
			return $this->permissionsService($userId)->canManageTableById($tableId, $userId);
		} catch (SetupException $error) {
			throw $error;
		} catch (NotFoundError|PermissionError $error) {
			throw new SetupException('Tables table is not available.', 404, [], $error);
		} catch (Throwable $error) {
			throw new SetupException('Tables operation failed.', 500, [], $error);
		}
	}

	public function canUpdateRows(int $tableId, string $userId): bool {
		try {
			return $this->permissionsService($userId)->canUpdateRowsByTableId($tableId, $userId);
		} catch (SetupException $error) {
			throw $error;
		} catch (NotFoundError|PermissionError $error) {
			throw new SetupException('Tables table is not available.', 404, [], $error);
		} catch (Throwable $error) {
			throw new SetupException('Tables operation failed.', 500, [], $error);
		}
	}

	private function tableDto($table, array $columns, string $userId): array {
		$id = (int)$table->getId();
		$canManage = $this->canManage($id, $userId);
		return [
			'id' => $id,
			'title' => $table->getTitle(),
			'emoji' => $table->getEmoji() ?? '',
			'description' => $table->getDescription(),
			'ownership' => $table->getOwnership() ?? '',
			'columns' => $columns,
			'permissions' => [
				'canManage' => $canManage,
				'canUpdateRows' => $this->canUpdateRows($id, $userId),
				'sharesVisible' => $canManage,
			],
			'shares' => $canManage ? $this->shares($id, $userId) : [],
			'tablesUrl' => '/apps/tables/#/table/' . $id,
		];
	}

	private function shares(int $tableId, string $userId): array {
		$shareService = $this->shareService($userId);
		return array_map(static fn ($share): array => [
			'receiverType' => $share->getReceiverType(),
			'receiver' => $share->getReceiver(),
			'receiverDisplayName' => $share->getReceiverDisplayName(),
			'read' => $share->getPermissionRead(),
			'create' => $share->getPermissionCreate(),
			'update' => $share->getPermissionUpdate(),
			'delete' => $share->getPermissionDelete(),
			'manage' => $share->getPermissionManage(),
		], $shareService->findAll('table', $tableId, $userId));
	}

	private function assertAvailable(string $userId): void {
		if (!$this->isAvailable($userId)) {
			throw new SetupException('Tables is not available.', 503);
		}
	}

	private function tableService(string $userId): TableService {
		$this->assertAvailable($userId);
		return Server::get(TableService::class);
	}

	private function columnService(string $userId): ColumnService {
		$this->assertAvailable($userId);
		return Server::get(ColumnService::class);
	}

	private function rowService(string $userId): RowService {
		$this->assertAvailable($userId);
		return Server::get(RowService::class);
	}

	private function shareService(string $userId): ShareService {
		$this->assertAvailable($userId);
		return Server::get(ShareService::class);
	}

	private function permissionsService(string $userId): PermissionsService {
		$this->assertAvailable($userId);
		return Server::get(PermissionsService::class);
	}
}
