<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Support;

use OCA\ServicePlanner\Repository\PlanRepository;
use OCA\ServicePlanner\Service\SetupException;

final class InMemoryPlanRepository implements PlanRepository {
    private array $plans = [];
    private int $nextId = 1;

    public function add(array $definition): array {
        $id = $this->nextId++;
        $now = time();
        $plan = ['id' => $id, 'revision' => 1, 'createdAt' => $now, 'updatedAt' => $now, ...$definition];
        $this->plans[$id] = $plan;
        return $plan;
    }

    public function all(): array {
        return array_values($this->plans);
    }

    public function findByName(string $name): ?array {
        foreach ($this->plans as $plan) {
            if (mb_strtolower($plan['name']) === mb_strtolower($name)) {
                return $plan;
            }
        }
        return null;
    }

    public function findById(int $id): ?array {
        return $this->plans[$id] ?? null;
    }

    public function update(int $id, int $expectedRevision, array $definition): array {
        $current = $this->plans[$id] ?? null;
        if ($current === null || $current['revision'] !== $expectedRevision) {
            throw new SetupException('The plan changed in another session. Reload before saving.', 409);
        }
        $updated = [
            'id' => $id,
            'revision' => $expectedRevision + 1,
            'createdAt' => $current['createdAt'],
            'updatedAt' => time(),
            ...$definition,
        ];
        $this->plans[$id] = $updated;
        return $updated;
    }

    public function delete(int $id): void {
        unset($this->plans[$id]);
    }
}
