<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Domain;

use InvalidArgumentException;
use OCA\ServicePlanner\Domain\PlanConfiguration;
use OCA\ServicePlanner\Tests\Unit\Support\PlanFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/PlanFixtures.php';

final class PlanConfigurationTest extends TestCase {
    public function testValidFixtureRoundTripsExactly(): void {
        self::assertSame(
            PlanFixtures::definition(),
            PlanConfiguration::fromArray(PlanFixtures::definition())->jsonSerialize(),
        );
    }

    public function testPreservesStableIdsAndNormalizesOrderAndText(): void {
        $input = PlanFixtures::definition();
        $input['name'] = '  Sunday team  ';
        $input['color'] = '#A1B2C3';
        $input['columns'][0]['position'] = 99;

        $actual = PlanConfiguration::fromArray($input)->jsonSerialize();

        self::assertSame('Sunday team', $actual['name']);
        self::assertSame('#a1b2c3', $actual['color']);
        self::assertSame(0, $actual['columns'][0]['position']);
        self::assertSame($input['services'][0]['id'], $actual['services'][0]['id']);
        self::assertSame($input['defaultViewId'], $actual['defaultViewId']);
    }

    public function testNormalizesUuidsAndTrimmedRuleText(): void {
        $input = PlanFixtures::definition();
        $serviceId = strtoupper($input['services'][0]['id']);
        $input['services'][0]['id'] = $serviceId;
        $input['services'][0]['name'] = '  Music team  ';
        $input['services'][0]['notAssignedValues'] = ['  --  ', '–', '-'];
        $input['permissions']['visibleGroups'] = [' planner-admins ', ' volunteers '];
        $input['views'][0]['serviceIds'] = [$serviceId];
        $input['views'][2]['serviceIds'] = [$serviceId];
        $input['views'][3]['serviceIds'] = [$serviceId];

        $actual = PlanConfiguration::fromArray($input)->jsonSerialize();

        self::assertSame(strtolower($serviceId), $actual['services'][0]['id']);
        self::assertSame('Music team', $actual['services'][0]['name']);
        self::assertSame(['--', '–', '-'], $actual['services'][0]['notAssignedValues']);
        self::assertSame(['planner-admins', 'volunteers'], $actual['permissions']['visibleGroups']);
        self::assertSame(strtolower($serviceId), $actual['views'][0]['serviceIds'][0]);
    }

    public function testDiscardsUnknownTopLevelKeys(): void {
        $input = PlanFixtures::definition();
        $input['unexpected'] = ['not' => 'persisted'];

        $actual = PlanConfiguration::fromArray($input)->jsonSerialize();

        self::assertArrayNotHasKey('unexpected', $actual);
        self::assertSame(PlanFixtures::definition(), $actual);
    }

    #[DataProvider('supportedFilters')]
    public function testAcceptsEverySupportedFilterOperator(string $operator, int $columnId, string $value): void {
        $input = PlanFixtures::definition();
        $input['views'][3]['filters'][0] = [
            'id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d007',
            'columnId' => $columnId,
            'operator' => $operator,
            'value' => $value,
        ];

        $actual = PlanConfiguration::fromArray($input)->jsonSerialize();

        self::assertSame($operator, $actual['views'][3]['filters'][0]['operator']);
        self::assertSame($value, $actual['views'][3]['filters'][0]['value']);
    }

    public static function supportedFilters(): iterable {
        yield 'contains' => ['contains', 103, 'special'];
        yield 'equals' => ['equals', 103, 'special'];
        yield 'isEmpty' => ['isEmpty', 103, ''];
        yield 'isNotEmpty' => ['isNotEmpty', 103, ''];
        yield 'open' => ['open', 104, ''];
    }

    #[DataProvider('valueRequiringOperators')]
    public function testRejectsBlankValuesForContainsAndEquals(string $operator): void {
        $input = PlanFixtures::definition();
        $input['views'][3]['filters'][0]['operator'] = $operator;
        $input['views'][3]['filters'][0]['value'] = ' ';

        $this->assertRejected($input, 'views.3.filters.0.value');
    }

