<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service\Groups;

interface GroupDirectory {
    public function groupsForUser(string $userId): array;
    public function listGroups(): array;
}
