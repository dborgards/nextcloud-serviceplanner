# ServicePlanner Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a tested Nextcloud app foundation that stores multiple validated plan configurations and exposes them through OCC without duplicating Tables content.

**Architecture:** A dependency-free PHP value object validates plan metadata. A small service depends on a repository interface; a Nextcloud QBMapper implementation persists records, while unit tests use an in-memory repository. OCC commands provide an independently testable administrative interface before the browser setup is introduced.

**Tech Stack:** PHP 8.2, Nextcloud 35–36, Nextcloud Tables, PHPUnit 10, Composer, Symfony Console as supplied by Nextcloud.

## Global Constraints

- Work only in `next-cloud-dienste`; never modify `/Users/dietmar/Repositories/next-cloud-gd/sonntagum5`.
- App name: `ServicePlanner`; app ID and PHP namespace suffix: `serviceplanner` / `ServicePlanner`.
- Tables remains authoritative for table definitions, rows, cells and basic read/write permissions.
- Store only plan metadata and rules in ServicePlanner; reference Tables tables and columns by ID.
- Use PHP 8.2 and declare Nextcloud 35 through 36 in `appinfo/info.xml`.
- Every commit in this repository must use `2646931+dborgards@users.noreply.github.com`; configure it with `git config --local user.email` before the first commit.
- Do not push to GitHub unless the user explicitly requests it.
- Follow TDD: run each new test before its implementation and retain the observed failure in the task notes.
- Subsequent implementation plans cover, in order: setup wizard and Tables adapter; configured runtime views; writes and self-signup; Flow events and actions; release hardening and packaging.

---

## File Structure

- `appinfo/info.xml`: app identity, supported platform and OCC command registration.
- `appinfo/routes.php`: empty route registry until the HTTP plan is implemented.
- `lib/AppInfo/Application.php`: Nextcloud bootstrap class.
- `lib/Db/Plan.php`: database entity containing normalized plan JSON.
- `lib/Db/PlanMapper.php`: QBMapper persistence adapter.
- `lib/Migration/Version000001Date20261025000000.php`: initial schema.
- `lib/Domain/PlanDefinition.php`: validated, dependency-free plan value object.
- `lib/Repository/PlanRepository.php`: persistence boundary used by application services.
- `lib/Repository/DatabasePlanRepository.php`: entity/repository bridge.
- `lib/Service/PlanService.php`: plan creation and listing use cases.
- `lib/Command/CreatePlan.php`: OCC creation command.
- `lib/Command/ListPlans.php`: OCC listing command.
- `tests/Unit/`: dependency-free unit tests and in-memory test double.
- `tests/Integration/smoke.sh`: opt-in checks against a real Nextcloud installation.
- `composer.json` / `phpunit.xml`: development test tooling only; runtime uses Nextcloud autoloading.

### Task 1: App Skeleton and Validated Plan Definition

**Files:**
- Create: `appinfo/info.xml`
- Create: `appinfo/routes.php`
- Create: `lib/AppInfo/Application.php`
- Create: `lib/Domain/PlanDefinition.php`
- Create: `composer.json`
- Create: `phpunit.xml`
- Test: `tests/Unit/Domain/PlanDefinitionTest.php`

**Interfaces:**
- Consumes: no earlier project code.
- Produces: `PlanDefinition::fromArray(array $input): self`, `getName(): string`, `getTableId(): int`, `getIcon(): string`, `getColor(): string`, `jsonSerialize(): array`; later tasks persist its normalized array.

- [ ] **Step 1: Configure repository-local identity and install test tooling**

Run:

```bash
git config --local user.email "2646931+dborgards@users.noreply.github.com"
php -v
composer --version
```

Expected: PHP 8.2 or newer and Composer 2.x. Create `composer.json`:

