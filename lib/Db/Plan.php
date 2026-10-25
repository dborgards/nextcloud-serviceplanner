<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Db;

use OCP\AppFramework\Db\Entity;

final class Plan extends Entity {
    protected string $name = '';
    protected string $nameKey = '';
    protected string $definition = '{}';
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function toArray(): array {
        $definition = json_decode($this->definition, true, 512, JSON_THROW_ON_ERROR);
        return ['id' => $this->getId(), ...$definition];
    }
}
