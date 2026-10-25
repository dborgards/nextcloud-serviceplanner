<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Repository;

use OCA\ServicePlanner\Db\PlanMapper;

final class DatabasePlanRepository implements PlanRepository {
    public function __construct(private PlanMapper $mapper) {
    }

    public function add(array $definition): array {
        return $this->mapper->insertDefinition($definition, time())->toArray();
    }

    public function all(): array {
        return array_map(static fn ($plan) => $plan->toArray(), $this->mapper->findAllPlans());
    }

    public function findByName(string $name): ?array {
        foreach ($this->all() as $plan) {
            if (mb_strtolower($plan['name']) === mb_strtolower($name)) {
                return $plan;
            }
        }
        return null;
    }
}