```json
{
    "name": "dborgards/serviceplanner",
    "description": "Configurable service and event planners for Nextcloud Tables",
    "license": "AGPL-3.0-or-later",
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "config": {
        "sort-packages": true
    },
    "scripts": {
        "test": "phpunit"
    }
}
```

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>lib</directory>
        </include>
    </source>
</phpunit>
```

Create `tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\ServicePlanner\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
```

Run `composer install`. Expected: `vendor/bin/phpunit` exists.

- [ ] **Step 2: Write the failing value-object tests**

Create `tests/Unit/Domain/PlanDefinitionTest.php`:

```php
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
```

- [ ] **Step 3: Run tests to verify they fail**

Run `composer test -- --filter PlanDefinitionTest`.

Expected: FAIL because `OCA\ServicePlanner\Domain\PlanDefinition` cannot be autoloaded.

- [ ] **Step 4: Implement the minimal value object and app skeleton**

Create `lib/Domain/PlanDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Domain;

use InvalidArgumentException;
use JsonSerializable;

final class PlanDefinition implements JsonSerializable {
    private function __construct(
        private string $name,
        private int $tableId,
        private string $description,
        private string $icon,
        private string $color,
    ) {
    }

    public static function fromArray(array $input): self {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Plan name must not be empty.');
        }
        $tableId = filter_var($input['tableId'] ?? null, FILTER_VALIDATE_INT);
        if ($tableId === false || $tableId < 1) {
            throw new InvalidArgumentException('Tables table ID must be positive.');
        }
        $color = strtolower((string)($input['color'] ?? '#0082c9'));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new InvalidArgumentException('Color must use #rrggbb format.');
        }
        return new self(
            $name,
            $tableId,
            trim((string)($input['description'] ?? '')),
            trim((string)($input['icon'] ?? 'calendar')) ?: 'calendar',
            $color,
        );
    }

    public function getName(): string { return $this->name; }
    public function getTableId(): int { return $this->tableId; }
    public function getIcon(): string { return $this->icon; }
    public function getColor(): string { return $this->color; }

    public function jsonSerialize(): array {
        return [
            'name' => $this->name,
            'tableId' => $this->tableId,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }
}
```

Create `appinfo/info.xml`:

```xml
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
    <id>serviceplanner</id>
    <name>ServicePlanner</name>
    <summary>Configurable service and event planners for Nextcloud Tables</summary>
    <description>Stores independent planner configurations backed by Nextcloud Tables.</description>
    <version>0.1.0</version>
    <licence>agpl</licence>
    <author mail="2646931+dborgards@users.noreply.github.com">Dietmar Borgards</author>
    <namespace>ServicePlanner</namespace>
    <category>organization</category>
    <dependencies>
        <php min-version="8.2"/>
        <nextcloud min-version="35" max-version="36"/>
    </dependencies>
    <commands>
        <command>OCA\ServicePlanner\Command\CreatePlan</command>
        <command>OCA\ServicePlanner\Command\ListPlans</command>
    </commands>
</info>
```

Create `appinfo/routes.php`:

```php
<?php

declare(strict_types=1);

return ['routes' => []];
```

Create `lib/AppInfo/Application.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
    public const APP_ID = 'serviceplanner';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run `composer test`.

Expected: `OK (4 tests, 7 assertions)`.

- [ ] **Step 6: Commit the tested foundation**

```bash
git add appinfo lib composer.json composer.lock phpunit.xml tests
git commit -m "feat: add app skeleton and plan definition"
```

### Task 2: Repository Boundary and Plan Creation Service

**Files:**
- Create: `lib/Repository/PlanRepository.php`
- Create: `lib/Service/PlanService.php`
- Test: `tests/Unit/Service/PlanServiceTest.php`
- Test: `tests/Unit/Support/InMemoryPlanRepository.php`

**Interfaces:**
- Consumes: `PlanDefinition::fromArray()` and `jsonSerialize()` from Task 1.
- Produces: `PlanRepository::add(array $definition): array`, `all(): array`, `findByName(string $name): ?array`; `PlanService::create(array $input): array` and `list(): array` for persistence and OCC tasks.