    public static function valueRequiringOperators(): iterable {
        yield 'contains' => ['contains'];
        yield 'equals' => ['equals'];
    }

    #[DataProvider('emptyValueOperators')]
    public function testRejectsValuesForEmptyAndOpenOperators(string $operator): void {
        $input = PlanFixtures::definition();
        $input['views'][0]['filters'][0]['operator'] = $operator;
        $input['views'][0]['filters'][0]['value'] = 'unexpected';

        $this->assertRejected($input, 'views.0.filters.0.value');
    }

    public static function emptyValueOperators(): iterable {
        yield 'isEmpty' => ['isEmpty'];
        yield 'isNotEmpty' => ['isNotEmpty'];
        yield 'open' => ['open'];
    }

    #[DataProvider('periodBoundaries')]
    public function testAcceptsPeriodBoundaries(int $view, array $period): void {
        $input = PlanFixtures::definition();
        $input['views'][$view]['period'] = $period;

        $actual = PlanConfiguration::fromArray($input)->jsonSerialize();

        self::assertSame($period, $actual['views'][$view]['period']);
    }

    public static function periodBoundaries(): iterable {
        yield 'one day' => [0, ['kind' => 'upcoming', 'days' => 1]];
        yield '3660 days' => [0, ['kind' => 'upcoming', 'days' => 3660]];
        yield 'year 2000' => [1, ['kind' => 'year', 'year' => 2000]];
        yield 'year 2100' => [1, ['kind' => 'year', 'year' => 2100]];
        yield 'current year' => [1, ['kind' => 'year', 'year' => 'current']];
    }

    #[DataProvider('invalidDefinitions')]
    public function testRejectsInvalidDefinitions(array $change, string $path): void {
        $input = PlanFixtures::definition();
        foreach ($change as $key => $value) {
            $input[$key] = $value;
        }
        $this->assertRejected($input, $path);
    }

    public static function invalidDefinitions(): iterable {
        yield 'schema' => [['schemaVersion' => 2], 'schemaVersion'];
        yield 'schema string' => [['schemaVersion' => '1'], 'schemaVersion'];
        yield 'groups' => [['permissions' => ['managerGroups' => [], 'visibleGroups' => []]], 'permissions.managerGroups'];
        yield 'views' => [['views' => []], 'views'];
        yield 'default view' => [['defaultViewId' => 'missing'], 'defaultViewId'];
        yield 'separators' => [['personNames' => ['separators' => ['|']]], 'personNames.separators'];
        yield 'numeric string table ID' => [['tableId' => '42'], 'tableId'];
        yield 'boolean table ID' => [['tableId' => true], 'tableId'];
        yield 'numeric name' => [['name' => 42], 'name'];
        yield 'boolean description' => [['description' => false], 'description'];
        yield 'array icon' => [['icon' => ['calendar']], 'icon'];
        yield 'null color' => [['color' => null], 'color'];
        yield 'non-list columns' => [['columns' => ['first' => []]], 'columns'];
        yield 'non-list services' => [['services' => ['first' => []]], 'services'];
        yield 'non-list views' => [['views' => ['first' => []]], 'views'];
        yield 'non-list flow actions' => [['flow' => ['actions' => ['first' => []]]], 'flow.actions'];
    }

    #[DataProvider('invalidNestedValues')]
    public function testRejectsInvalidNestedValues(array $path, mixed $value, string $errorPath): void {
        $input = PlanFixtures::definition();
        $this->setPath($input, $path, $value);

        $this->assertRejected($input, $errorPath);
    }

