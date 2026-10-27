<?php

declare(strict_types=1);

namespace OCP {
	interface IRequest {
		public function getParams(): array;
	}
}

namespace OCP\AppFramework {
	use OCP\IRequest;
	class Controller {
		public function __construct(string $appName, protected IRequest $request) {
		}
	}
}

namespace OCP\AppFramework\Http {
	class JSONResponse {
		public function __construct(private array $data, private int $status = 200) {
		}
		public function getData(): array { return $this->data; }
		public function getStatus(): int { return $this->status; }
	}
}

namespace OCP\AppFramework\Http\Attribute {
	#[\Attribute]
	class NoAdminRequired {
	}
}

namespace OCA\ServicePlanner\AppInfo {
	final class Application {
		public const APP_ID = 'serviceplanner';
	}
}

namespace OCA\ServicePlanner\Tests\Unit\Controller {
	use OCA\ServicePlanner\Controller\SetupController;
	use OCA\ServicePlanner\Domain\ConfigurationValidator;
	use OCA\ServicePlanner\Service\PlanAccess;
	use OCA\ServicePlanner\Service\PlanSetupService;
	use OCA\ServicePlanner\Tests\Unit\Support\InMemoryGroupDirectory;
	use OCA\ServicePlanner\Tests\Unit\Support\InMemoryPlanRepository;
	use OCA\ServicePlanner\Tests\Unit\Support\InMemoryTablesGateway;
	use OCA\ServicePlanner\Tests\Unit\Support\PlanFixtures;
	use OCP\IRequest;
	use PHPUnit\Framework\TestCase;

	require_once dirname(__DIR__) . '/Support/PlanFixtures.php';
	require_once dirname(__DIR__) . '/Support/InMemoryGroupDirectory.php';
	require_once dirname(__DIR__) . '/Support/InMemoryPlanRepository.php';
	require_once dirname(__DIR__) . '/Support/InMemoryTablesGateway.php';

	final class ControllerRequest implements IRequest {
		public function __construct(private array $params) {
		}
		public function getParams(): array { return $this->params; }
	}

	final class SetupControllerTest extends TestCase {
		private function environment(array $params): array {
			$repository = new InMemoryPlanRepository();
			$tables = new InMemoryTablesGateway([42 => ['metadata' => PlanFixtures::metadata(), 'canManage' => true]]);
			$groups = new InMemoryGroupDirectory(['alice' => ['planner-admins']], []);
			$service = new PlanSetupService($repository, $tables, $groups, new PlanAccess($groups, $tables), new ConfigurationValidator());
			return [new SetupController(new ControllerRequest($params), $service, 'alice'), $service];
		}

		public function testCreateDuplicateReturnsMachineReadableNameConflict(): void {
			$definition = PlanFixtures::definition();
			[$controller, $service] = $this->environment(['definition' => $definition]);
			$service->create($definition, 'alice');

			$response = $controller->createPlan();

			self::assertSame(409, $response->getStatus());
			self::assertSame(['error' => 'A plan with this name already exists.', 'code' => 'duplicate_name'], $response->getData());
		}

		public function testUpdateDuplicateReturnsMachineReadableNameConflict(): void {
			$first = PlanFixtures::definition();
			$second = PlanFixtures::definition();
			$second['name'] = 'Second team';
			$updated = $second;
			$updated['name'] = $first['name'];
			[$controller, $service] = $this->environment(['definition' => $updated, 'expectedRevision' => 1]);
			$service->create($first, 'alice');
			$created = $service->create($second, 'alice');

			$response = $controller->updatePlan((string)$created['id']);

			self::assertSame(409, $response->getStatus());
			self::assertSame(['error' => 'A plan with this name already exists.', 'code' => 'duplicate_name'], $response->getData());
		}

		public function testUpdateStaleRevisionReturnsDistinctConflictCode(): void {
			$definition = PlanFixtures::definition();
			[$controller, $service] = $this->environment(['definition' => $definition, 'expectedRevision' => 0]);
			$created = $service->create($definition, 'alice');

			$response = $controller->updatePlan((string)$created['id']);

			self::assertSame(409, $response->getStatus());
			self::assertSame(['error' => 'The plan changed in another session. Reload before saving.', 'code' => 'stale_revision'], $response->getData());
		}
	}
}