- [ ] **Step 1: Write failing service tests**

Create `tests/Unit/Support/InMemoryPlanRepository.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Support;

use OCA\ServicePlanner\Repository\PlanRepository;

final class InMemoryPlanRepository implements PlanRepository {
    private array $plans = [];
    private int $nextId = 1;

    public function add(array $definition): array {
        $plan = ['id' => $this->nextId++, ...$definition];
        $this->plans[] = $plan;
        return $plan;
    }

    public function all(): array {
        return $this->plans;
    }

    public function findByName(string $name): ?array {
        foreach ($this->plans as $plan) {
            if (mb_strtolower($plan['name']) === mb_strtolower($name)) {
                return $plan;
            }
        }
        return null;
    }
}
```

Create `tests/Unit/Service/PlanServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Service;

use DomainException;
use OCA\ServicePlanner\Service\PlanService;
use OCA\ServicePlanner\Tests\Unit\Support\InMemoryPlanRepository;
use PHPUnit\Framework\TestCase;

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
```

- [ ] **Step 2: Run tests to verify they fail**

Run `composer test -- --filter PlanServiceTest`.

Expected: FAIL because `PlanRepository` is missing.

- [ ] **Step 3: Implement repository contract and service**

Create `lib/Repository/PlanRepository.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Repository;

interface PlanRepository {
    public function add(array $definition): array;
    public function all(): array;
    public function findByName(string $name): ?array;
}
```

Create `lib/Service/PlanService.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service;

use DomainException;
use OCA\ServicePlanner\Domain\PlanDefinition;
use OCA\ServicePlanner\Repository\PlanRepository;

final class PlanService {
    public function __construct(private PlanRepository $repository) {
    }

    public function create(array $input): array {
        $definition = PlanDefinition::fromArray($input);
        if ($this->repository->findByName($definition->getName()) !== null) {
            throw new DomainException('A plan with this name already exists.');
        }
        return $this->repository->add($definition->jsonSerialize());
    }

    public function list(): array {
        return $this->repository->all();
    }
}
```

- [ ] **Step 4: Run all tests**

Run `composer test`.

Expected: `OK (6 tests, 10 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Repository lib/Service tests/Unit
git commit -m "feat: add plan creation service"
```

### Task 3: Nextcloud Persistence

**Files:**
- Create: `lib/Migration/Version000001Date20261025000000.php`
- Create: `lib/Db/Plan.php`
- Create: `lib/Db/PlanMapper.php`
- Create: `lib/Repository/DatabasePlanRepository.php`
- Create: `tests/Integration/smoke.sh`

**Interfaces:**
- Consumes: `PlanRepository` from Task 2 and Nextcloud `QBMapper`.
- Produces: a container-resolvable `PlanRepository` using table `serviceplanner_plans`; OCC and future HTTP tasks consume only `PlanService`.

- [ ] **Step 1: Create the failing integration smoke check**

Create `tests/Integration/smoke.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

: "${NEXTCLOUD_ROOT:?Set NEXTCLOUD_ROOT to a disposable Nextcloud 35 or 36 checkout}"
: "${OCC_USER:?Set OCC_USER to the web-server user, or to empty for the current user}"
APP_DIR="$NEXTCLOUD_ROOT/apps/serviceplanner"

if [[ -e "$APP_DIR" ]]; then
    echo "Refusing to replace existing $APP_DIR" >&2
    exit 1
fi
ln -s "$(pwd)" "$APP_DIR"
trap 'rm "$APP_DIR"' EXIT

run_occ() {
    if [[ -n "$OCC_USER" ]]; then
        sudo -u "$OCC_USER" php "$NEXTCLOUD_ROOT/occ" "$@"
    else
        php "$NEXTCLOUD_ROOT/occ" "$@"
    fi
}

run_occ app:disable serviceplanner >/dev/null 2>&1 || true
run_occ app:enable serviceplanner
run_occ serviceplanner:create --name "Smoke plan" --table-id 1
run_occ serviceplanner:list | grep -F 'Smoke plan'
set +e
duplicate_output=$(run_occ serviceplanner:create --name "Smoke plan" --table-id 2 2>&1)
duplicate_status=$?
set -e
[[ $duplicate_status -ne 0 ]]
grep -F 'A plan with this name already exists.' <<<"$duplicate_output"
run_occ app:disable serviceplanner
```

