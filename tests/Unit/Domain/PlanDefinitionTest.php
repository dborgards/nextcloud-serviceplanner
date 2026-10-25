<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Domain;

use InvalidArgumentException;
use OCA\ServicePlanner\Domain\PlanDefinition;
use PHPUnit\Framework\TestCase;

final class PlanDefinitionTest extends TestCase {
    public function testNormalizesValidDefinition(): void {
        $plan = PlanDefinition::fromArray([
            'name' => '  Sunday team  ',
            'tableId' => 9,
            'description' => '  Services  ',
            'icon' => 'calendar',
            'color' => '#A1b2C3',
        ]);

        self::assertSame('Sunday team', $plan->getName());
        self::assertSame(9, $plan->getTableId());
        self::assertSame('Services', $plan->jsonSerialize()['description']);
        self::assertSame('#a1b2c3', $plan->getColor());
    }

    public function testRejectsEmptyName(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Plan name must not be empty.');
        PlanDefinition::fromArray(['name' => '  ', 'tableId' => 9]);
    }

    public function testRejectsInvalidTableId(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tables table ID must be positive.');
        PlanDefinition::fromArray(['name' => 'Team', 'tableId' => 0]);
    }

    public function testRejectsInvalidColor(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Color must use #rrggbb format.');
        PlanDefinition::fromArray(['name' => 'Team', 'tableId' => 9, 'color' => 'red']);
    }
}