    public static function invalidNestedValues(): iterable {
        yield 'numeric string column ID' => [['columns', 0, 'columnId'], '101', 'columns.0.columnId'];
        yield 'zero column ID' => [['columns', 0, 'columnId'], 0, 'columns.0.columnId'];
        yield 'duplicate column ID' => [['columns', 1, 'columnId'], 101, 'columns.1.columnId'];
        yield 'missing date role' => [['columns', 0, 'role'], 'text', 'columns'];
        yield 'second date role' => [['columns', 1, 'role'], 'date', 'columns.1.role'];
        yield 'second title role' => [['columns', 2, 'role'], 'title', 'columns.2.role'];
        yield 'unknown column role' => [['columns', 0, 'role'], 'number', 'columns.0.role'];
        yield 'non-string column label' => [['columns', 0, 'displayLabel'], 10, 'columns.0.displayLabel'];
        yield 'blank column label' => [['columns', 0, 'displayLabel'], '  ', 'columns.0.displayLabel'];
        yield 'long column label' => [['columns', 0, 'displayLabel'], str_repeat('x', 191), 'columns.0.displayLabel'];
        yield 'string column position' => [['columns', 0, 'position'], '0', 'columns.0.position'];
        yield 'non-string service UUID' => [['services', 0, 'id'], 123, 'services.0.id'];
        yield 'malformed service UUID' => [['services', 0, 'id'], 'service-1', 'services.0.id'];
        yield 'blank service name' => [['services', 0, 'name'], ' ', 'services.0.name'];
        yield 'non-string service icon' => [['services', 0, 'icon'], false, 'services.0.icon'];
        yield 'non-list service columns' => [['services', 0, 'columnIds'], ['first' => 104], 'services.0.columnIds'];
        yield 'unknown service column' => [['services', 0, 'columnIds', 0], 999, 'services.0.columnIds.0'];
        yield 'non-person service column' => [['services', 0, 'columnIds', 0], 103, 'services.0.columnIds.0'];
        yield 'duplicate service columns' => [['services', 0, 'columnIds'], [104, 104], 'services.0.columnIds'];
        yield 'string signup flag' => [['services', 0, 'selfSignup', 'enabled'], '1', 'services.0.selfSignup.enabled'];
        yield 'leave without signup' => [['services', 0, 'selfSignup'], ['enabled' => false, 'leaveEnabled' => true], 'services.0.selfSignup.leaveEnabled'];
        yield 'non-list placeholders' => [['services', 0, 'notAssignedValues'], ['first' => '--'], 'services.0.notAssignedValues'];
        yield 'non-string placeholder' => [['services', 0, 'notAssignedValues', 0], 0, 'services.0.notAssignedValues.0'];
        yield 'blank placeholder' => [['services', 0, 'notAssignedValues', 0], ' ', 'services.0.notAssignedValues.0'];
        yield 'duplicate trimmed placeholders' => [['services', 0, 'notAssignedValues'], ['--', ' -- '], 'services.0.notAssignedValues'];
        yield 'non-list manager groups' => [['permissions', 'managerGroups'], ['first' => 'planner-admins'], 'permissions.managerGroups'];
        yield 'non-string group' => [['permissions', 'visibleGroups', 1], 5, 'permissions.visibleGroups.1'];
        yield 'blank group' => [['permissions', 'visibleGroups', 1], ' ', 'permissions.visibleGroups.1'];
        yield 'duplicate groups' => [['permissions', 'visibleGroups'], ['planner-admins', 'planner-admins'], 'permissions.visibleGroups'];
        yield 'manager not visible' => [['permissions', 'managerGroups'], ['other-group'], 'permissions.managerGroups.0'];
        yield 'empty visible groups' => [['permissions', 'visibleGroups'], [], 'permissions.visibleGroups'];
        yield 'non-string view UUID' => [['views', 0, 'id'], false, 'views.0.id'];
        yield 'unknown view type' => [['views', 0, 'type'], 'table', 'views.0.type'];
        yield 'blank view title' => [['views', 0, 'title'], '', 'views.0.title'];
        yield 'non-string view icon' => [['views', 0, 'icon'], 1, 'views.0.icon'];
        yield 'string enabled flag' => [['views', 0, 'enabled'], 'true', 'views.0.enabled'];
        yield 'string view position' => [['views', 0, 'position'], '0', 'views.0.position'];
        yield 'unknown view column' => [['views', 0, 'columnIds', 0], 999, 'views.0.columnIds.0'];
        yield 'duplicate view columns' => [['views', 0, 'columnIds'], [101, 101], 'views.0.columnIds'];
        yield 'unknown view service' => [['views', 0, 'serviceIds', 0], '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d999', 'views.0.serviceIds.0'];
        yield 'duplicate view services' => [['views', 0, 'serviceIds'], ['7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001', '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001'], 'views.0.serviceIds'];
        yield 'case-variant duplicate view services' => [['views', 0, 'serviceIds'], ['7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001', '7D1678A5-E28F-4B1F-B0F1-A5D7EF93D001'], 'views.0.serviceIds'];
        yield 'service view without service' => [['views', 3, 'serviceIds'], [], 'views.3.serviceIds'];
        yield 'unknown period kind' => [['views', 0, 'period', 'kind'], 'past', 'views.0.period.kind'];
        yield 'zero upcoming days' => [['views', 0, 'period', 'days'], 0, 'views.0.period.days'];
        yield 'excessive upcoming days' => [['views', 0, 'period', 'days'], 3661, 'views.0.period.days'];
        yield 'string upcoming days' => [['views', 0, 'period', 'days'], '90', 'views.0.period.days'];
        yield 'early year' => [['views', 1, 'period', 'year'], 1999, 'views.1.period.year'];
        yield 'late year' => [['views', 1, 'period', 'year'], 2101, 'views.1.period.year'];
        yield 'numeric string year' => [['views', 1, 'period', 'year'], '2027', 'views.1.period.year'];
        yield 'unknown sort direction' => [['views', 0, 'sort', 0, 'direction'], 'ascending', 'views.0.sort.0.direction'];
        yield 'sort column outside view' => [['views', 1, 'sort', 0, 'columnId'], 103, 'views.1.sort.0.columnId'];
        yield 'duplicate sort columns' => [['views', 0, 'sort'], [['columnId' => 101, 'direction' => 'asc'], ['columnId' => 101, 'direction' => 'desc']], 'views.0.sort'];
        yield 'filter column outside view' => [['views', 1, 'filters'], [['id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d012', 'columnId' => 103, 'operator' => 'isEmpty', 'value' => '']], 'views.1.filters.0.columnId'];
        yield 'open on text column' => [['views', 3, 'filters', 0], ['id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d007', 'columnId' => 103, 'operator' => 'open', 'value' => ''], 'views.3.filters.0.operator'];
        yield 'contains blank value' => [['views', 3, 'filters', 0, 'value'], '  ', 'views.3.filters.0.value'];
        yield 'contains non-string value' => [['views', 3, 'filters', 0, 'value'], 5, 'views.3.filters.0.value'];
        yield 'empty operator with value' => [['views', 3, 'filters', 1, 'value'], 'unexpected', 'views.3.filters.1.value'];
        yield 'default view disabled' => [['views', 0, 'enabled'], false, 'defaultViewId'];
        yield 'malformed flow UUID' => [['flow', 'actions', 0, 'id'], 'action-1', 'flow.actions.0.id'];
        yield 'unknown flow action' => [['flow', 'actions', 0, 'type'], 'deleteCell', 'flow.actions.0.type'];
        yield 'empty flow targets' => [['flow', 'actions', 0, 'columnIds'], [], 'flow.actions.0.columnIds'];
        yield 'unknown flow target' => [['flow', 'actions', 0, 'columnIds', 0], 999, 'flow.actions.0.columnIds.0'];
        yield 'duplicate flow targets' => [['flow', 'actions', 0, 'columnIds'], [104, 104], 'flow.actions.0.columnIds'];
        yield 'setCell targets date' => [['flow', 'actions', 2, 'columnIds'], [101], 'flow.actions.2.columnIds.0'];
    }

