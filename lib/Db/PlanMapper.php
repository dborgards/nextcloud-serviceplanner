<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Db;

use OCA\ServicePlanner\Domain\PlanDefinition;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

final class PlanMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'serviceplanner_plans', Plan::class);
    }

    public function insertDefinition(array $definition, int $now): Plan {
        $plan = new Plan();
        $plan->setName($definition['name']);
        $plan->setNameKey(PlanDefinition::nameKey($definition['name']));
        $plan->setDefinition(json_encode($definition, JSON_THROW_ON_ERROR));
        $plan->setCreatedAt($now);
        $plan->setUpdatedAt($now);
        return $this->insert($plan);
    }

    public function findAllPlans(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('name', 'ASC');
        return $this->findEntities($qb);
    }

    public function findByName(string $name): ?Plan {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('name_key', $qb->createNamedParameter(PlanDefinition::nameKey($name))))
            ->setMaxResults(1);
        return $this->findEntities($qb)[0] ?? null;
    }

}
