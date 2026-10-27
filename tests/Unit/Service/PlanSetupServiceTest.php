<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\ServicePlanner\Domain\ConfigurationValidator;
use OCA\ServicePlanner\Repository\PlanRepository;
use OCA\ServicePlanner\Service\PlanAccess;
use OCA\ServicePlanner\Service\PlanSetupService;
use OCA\ServicePlanner\Service\SetupException;
use OCA\ServicePlanner\Service\Tables\TablesGateway;
use OCA\ServicePlanner\Tests\Unit\Support\InMemoryGroupDirectory;
use OCA\ServicePlanner\Tests\Unit\Support\InMemoryPlanRepository;
use OCA\ServicePlanner\Tests\Unit\Support\InMemoryTablesGateway;
use OCA\ServicePlanner\Tests\Unit\Support\PlanFixtures;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/PlanFixtures.php';
require_once dirname(__DIR__) . '/Support/InMemoryGroupDirectory.php';
require_once dirname(__DIR__) . '/Support/InMemoryPlanRepository.php';
require_once dirname(__DIR__) . '/Support/InMemoryTablesGateway.php';

final class PlanSetupServiceTest extends TestCase {
    private ?InMemoryTablesGateway $gateway = null;

    protected function tearDown(): void {
        if ($this->gateway !== null) {
            $allowed = ['metadata', 'previewRows', 'canManage', 'listTables', 'createServiceTable'];
            self::assertSame([], array_diff($this->gateway->calls, $allowed));
        }
    }

    public function testPreviewUsesOnlyRealConfiguredColumnsAndDoesNotPersist(): void {
        $repository = new InMemoryPlanRepository();
        $gateway = new InMemoryTablesGateway([42 => ['metadata' => PlanFixtures::metadata(), 'canManage' => true]]);
        $gateway->rows = [['id' => 900, 'cells' => [102 => 'Actual Tables value', 999 => 'not exposed']]];
        $service = $this->service($repository, $gateway, ['alice' => ['planner-admins']]);

        $preview = $service->preview(PlanFixtures::definition(), 'alice');

        self::assertSame([], $preview['issues']);
        self::assertSame('Actual Tables value', $preview['rows'][0]['cells'][102]);
        self::assertArrayNotHasKey(999, $preview['rows'][0]['cells']);
        self::assertSame([], $repository->all());
        self::assertSame(4, $preview['summary']['columnCount']);
        self::assertSame(3, $preview['summary']['enabledViewCount']);
        self::assertSame([
            'tableTitle' => 'Sunday services',
            'columnCount' => 4,
            'serviceCount' => 1,
            'managerGroupCount' => 1,
            'visibleGroupCount' => 2,
            'enabledViewCount' => 3,
            'defaultViewTitle' => 'Upcoming',
            'selfSignupServiceCount' => 1,
            'flowActionCount' => 3,
        ], $preview['summary']);
    }

    public function testSemanticSchemaRejectionIsStructured422(): void {
        $definition = PlanFixtures::definition();
        $definition['defaultViewId'] = '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d099';
        $service = $this->service(new InMemoryPlanRepository(), $this->gateway(), ['alice' => ['planner-admins']]);

        try {
            $service->validate($definition, 'alice');
            self::fail('Expected schema rejection.');
        } catch (SetupException $error) {
            self::assertSame(422, $error->getStatus());
            self::assertSame('Configuration is invalid.', $error->getMessage());
            self::assertSame('configuration.invalid', $error->getIssues()[0]['code']);
            self::assertSame('configuration', $error->getIssues()[0]['path']);
        }
    }

    public function testMissingAndTypeChangedColumnsReturnIssuesAndNoRows(): void {
        $metadata = PlanFixtures::metadata();
        unset($metadata['columns'][1]);
        $metadata['columns'][2]['type'] = 'number';
        $gateway = new InMemoryTablesGateway([42 => ['metadata' => $metadata, 'canManage' => true]]);
        $service = $this->service(new InMemoryPlanRepository(), $gateway, ['alice' => ['planner-admins']]);

        $preview = $service->preview(PlanFixtures::definition(), 'alice');

        self::assertSame([], $preview['rows']);
        self::assertContains('column.missing', array_column($preview['issues'], 'code'));
        self::assertContains('column.typeChanged', array_column($preview['issues'], 'code'));
        self::assertNotContains('previewRows', $gateway->calls);
    }

