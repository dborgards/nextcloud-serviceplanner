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
        $plan->setRevision(1);
        $plan->setCreatedAt($now);
        $plan->setUpdatedAt($now);
        return $this->insert($plan);
    }

    public function updateDefinition(int $id, int $expectedRevision, array $definition, int $now): ?Plan {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('name', $qb->createNamedParameter($definition['name']))
            ->set('name_key', $qb->createNamedParameter(PlanDefinition::nameKey($definition['name'])))
            ->set('definition', $qb->createNamedParameter(json_encode($definition, JSON_THROW_ON_ERROR)))
            ->set('updated_at', $qb->createNamedParameter($now))
            ->set('revision', $qb->createFunction('revision + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision)));
        if ($qb->executeStatement() !== 1) {
            return null;
        }
        return $this->findById($id);
    }

    public function deleteDefinition(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
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

    public function findById(int $id): ?Plan {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        return $this->findEntities($qb)[0] ?? null;
    }
}
