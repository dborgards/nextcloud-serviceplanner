<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Domain;

use InvalidArgumentException;
use JsonSerializable;

final class PlanDefinition implements JsonSerializable {
    private function __construct(
        private string $name,
        private int $tableId,
        private string $description,
        private string $icon,
        private string $color,
    ) {
    }

    public static function fromArray(array $input): self {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Plan name must not be empty.');
        }
        $tableId = filter_var($input['tableId'] ?? null, FILTER_VALIDATE_INT);
        if ($tableId === false || $tableId < 1) {
            throw new InvalidArgumentException('Tables table ID must be positive.');
        }
        $color = strtolower((string)($input['color'] ?? '#0082c9'));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new InvalidArgumentException('Color must use #rrggbb format.');
        }
        return new self(
            $name,
            $tableId,
            trim((string)($input['description'] ?? '')),
            trim((string)($input['icon'] ?? 'calendar')) ?: 'calendar',
            $color,
        );
    }

    public function getName(): string { return $this->name; }
    public function getTableId(): int { return $this->tableId; }
    public function getIcon(): string { return $this->icon; }
    public function getColor(): string { return $this->color; }

    public function jsonSerialize(): array {
        return [
            'name' => $this->name,
            'tableId' => $this->tableId,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }
}