    public function testCreatePersistsNormalizedStableIdsAndRevision(): void {
        $repository = new InMemoryPlanRepository();
        $service = $this->service($repository, $this->gateway(), ['alice' => ['planner-admins']]);
        $definition = PlanFixtures::definition();
        $definition['name'] = '  Sunday team  ';
        $definition['columns'] = array_reverse($definition['columns']);
        $definition['services'][0]['id'] = strtoupper($definition['services'][0]['id']);

        $created = $service->create($definition, 'alice');

        self::assertSame('Sunday team', $created['name']);
        self::assertSame(104, $created['columns'][0]['columnId']);
        self::assertSame(0, $created['columns'][0]['position']);
        self::assertSame(strtolower($definition['services'][0]['id']), $created['services'][0]['id']);
        self::assertSame(1, $created['revision']);
        self::assertSame($created['createdAt'], $created['updatedAt']);
        self::assertSame([$created], $repository->all());
    }

    public function testCreateFailsWhenCreatorLacksSubmittedManagerGroup(): void {
        $repository = new InMemoryPlanRepository();
        $service = $this->service($repository, $this->gateway(), ['alice' => ['volunteers']]);

        try {
            $service->create(PlanFixtures::definition(), 'alice');
            self::fail('Expected denial.');
        } catch (SetupException $error) {
            self::assertSame(404, $error->getStatus());
            self::assertSame([], $repository->all());
        }
    }

    public function testUpdateRejectsStaleRevisionWithoutChangingOldRecord(): void {
        $repository = new InMemoryPlanRepository();
        $service = $this->service($repository, $this->gateway(), ['alice' => ['planner-admins']]);
        $created = $service->create(PlanFixtures::definition(), 'alice');
        $updated = PlanFixtures::definition();
        $updated['name'] = 'Changed elsewhere';

        try {
            $service->update($created['id'], 0, $updated, 'alice');
            self::fail('Expected conflict.');
        } catch (SetupException $error) {
            self::assertSame(409, $error->getStatus());
            self::assertSame('The plan changed in another session. Reload before saving.', $error->getMessage());
            self::assertSame($created, $repository->findById($created['id']));
        }
    }

    public function testUpdateCannotSwitchToTableUserCannotManage(): void {
        $repository = new InMemoryPlanRepository();
        $gateway = $this->gateway();
        $metadata = PlanFixtures::metadata();
        $metadata['id'] = 43;
        $gateway->setMetadata(43, $metadata);
        $service = $this->service($repository, $gateway, ['alice' => ['planner-admins']]);
        $created = $service->create(PlanFixtures::definition(), 'alice');
        $updated = PlanFixtures::definition();
        $updated['tableId'] = 43;

        try {
            $service->update($created['id'], 1, $updated, 'alice');
            self::fail('Expected denial.');
        } catch (SetupException $error) {
            self::assertSame(404, $error->getStatus());
            self::assertSame($created, $repository->findById($created['id']));
        }
    }

    public function testUpdateReplacesWholeDefinitionAndIncrementsRevision(): void {
        $repository = new InMemoryPlanRepository();
        $service = $this->service($repository, $this->gateway(), ['alice' => ['planner-admins']]);
        $created = $service->create(PlanFixtures::definition(), 'alice');
        $updated = PlanFixtures::definition();
        $updated['name'] = 'Updated team';
        $updated['description'] = 'Replacement';

        $result = $service->update($created['id'], 1, $updated, 'alice');

        self::assertSame(2, $result['revision']);
        self::assertSame('Updated team', $result['name']);
        self::assertSame('Replacement', $result['description']);
        self::assertSame($created['createdAt'], $result['createdAt']);
    }

    public function testDeleteChecksManagerAndOnlyDeletesConfiguration(): void {
        $repository = new InMemoryPlanRepository();
        $gateway = $this->gateway();
        $service = $this->service($repository, $gateway, ['alice' => ['planner-admins']]);
        $created = $service->create(PlanFixtures::definition(), 'alice');
        $gateway->calls = [];

        $service->delete($created['id'], 'alice');

        self::assertSame([], $repository->all());
        self::assertSame(['canManage'], $gateway->calls);
    }

