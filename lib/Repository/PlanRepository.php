<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Repository;

interface PlanRepository {
    public function add(array $definition): array;
    public function all(): array;
    public function findByName(string $name): ?array;
    public function findById(int $id): ?array;
    public function update(int $id, int $expectedRevision, array $definition): array;
    public function delete(int $id): void;
}
