<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Repository;

interface PlanRepository {
    public function add(array $definition): array;
    public function all(): array;
    public function findByName(string $name): ?array;
}