    public function testGetReturnsCurrentStructureIssuesForExistingPlan(): void {
        $repository = new InMemoryPlanRepository();
        $gateway = $this->gateway();
        $service = $this->service($repository, $gateway, ['alice' => ['planner-admins']]);
        $created = $service->create(PlanFixtures::definition(), 'alice');
        $metadata = PlanFixtures::metadata();
        $metadata['columns'][0]['subtype'] = 'time';
        $gateway->setMetadata(42, $metadata);

        $result = $service->get($created['id'], 'alice');

        self::assertSame($created, $result['plan']);
        self::assertSame($metadata, $result['table']);
        self::assertSame('column.typeChanged', $result['issues'][0]['code']);
    }

    public function testGetReturnsIncompleteIssueWithoutNormalizingLegacyPlan(): void {
        $repository = new InMemoryPlanRepository();
        $legacy = $repository->add(['name' => 'Legacy', 'description' => '', 'icon' => 'calendar', 'color' => '#0082c9', 'tableId' => 42]);
        $service = $this->service($repository, $this->gateway(), []);

        $result = $service->get($legacy['id'], 'alice');

        self::assertSame($legacy, $result['plan']);
        self::assertSame('configuration.incomplete', $result['issues'][0]['code']);
        self::assertArrayNotHasKey('columns', $result['plan']);
    }

    public function testListRetainsOnlyPlansPassingCurrentManagerChecks(): void {
        $repository = new InMemoryPlanRepository();
        $allowed = $repository->add(PlanFixtures::definition());
        $denied = PlanFixtures::definition();
        $denied['name'] = 'Other';
        $denied['permissions']['managerGroups'] = ['other-group'];
        $repository->add($denied);
        $service = $this->service($repository, $this->gateway(), ['alice' => ['planner-admins']]);

        self::assertSame([$allowed], $service->list('alice'));
    }

    public function testListTablesOmitsColumnsAndSharesAndHandlesOnlyUnavailable(): void {
        $gateway = $this->gateway();
        $service = $this->service(new InMemoryPlanRepository(), $gateway, []);

        $result = $service->listTables('alice');

        self::assertTrue($result['tablesAvailable']);
        self::assertArrayNotHasKey('columns', $result['tables'][0]);
        self::assertArrayNotHasKey('shares', $result['tables'][0]);

        $gateway->listTablesError = new SetupException('Tables is not available.', 503);
        self::assertSame(['tables' => [], 'tablesAvailable' => false], $service->listTables('alice'));
        $gateway->listTablesError = new SetupException('Tables operation failed.', 500);
        $this->expectException(SetupException::class);
        $service->listTables('alice');
    }

    public function testCreateTableValidatesTemplateBeforeCallingTables(): void {
        $gateway = $this->gateway();
        $gateway->createdTable = PlanFixtures::metadata();
        $service = $this->service(new InMemoryPlanRepository(), $gateway, []);

        try {
            $service->createTable('Title', 'emoji', 'Description', 'other', 'alice');
            self::fail('Expected template rejection.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Unknown table template.', $error->getMessage());
            self::assertSame([], $gateway->calls);
        }
        self::assertSame(PlanFixtures::metadata(), $service->createTable('Title', 'emoji', 'Description', 'service-basic', 'alice'));
    }

    public function testTableAndGroupsExposeSelectorData(): void {
        $gateway = $this->gateway();
        $groups = new InMemoryGroupDirectory([], ['z-group' => 'Zulu', 'a-group' => 'Alpha']);
        $service = new PlanSetupService(new InMemoryPlanRepository(), $gateway, $groups, new PlanAccess($groups, $gateway), new ConfigurationValidator());
        $this->gateway = $gateway;

        self::assertSame(PlanFixtures::metadata(), $service->table(42, 'alice'));
        self::assertSame([
            ['id' => 'a-group', 'displayName' => 'Alpha'],
            ['id' => 'z-group', 'displayName' => 'Zulu'],
        ], $service->groups());
    }

    private function service(PlanRepository $repository, TablesGateway $gateway, array $groupsByUser): PlanSetupService {
        $groups = new InMemoryGroupDirectory($groupsByUser, []);
        if ($gateway instanceof InMemoryTablesGateway) {
            $this->gateway = $gateway;
        }
        return new PlanSetupService(
            $repository,
            $gateway,
            $groups,
            new PlanAccess($groups, $gateway),
            new ConfigurationValidator(),
        );
    }

    private function gateway(): InMemoryTablesGateway {
        return new InMemoryTablesGateway([42 => ['metadata' => PlanFixtures::metadata(), 'canManage' => true]]);
    }
}
