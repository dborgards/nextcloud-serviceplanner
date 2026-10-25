<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Service;

use DomainException;
use OCA\ServicePlanner\Service\PlanService;
use OCA\ServicePlanner\Tests\Unit\Support\InMemoryPlanRepository;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/InMemoryPlanRepository.php';

final class PlanServiceTest extends TestCase {
    public function testCreatesAndListsPlans(): void {
        $repository = new InMemoryPlanRepository();
        $service = new PlanService($repository);

        $created = $service->create(['name' => 'Sunday team', 'tableId' => 9]);

        self::assertSame(1, $created['id']);
        self::assertSame([$created], $service->list());
    }

    public function testRejectsDuplicateNamesIgnoringCase(): void {
        $service = new PlanService(new InMemoryPlanRepository());
        $service->create(['name' => 'Sunday team', 'tableId' => 9]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A plan with this name already exists.');
        $service->create(['name' => 'sunday TEAM', 'tableId' => 12]);
    }
}
