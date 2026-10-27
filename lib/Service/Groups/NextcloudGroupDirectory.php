<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service\Groups;

use OCP\IGroupManager;
use OCP\IUserManager;

final class NextcloudGroupDirectory implements GroupDirectory {
    public function __construct(private IGroupManager $groups, private IUserManager $users) {
    }

    public function groupsForUser(string $userId): array {
        $user = $this->users->get($userId);
        return $user === null ? [] : $this->groups->getUserGroupIds($user);
    }

    public function listGroups(): array {
        $groups = array_map(static fn ($group): array => [
            'id' => $group->getGID(),
            'displayName' => $group->getDisplayName(),
        ], $this->groups->search(''));
        usort($groups, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        return $groups;
    }
}
