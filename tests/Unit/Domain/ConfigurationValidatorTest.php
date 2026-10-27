<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Domain;

use OCA\ServicePlanner\Domain\ConfigurationValidator;
use OCA\ServicePlanner\Domain\PlanConfiguration;
use OCA\ServicePlanner\Tests\Unit\Support\PlanFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/PlanFixtures.php';

final class ConfigurationValidatorTest extends TestCase {
    public function testValidCurrentStructureHasNoIssues(): void {
        $issues = $this->validate(PlanFixtures::metadata());

        self::assertSame([], $issues);
    }

    public function testReportsMissingAndTypeChangedColumnsAtEveryReference(): void {
        $definition = PlanFixtures::definition();
        $metadata = PlanFixtures::metadata();
        unset($metadata['columns'][3]);
        $metadata['columns'] = array_values($metadata['columns']);
        $metadata['columns'][0]['type'] = 'text';
        $metadata['columns'][0]['subtype'] = 'line';

        $issues = (new ConfigurationValidator())->validate(
            PlanConfiguration::fromArray($definition)->jsonSerialize(),
            $metadata,
        );
        $collectCodes = static fn (array $issues): array => array_column($issues, 'code');

        self::assertContains([
            'code' => 'column.missing',
            'path' => 'columns.3.columnId',
            'message' => 'Column 104 no longer exists in the Tables table.',
        ], $issues);
        self::assertContains([
            'code' => 'column.typeChanged',
            'path' => 'columns.0.columnId',
            'message' => 'Column 101 must remain datetime/date for role date.',
        ], $issues);
        self::assertContains('service.columnMissing', $collectCodes($issues));
        self::assertContains('view.columnMissing', $collectCodes($issues));
        self::assertContains('filter.columnMissing', $collectCodes($issues));
        self::assertContains('flow.columnMissing', $collectCodes($issues));
        self::assertContains([
            'code' => 'service.columnMissing',
            'path' => 'services.0.columnIds.0',
            'message' => 'Referenced column 104 no longer exists.',
        ], $issues);
        self::assertContains([
            'code' => 'view.columnMissing',
            'path' => 'views.0.columnIds.3',
            'message' => 'Referenced column 104 no longer exists.',
        ], $issues);
        self::assertContains([
            'code' => 'filter.columnMissing',
            'path' => 'views.0.filters.0.columnId',
            'message' => 'Referenced column 104 no longer exists.',
        ], $issues);
        self::assertContains([
            'code' => 'flow.columnMissing',
            'path' => 'flow.actions.0.columnIds.0',
            'message' => 'Referenced column 104 no longer exists.',
        ], $issues);
    }

    public function testReportsMissingSortAndFilterReferencesAtExactPaths(): void {
        $metadata = PlanFixtures::metadata();
        $metadata['columns'] = array_values(array_filter(
            $metadata['columns'],
            static fn (array $column): bool => in_array($column['id'], [101, 103], true) === false,
        ));

        $issues = $this->validate($metadata);

        self::assertContains([
            'code' => 'sort.columnMissing',
            'path' => 'views.0.sort.0.columnId',
            'message' => 'Referenced column 101 no longer exists.',
        ], $issues);
        self::assertContains([
            'code' => 'filter.columnMissing',
            'path' => 'views.3.filters.0.columnId',
            'message' => 'Referenced column 103 no longer exists.',
        ], $issues);
        self::assertContains([
            'code' => 'flow.columnMissing',
            'path' => 'flow.actions.2.columnIds.0',
            'message' => 'Referenced column 103 no longer exists.',
        ], $issues);
    }

    public function testRejectsMismatchedTableBeforeCheckingColumns(): void {
        $metadata = PlanFixtures::metadata();
        $metadata['id'] = 43;
        $metadata['columns'] = [];

        $issues = $this->validate($metadata);

        self::assertSame([[
            'code' => 'table.mismatch',
            'path' => 'tableId',
            'message' => 'The Tables table does not match the plan.',
        ]], $issues);
    }

    #[DataProvider('invalidDateTypes')]
    public function testRequiresDatetimeDateForDateColumn(string $type, string $subtype): void {
        $metadata = PlanFixtures::metadata();
        $metadata['columns'][0]['type'] = $type;
        $metadata['columns'][0]['subtype'] = $subtype;

        $issues = $this->validate($metadata);

        self::assertContains([
            'code' => 'column.typeChanged',
            'path' => 'columns.0.columnId',
            'message' => 'Column 101 must remain datetime/date for role date.',
        ], $issues);
    }

    public static function invalidDateTypes(): iterable {
        yield 'wrong type' => ['text', 'date'];
        yield 'wrong subtype' => ['datetime', 'datetime'];
        yield 'empty subtype' => ['datetime', ''];
    }

    #[DataProvider('textColumns')]
    public function testRequiresTextForTitleTextAndPersonColumns(int $index, int $id, string $role): void {
        $metadata = PlanFixtures::metadata();
        $metadata['columns'][$index]['type'] = 'number';
        $metadata['columns'][$index]['subtype'] = '';

        $issues = $this->validate($metadata);

        self::assertContains([
            'code' => 'column.typeChanged',
            'path' => "columns.$index.columnId",
            'message' => "Column $id must remain text for role $role.",
        ], $issues);
    }

    public static function textColumns(): iterable {
        yield 'title' => [1, 102, 'title'];
        yield 'text' => [2, 103, 'text'];
        yield 'person' => [3, 104, 'person'];
    }

    public function testTitleRenameAloneProducesNoIssueBecauseIdsAreAuthoritative(): void {
        $metadata = PlanFixtures::metadata();
        foreach ($metadata['columns'] as &$column) {
            $column['title'] = 'Renamed ' . $column['id'];
        }
        unset($column);

        self::assertSame([], $this->validate($metadata));
    }

    public function testSetCellDateRoleProducesFlowTypeIssue(): void {
        $configuration = PlanConfiguration::fromArray(PlanFixtures::definition())->jsonSerialize();
        $configuration['flow']['actions'][2]['columnIds'] = [101];

        $issues = (new ConfigurationValidator())->validate($configuration, PlanFixtures::metadata());

        self::assertContains([
            'code' => 'flow.columnTypeChanged',
            'path' => 'flow.actions.2.columnIds.0',
            'message' => 'Referenced column 101 no longer has the required role.',
        ], $issues);
    }

    public function testIssueMessagesDoNotContainColumnTitles(): void {
        $metadata = PlanFixtures::metadata();
        $metadata['columns'][0]['title'] = 'Sensitive date title';
        unset($metadata['columns'][3]);

        $issues = $this->validate($metadata);

        self::assertNotSame([], $issues);
        foreach ($issues as $issue) {
            self::assertStringNotContainsString('Sensitive date title', $issue['message']);
            self::assertStringNotContainsString('Music', $issue['message']);
        }
    }

    private function validate(array $metadata): array {
        return (new ConfigurationValidator())->validate(
            PlanConfiguration::fromArray(PlanFixtures::definition())->jsonSerialize(),
            $metadata,
        );
    }
}