    #[DataProvider('unsupportedFilterOperators')]
    public function testRejectsEveryUnsupportedFilterOperator(string $operator): void {
        $input = PlanFixtures::definition();
        $input['views'][3]['filters'][0]['operator'] = $operator;

        $this->assertRejected($input, 'views.3.filters.0.operator');
    }

    public static function unsupportedFilterOperators(): iterable {
        foreach (['startsWith', 'endsWith', 'notEquals', 'regex', ''] as $operator) {
            yield $operator === '' ? 'empty' : $operator => [$operator];
        }
    }

    #[DataProvider('duplicateStableIdCollections')]
    public function testRejectsDuplicateStableIds(string $collection, string $path): void {
        $input = PlanFixtures::definition();
        if ($collection === 'services') {
            $input['services'][] = $input['services'][0];
        } elseif ($collection === 'views') {
            $input['views'][] = $input['views'][0];
        } elseif ($collection === 'filters') {
            $input['views'][0]['filters'][] = $input['views'][0]['filters'][0];
        } else {
            $input['flow']['actions'][] = $input['flow']['actions'][0];
        }

        $this->assertRejected($input, $path);
    }

    public static function duplicateStableIdCollections(): iterable {
        yield 'services' => ['services', 'services.1.id'];
        yield 'views' => ['views', 'views.4.id'];
        yield 'filters' => ['filters', 'views.0.filters.1.id'];
        yield 'flow actions' => ['flow', 'flow.actions.3.id'];
    }

