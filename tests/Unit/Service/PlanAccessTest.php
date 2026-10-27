<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Service;

use OCA\ServicePlanner\Service\PlanAccess;
use OCA\ServicePlanner\Service\SetupException;
use OCA\ServicePlanner\Tests\Unit\Support\InMemoryGroupDirectory;
use OCA\ServicePlanner\Tests\Unit\Support\InMemoryTablesGateway;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/InMemoryGroupDirectory.php';
require_once dirname(__DIR__) . '/Support/InMemoryTablesGateway.php';

final class PlanAccessTest extends TestCase {
    public function testManagerRequiresBothConfiguredGroupAndTablesManage(): void {
        $gateway = new InMemoryTablesGateway([42 => ['canManage' => false]]);
        $groups = new InMemoryGroupDirectory(['alice' => ['planner-admins']], []);
        $access = new PlanAccess($groups, $gateway);
        $plan = ['tableId' => 42, 'permissions' => ['managerGroups' => ['planner-admins'], 'visibleGroups' => ['planner-admins']]];

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('Plan is not available.');
        $access->assertPlanManager($plan, 'alice');
    }

    public function testManagerGroupAloneNeverExpandsTablesRightsAndWrongPlanIsNotEnumerable(): void {
        $access = new PlanAccess(
            new InMemoryGroupDirectory(['alice' => ['planner-admins']], []),
            new InMemoryTablesGateway([42 => ['canManage' => true]]),
        );
        $plan = ['id' => 7, 'tableId' => 42, 'permissions' => ['managerGroups' => ['other-group'], 'visibleGroups' => ['planner-admins']]];

        try {
            $access->assertPlanManager($plan, 'alice');
            self::fail('Expected denial.');
        } catch (SetupException $error) {
            self::assertSame(404, $error->getStatus());
            self::assertSame('Plan is not available.', $error->getMessage());
        }
    }

    public function testMatchingManagerWithTablesManageSucceeds(): void {
        $access = new PlanAccess(
            new InMemoryGroupDirectory(['alice' => ['planner-admins']], []),
            new InMemoryTablesGateway([42 => ['canManage' => true]]),
        );

        $access->assertPlanManager($this->plan(), 'alice');

        $this->addToAssertionCount(1);
    }

    public function testVisibleGroupMembershipDoesNotGrantManagement(): void {
        $access = new PlanAccess(
            new InMemoryGroupDirectory(['alice' => ['volunteers']], []),
            new InMemoryTablesGateway([42 => ['canManage' => true]]),
        );

        $this->expectException(SetupException::class);
        $access->assertPlanManager($this->plan(), 'alice');
    }

    public function testLegacyDefinitionIsManageableOnlyByTablesManager(): void {
        $groups = new InMemoryGroupDirectory([], []);
        $legacy = ['id' => 7, 'tableId' => 42];
        $allowed = new PlanAccess($groups, new InMemoryTablesGateway([42 => ['canManage' => true]]));
        $allowed->assertPlanManager($legacy, 'alice');
        $this->addToAssertionCount(1);

        $denied = new PlanAccess($groups, new InMemoryTablesGateway([42 => ['canManage' => false]]));
        $this->expectException(SetupException::class);
        $denied->assertPlanManager($legacy, 'alice');
    }

    public function testTableManagerDenialIsNotEnumerable(): void {
        $access = new PlanAccess(new InMemoryGroupDirectory([], []), new InMemoryTablesGateway([]));

        try {
            $access->assertTableManager(42, 'alice');
            self::fail('Expected denial.');
        } catch (SetupException $error) {
            self::assertSame(404, $error->getStatus());
            self::assertSame('Tables table is not available for setup.', $error->getMessage());
        }
    }

    private function plan(): array {
        return [
            'id' => 7,
            'tableId' => 42,
            'permissions' => ['managerGroups' => ['planner-admins'], 'visibleGroups' => ['planner-admins', 'volunteers']],
        ];
    }
}
