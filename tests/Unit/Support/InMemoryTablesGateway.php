<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Support;

use OCA\ServicePlanner\Service\Tables\TablesGateway;

final class InMemoryTablesGateway implements TablesGateway {
    public array $rows = [];
    public array $calls = [];
    public ?\Throwable $listTablesError = null;
    public array $createdTable = [];

    public function __construct(private array $tables) {
    }

    public function isAvailable(?string $userId): bool {
        return $userId !== null;
    }

    public function listTables(string $userId): array {
        $this->calls[] = 'listTables';
        if ($this->listTablesError !== null) {
            throw $this->listTablesError;
        }
        return array_map(fn (array $table): array => $this->metadataFor($table), array_values($this->tables));
    }

    public function createServiceTable(string $title, string $emoji, string $description, string $userId): array {
        $this->calls[] = 'createServiceTable';
        return $this->createdTable;
    }

    public function metadata(int $tableId, string $userId): array {
        $this->calls[] = 'metadata';
        return $this->metadataFor($this->tables[$tableId]);
    }

    public function previewRows(int $tableId, string $userId, array $columnIds, int $limit = 10): array {
        $this->calls[] = 'previewRows';
        $wanted = array_fill_keys($columnIds, true);
        return array_map(static function (array $row) use ($wanted): array {
            $cells = [];
            foreach ($row['cells'] as $columnId => $value) {
                if (isset($wanted[$columnId])) {
                    $cells[$columnId] = $value;
                }
            }
            return ['id' => $row['id'], 'cells' => $cells];
        }, array_slice($this->rows, 0, $limit));
    }

    public function canManage(int $tableId, string $userId): bool {
        $this->calls[] = 'canManage';
        return $this->tables[$tableId]['canManage'] ?? false;
    }

    public function canUpdateRows(int $tableId, string $userId): bool {
        $this->calls[] = 'canUpdateRows';
        return $this->tables[$tableId]['canUpdateRows'] ?? false;
    }

    public function setMetadata(int $tableId, array $metadata): void {
        $this->tables[$tableId]['metadata'] = $metadata;
    }

    private function metadataFor(array $table): array {
        return $table['metadata'] ?? ['id' => 0, 'title' => '', 'columns' => []];
    }
}
