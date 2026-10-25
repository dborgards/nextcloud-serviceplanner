<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Repository;

use DomainException;
use OCA\ServicePlanner\Db\PlanMapper;
use OCP\DB\Exception as DatabaseException;

final class DatabasePlanRepository implements PlanRepository {
    public function __construct(private PlanMapper $mapper) {
    }

    public function add(array $definition): array {
        try {
            return $this->mapper->insertDefinition($definition, time())->toArray();
        } catch (DatabaseException $error) {
            if ($error->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('A plan with this name already exists.', 0, $error);
            }
            throw $error;
        }
    }

    public function all(): array {
        return array_map(static fn ($plan) => $plan->toArray(), $this->mapper->findAllPlans());
    }

    public function findByName(string $name): ?array {
        return $this->mapper->findByName($name)?->toArray();
    }
}