Make it executable with `chmod +x tests/Integration/smoke.sh`.

- [ ] **Step 2: Run the smoke test to verify it fails**

Run `tests/Integration/smoke.sh` only with a disposable Nextcloud installation whose apps directory may receive the temporary symlink.

Expected: FAIL at `occ app:enable serviceplanner` because the migration and command classes do not exist.

- [ ] **Step 3: Implement schema and persistence adapter**

Create `lib/Migration/Version000001Date20261025000000.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000001Date20261025000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('serviceplanner_plans')) {
            return null;
        }
        $table = $schema->createTable('serviceplanner_plans');
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
        $table->addColumn('definition', 'text', ['notnull' => true]);
        $table->addColumn('created_at', 'bigint', ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('updated_at', 'bigint', ['notnull' => true, 'unsigned' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name'], 'serviceplanner_plan_name');
        return $schema;
    }
}
```

Create `lib/Db/Plan.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Db;

use OCP\AppFramework\Db\Entity;

final class Plan extends Entity {
    protected string $name = '';
    protected string $definition = '{}';
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function toArray(): array {
        $definition = json_decode($this->definition, true, 512, JSON_THROW_ON_ERROR);
        return ['id' => $this->getId(), ...$definition];
    }
}
```

Create `lib/Db/PlanMapper.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

final class PlanMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'serviceplanner_plans', Plan::class);
    }

    public function insertDefinition(array $definition, int $now): Plan {
        $plan = new Plan();
        $plan->setName($definition['name']);
        $plan->setDefinition(json_encode($definition, JSON_THROW_ON_ERROR));
        $plan->setCreatedAt($now);
        $plan->setUpdatedAt($now);
        return $this->insert($plan);
    }

    public function findAllPlans(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('name', 'ASC');
        return $this->findEntities($qb);
    }

}
```

Create `lib/Repository/DatabasePlanRepository.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Repository;

use OCA\ServicePlanner\Db\PlanMapper;

final class DatabasePlanRepository implements PlanRepository {
    public function __construct(private PlanMapper $mapper) {
    }

    public function add(array $definition): array {
        return $this->mapper->insertDefinition($definition, time())->toArray();
    }

    public function all(): array {
        return array_map(static fn ($plan) => $plan->toArray(), $this->mapper->findAllPlans());
    }

    public function findByName(string $name): ?array {
        foreach ($this->all() as $plan) {
            if (mb_strtolower($plan['name']) === mb_strtolower($name)) {
                return $plan;
            }
        }
        return null;
    }
}
```

Register the concrete repository in `Application::register()` by replacing its empty method with:

```php
    public function register(IRegistrationContext $context): void {
        $context->registerService(\OCA\ServicePlanner\Repository\PlanRepository::class, static fn () =>
            \OCP\Server::get(\OCA\ServicePlanner\Repository\DatabasePlanRepository::class)
        );
    }
```

- [ ] **Step 4: Re-run unit tests**

Run `composer test`.

Expected: all 6 tests still pass; persistence is tested separately because it requires Nextcloud.

- [ ] **Step 5: Commit persistence before adding its command consumer**

```bash
git add appinfo/info.xml lib/AppInfo/Application.php lib/Migration lib/Db lib/Repository/DatabasePlanRepository.php tests/Integration/smoke.sh
git commit -m "feat: persist plan definitions"
```

