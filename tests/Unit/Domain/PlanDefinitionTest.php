<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Domain;

use InvalidArgumentException;
use OCA\ServicePlanner\Domain\PlanDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAcceptsNamesWithExactly190Characters(): void {
        foreach (['a', 'ü'] as $character) {
            $name = str_repeat($character, 190);
            $plan = PlanDefinition::fromArray(['name' => $name, 'tableId' => 9]);

            self::assertSame($name, $plan->getName());
        }
    }

    public function testNameKeyIsFixedLengthForExpandingUnicode(): void {
        $name = str_repeat('İ', 190);
        self::assertSame(380, mb_strlen(mb_strtolower($name)));

        $key = PlanDefinition::nameKey($name);

        self::assertSame(64, strlen($key));
        self::assertSame(hash('sha256', mb_strtolower($name)), $key);
    }

    public function testNameKeyMatchesCaseVariants(): void {
        self::assertSame(
            PlanDefinition::nameKey('Sunday TEAM'),
            PlanDefinition::nameKey('sunday team'),
        );
    }

    #[DataProvider('namesWith191Characters')]
    public function testRejectsNamesWith191Characters(string $name): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Plan name must not exceed 190 characters.');
        PlanDefinition::fromArray(['name' => $name, 'tableId' => 9]);
    }

    public static function namesWith191Characters(): array {
        return [
            'ASCII' => [str_repeat('a', 191)],
            'multibyte' => [str_repeat('ü', 191)],
        ];
    }

    #[DataProvider('malformedTextValues')]
    public function testRejectsMalformedTextValues(string $field, mixed $value, string $message): void {
        $input = ['name' => 'Team', 'tableId' => 9];
        $input[$field] = $value;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        PlanDefinition::fromArray($input);
    }

    public static function malformedTextValues(): array {
        return [
            'name array' => ['name', ['Team'], 'Plan name must be a string.'],
            'name object' => ['name', new \stdClass(), 'Plan name must be a string.'],
            'description array' => ['description', ['Services'], 'Description must be a string.'],
            'description object' => ['description', new \stdClass(), 'Description must be a string.'],
            'icon array' => ['icon', ['calendar'], 'Icon must be a string.'],
            'icon object' => ['icon', new \stdClass(), 'Icon must be a string.'],
        ];
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
