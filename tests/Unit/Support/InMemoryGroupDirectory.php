<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Support;

use OCA\ServicePlanner\Service\Groups\GroupDirectory;

final class InMemoryGroupDirectory implements GroupDirectory {
    public function __construct(private array $groupsByUser, private array $allGroups) {
    }

    public function groupsForUser(string $userId): array {
        return $this->groupsByUser[$userId] ?? [];
    }

    public function listGroups(): array {
        $groups = [];
        foreach ($this->allGroups as $id => $displayName) {
            $groups[] = ['id' => $id, 'displayName' => $displayName];
        }
        usort($groups, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        return $groups;
    }
}
