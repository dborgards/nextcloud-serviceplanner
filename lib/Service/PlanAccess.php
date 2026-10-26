<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service;

use OCA\ServicePlanner\Service\Groups\GroupDirectory;
use OCA\ServicePlanner\Service\Tables\TablesGateway;

final class PlanAccess {
    public function __construct(private GroupDirectory $groups, private TablesGateway $tables) {
    }

    public function assertTableManager(int $tableId, string $userId): void {
        if (!$this->tables->canManage($tableId, $userId)) {
            throw new SetupException('Tables table is not available for setup.', 404);
        }
    }

    public function assertPlanManager(array $plan, string $userId): void {
        $legacy = !isset($plan['permissions']['managerGroups']);
        $member = $legacy || array_intersect($plan['permissions']['managerGroups'], $this->groups->groupsForUser($userId)) !== [];
        if (!$member || !$this->tables->canManage((int)$plan['tableId'], $userId)) {
            throw new SetupException('Plan is not available.', 404);
        }
    }
}