    public function testRejectsPersonColumnAssignedToMultipleServices(): void {
        $input = PlanFixtures::definition();
        $second = $input['services'][0];
        $second['id'] = '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d012';
        $input['services'][] = $second;

        $this->assertRejected($input, 'services.1.columnIds.0');
    }

    public function testRequiresAtLeastOneEnabledView(): void {
        $input = PlanFixtures::definition();
        foreach ($input['views'] as &$view) {
            $view['enabled'] = false;
        }
        unset($view);

        $this->assertRejected($input, 'views');
    }

    public function testRejectsSignupFlowWhenSignupIsDisabled(): void {
        $input = PlanFixtures::definition();
        $input['services'][0]['selfSignup'] = ['enabled' => false, 'leaveEnabled' => false];

        $this->assertRejected($input, 'flow.actions.0.columnIds.0');
    }

    public function testRejectsLeaveFlowWhenLeaveIsDisabled(): void {
        $input = PlanFixtures::definition();
        $input['services'][0]['selfSignup']['leaveEnabled'] = false;

        $this->assertRejected($input, 'flow.actions.1.columnIds.0');
    }

    #[DataProvider('definitionsWithoutDateRole')]
    public function testReportsCollectionPathWhenNoDateRoleExists(array $input): void {
        try {
            PlanConfiguration::fromArray($input);
            self::fail('Expected configuration rejection.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('columns must contain exactly one date column.', $error->getMessage());
        }
    }

    public static function definitionsWithoutDateRole(): iterable {
        $outsideIndexZero = PlanFixtures::definition();
        $date = array_shift($outsideIndexZero['columns']);
        array_splice($outsideIndexZero['columns'], 1, 0, [$date]);
        $outsideIndexZero['columns'][1]['role'] = 'text';
        yield 'missing date outside index zero' => [$outsideIndexZero];

        $empty = PlanFixtures::definition();
        $empty['columns'] = [];
        yield 'empty columns' => [$empty];
    }

    private function assertRejected(array $input, string $path): void {
        try {
            PlanConfiguration::fromArray($input);
            self::fail('Expected configuration rejection.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString($path, $error->getMessage());
        }
    }

    private function setPath(array &$input, array $path, mixed $value): void {
        $target = &$input;
        foreach ($path as $key) {
            $target = &$target[$key];
        }
        $target = $value;
    }
}