Expected at this stage: the smoke test still fails on missing OCC commands; Task 4 completes that vertical slice.

### Task 4: OCC Administration and End-to-End Verification

**Files:**
- Create: `lib/Command/CreatePlan.php`
- Create: `lib/Command/ListPlans.php`
- Modify: `tests/Integration/smoke.sh` only if the real Nextcloud command output differs from the expected strings; do not weaken its assertions.
- Test: `tests/Integration/smoke.sh`

**Interfaces:**
- Consumes: `PlanService::create(array $input): array` and `PlanService::list(): array` from Task 2.
- Produces: `occ serviceplanner:create --name <name> --table-id <positive-int> [--description <text>] [--icon <name>] [--color <#rrggbb>]` and `occ serviceplanner:list`; later setup and release tasks may reuse the same service.

- [ ] **Step 1: Implement the two OCC commands**

Create `lib/Command/CreatePlan.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Command;

use OCA\ServicePlanner\Service\PlanService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class CreatePlan extends Command {
    public function __construct(private PlanService $plans) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('serviceplanner:create')
            ->setDescription('Create a ServicePlanner plan')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Plan name')
            ->addOption('table-id', null, InputOption::VALUE_REQUIRED, 'Tables table ID')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Description', '')
            ->addOption('icon', null, InputOption::VALUE_REQUIRED, 'Icon name', 'calendar')
            ->addOption('color', null, InputOption::VALUE_REQUIRED, 'Hex color', '#0082c9');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        try {
            $plan = $this->plans->create([
                'name' => $input->getOption('name'),
                'tableId' => $input->getOption('table-id'),
                'description' => $input->getOption('description'),
                'icon' => $input->getOption('icon'),
                'color' => $input->getOption('color'),
            ]);
            $io->success('Created plan ' . $plan['id'] . ': ' . $plan['name']);
            return Command::SUCCESS;
        } catch (Throwable $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        }
    }
}
```

Create `lib/Command/ListPlans.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Command;

use OCA\ServicePlanner\Service\PlanService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListPlans extends Command {
    public function __construct(private PlanService $plans) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('serviceplanner:list')->setDescription('List ServicePlanner plans');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $rows = array_map(static fn (array $plan): array => [
            $plan['id'], $plan['name'], $plan['tableId'], $plan['icon'], $plan['color'],
        ], $this->plans->list());
        $output->writeln(['ID | Name | Tables ID | Icon | Color']);
        foreach ($rows as $row) {
            $output->writeln(implode(' | ', array_map('strval', $row)));
        }
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Run static syntax checks and unit tests**

Run:

```bash
find appinfo lib tests -name '*.php' -print0 | xargs -0 -n1 php -l
composer test
```

Expected: every file reports no syntax errors; all 6 unit tests pass.

- [ ] **Step 3: Run the real Nextcloud smoke test**

Use only a disposable Nextcloud 35 or 36 installation. Set `NEXTCLOUD_ROOT` and either `OCC_USER=''` or the appropriate web-server user, then run:

```bash
NEXTCLOUD_ROOT=/absolute/path/to/nextcloud OCC_USER='' tests/Integration/smoke.sh
```

Expected: app enables, migration creates `serviceplanner_plans`, create/list output contains `Smoke plan`, duplicate creation reports `A plan with this name already exists.`, app disables cleanly, and the exit trap removes only the temporary symlink.

- [ ] **Step 4: Verify repository identity and final diff**

Run:

```bash
git config --local --get user.email
git diff --check
git status --short
```

Expected: identity is exactly `2646931+dborgards@users.noreply.github.com`; `git diff --check` is empty; only intended plan files are staged in the next step.

- [ ] **Step 5: Commit the working vertical slice**

```bash
git add lib/Command tests/Integration/smoke.sh
git commit -m "feat: manage plans through occ"
```

Do not push without an explicit user request.
