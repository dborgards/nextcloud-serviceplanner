<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Support;

use OCA\ServicePlanner\Repository\PlanRepository;

final class InMemoryPlanRepository implements PlanRepository {
    private array $plans = [];
    private int $nextId = 1;

    public function add(array $definition): array {
        $plan = ['id' => $this->nextId++, ...$definition];
        $this->plans[] = $plan;
        return $plan;
    }

    public function all(): array {
        return $this->plans;
    }

    public function findByName(string $name): ?array {
        foreach ($this->plans as $plan) {
            if (mb_strtolower($plan['name']) === mb_strtolower($name)) {
                return $plan;
            }
        }
        return null;
    }
}
