<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service\Tables;

interface TablesGateway {
	public function isAvailable(?string $userId): bool;
	public function listTables(string $userId): array;
	public function createServiceTable(string $title, string $emoji, string $description, string $userId): array;
	public function metadata(int $tableId, string $userId): array;
	public function previewRows(int $tableId, string $userId, array $columnIds, int $limit = 10): array;
	public function canManage(int $tableId, string $userId): bool;
	public function canUpdateRows(int $tableId, string $userId): bool;
}
