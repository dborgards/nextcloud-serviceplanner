<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service;

use DomainException;
use OCA\ServicePlanner\Domain\PlanDefinition;
use OCA\ServicePlanner\Repository\PlanRepository;

final class PlanService {
    public function __construct(private PlanRepository $repository) {
    }

    public function create(array $input): array {
        $definition = PlanDefinition::fromArray($input);
        if ($this->repository->findByName($definition->getName()) !== null) {
            throw new DomainException('A plan with this name already exists.');
        }
        return $this->repository->add($definition->jsonSerialize());
    }

    public function list(): array {
        return $this->repository->all();
    }
}
