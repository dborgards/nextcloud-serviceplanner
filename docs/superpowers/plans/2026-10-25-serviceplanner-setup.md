# ServicePlanner Setup Wizard and Tables Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver an independently usable setup wizard that creates and atomically edits complete plan configurations against real Nextcloud Tables metadata and preview data, without implementing runtime plan rendering, cell writes, self-signup execution, or Flow execution.

**Architecture:** A dependency-free configuration value object and structure validator define one normalized, ID-based schema. One lazy Tables adapter isolates all Tables 2.3.2 internals, while a setup service combines it with server-side group and Tables permission checks and single-statement optimistic persistence. A Vue 3 wizard keeps edits in a local draft, validates and previews through real APIs, and saves only after the whole draft is valid.

**Tech Stack:** PHP 8.2 application floor, Nextcloud 35–36, Nextcloud Tables 2.3.2 integration target on Nextcloud 35, PHPUnit 10, Vue 3, @nextcloud/vue 9, Vite 7, Node 22/npm 10, Vitest, @vue/test-utils, Playwright Chromium.

## Global Constraints

- Work only in the isolated `next-cloud-dienste/.worktrees/feature-serviceplanner-setup` worktree on `feature/serviceplanner-setup`; never modify `/Users/dietmar/Repositories/next-cloud-gd/sonntagum5`.
- App name: `ServicePlanner`; app ID and PHP namespace suffix: `serviceplanner` / `ServicePlanner`.
- Tables remains authoritative for table definitions, rows, cells, shares and basic read/write permissions.
- Store only plan metadata and rules in ServicePlanner; reference Tables tables and columns by numeric ID, never by title.
- Retain PHP 8.2 as the application floor and Nextcloud 35 through 36 in `appinfo/info.xml`; execute runtime integration on Nextcloud 35 + Tables 2.3.2 using the supported PHP version supplied by the disposable NC35 image, and record the actual version rather than claiming PHP 8.2 coverage.
- Match the reference frontend baseline: Vue 3, `@nextcloud/vue` 9, Vite 7, Node `^22.0.0`, npm `^10.0.0`; run frontend commands with Node 22 even if the host default is newer or older, and do not copy hard-coded domain rules.
- Every internal Tables call lives in `lib/Service/Tables/TablesAdapter.php`; other classes consume `TablesGateway` only.
- Configuration rules may only restrict Tables permissions. Setup management requires both Tables `canManageTableById` and membership in a configured manager group; creating a configuration requires Tables manage permission.
- API mutations keep Nextcloud CSRF protection enabled and require an authenticated user. Inaccessible plan IDs return 404 to avoid IDOR information disclosure.
- Self-signup/leave and Flow controls in this block only persist configuration and are labelled as such. No runtime write endpoints, Flow registration, runtime navigation, or configured runtime view rendering.
- Preview uses real Tables columns and up to ten real rows visible to the current user; it never uses fabricated rows and never copies those rows into ServicePlanner.
- Deleting a plan deletes only its ServicePlanner configuration, never the Tables table, columns, rows or shares.
- Keep the existing OCC commands, smoke test and foundation tests working; avoid unrelated refactoring.
- Follow TDD: run every new test before its implementation and record the observed failure in the task notes.
- Every commit in this repository must use `2646931+dborgards@users.noreply.github.com`; configure it with `git config --local user.email` before the first commit. Do not push.
- Nextcloud 36 runtime, databases other than the integration instance's database, PHP versions other than the one actually recorded during integration, and packaged-app installation are release hardening, not claims of this plan.

---

## Normalized Configuration Contract (schema version 1)

`PlanConfiguration::fromArray(array $input): self` returns `jsonSerialize(): array` in exactly this shape. Unknown top-level keys are discarded; known keys are strictly type-checked. Array order is canonical and `position` is normalized to zero-based array order. IDs are lower-case UUID strings matching `/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/`; the browser creates them with `crypto.randomUUID()` and the server preserves them on edit. Plan IDs remain the existing stable database integer IDs.

```json
{
  "schemaVersion": 1,
  "name": "Sunday team",
  "description": "Services and volunteers",
  "icon": "calendar",
  "color": "#0082c9",
  "tableId": 42,
  "personNames": { "separators": [",", ";", "\n"] },
  "columns": [
    { "columnId": 101, "role": "date", "displayLabel": "Date", "position": 0 },
    { "columnId": 102, "role": "title", "displayLabel": "Theme", "position": 1 },
    { "columnId": 103, "role": "text", "displayLabel": "Notes", "position": 2 },
    { "columnId": 104, "role": "person", "displayLabel": "Music", "position": 3 }
  ],
  "services": [
    {
      "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001",
      "name": "Music and sound",
      "icon": "music",
      "columnIds": [104],
      "notAssignedValues": ["--", "–", "-"],
      "selfSignup": { "enabled": true, "leaveEnabled": true }
    }
  ],
  "permissions": {
    "managerGroups": ["planner-admins"],
    "visibleGroups": ["planner-admins", "volunteers"]
  },
  "views": [
    {
      "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d002",
      "type": "cards",
      "title": "Upcoming",
      "icon": "calendar",
      "enabled": true,
      "position": 0,
      "columnIds": [101, 102, 103, 104],
      "serviceIds": ["7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001"],
      "period": { "kind": "upcoming", "days": 90 },
      "sort": [{ "columnId": 101, "direction": "asc" }],
      "filters": [
        { "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d003", "columnId": 104, "operator": "open", "value": "" }
      ]
    },
    {
      "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d004",
      "type": "year",
      "title": "Year grid",
      "icon": "grid",
      "enabled": true,
      "position": 1,
      "columnIds": [101, 102, 104],
      "serviceIds": [],
      "period": { "kind": "year", "year": "current" },
      "sort": [{ "columnId": 101, "direction": "asc" }],
      "filters": []
    },
    {
      "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d005",
      "type": "mine",
      "title": "My services",
      "icon": "account-check",
      "enabled": true,
      "position": 2,
      "columnIds": [101, 102, 104],
      "serviceIds": ["7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001"],
      "period": { "kind": "upcoming", "days": 365 },
      "sort": [{ "columnId": 101, "direction": "asc" }],
      "filters": []
    },
    {
      "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d006",
      "type": "service",
      "title": "Music team",
      "icon": "music",
      "enabled": false,
      "position": 3,
      "columnIds": [101, 102, 104],
      "serviceIds": ["7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001"],
      "period": { "kind": "year", "year": 2027 },
      "sort": [{ "columnId": 101, "direction": "desc" }],
      "filters": [
        { "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d007", "columnId": 103, "operator": "contains", "value": "special" },
        { "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d008", "columnId": 102, "operator": "isNotEmpty", "value": "" }
      ]
    }
  ],
  "defaultViewId": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d002",
  "flow": {
    "actions": [
      { "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d009", "type": "signup", "columnIds": [104] },
      { "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d010", "type": "leave", "columnIds": [104] },
      { "id": "7d1678a5-e28f-4b1f-b0f1-a5d7ef93d011", "type": "setCell", "columnIds": [103] }
    ]
  }
}
```

Schema invariants enforced before persistence:

- `schemaVersion === 1`; metadata uses the existing `PlanDefinition` rules; exactly one `date` column, at most one `title`, and at least one enabled view.
- Column roles are `date|title|person|text`; labels are trimmed non-empty strings of at most 190 characters; numeric column IDs are positive and unique.
- Separators are exactly `[",", ";", "\n"]` after normalization, preserving the reference's comma/semicolon/newline multi-person model.
- Service/view/filter/Flow-action IDs are unique within their own collections and stable across edits. Service names/icons and view titles/icons are non-empty trimmed strings; `position` follows array order.
- Service `columnIds` reference distinct configured `person` columns, and a person column belongs to at most one service. `leaveEnabled` requires `enabled`. `notAssignedValues` contains trimmed, unique, non-empty strings; these values are not open slots.
- Manager and visible group IDs are trimmed, unique, non-empty strings; every manager group is also a visible group. Both lists are non-empty.
- View types are `cards|year|mine|service`; every referenced column/service exists. `service` requires at least one service ID. `upcoming.days` is an integer 1–3660; `year.year` is `current` or an integer 2000–2100.
- Sort directions are `asc|desc`; sort columns must be in the view. Filter operators are `contains|equals|isEmpty|isNotEmpty|open`; filter columns must be in the view. `contains|equals` require a non-empty string value; the other operators require `value === ''`; `open` requires a person column.
- `defaultViewId` references an enabled view. Flow action types are `signup|leave|setCell`; target lists are non-empty and reference mapped columns. Signup/leave targets must belong to services with the corresponding rule enabled; `setCell` cannot target the date column.

## Tables DTO and HTTP Contracts

`TablesGateway` returns arrays, never Tables entities:

```php
interface TablesGateway {
    public function isAvailable(?string $userId): bool;
    public function listTables(string $userId): array;
    public function createServiceTable(string $title, string $emoji, string $description, string $userId): array;
    public function metadata(int $tableId, string $userId): array;
    public function previewRows(int $tableId, string $userId, array $columnIds, int $limit = 10): array;
    public function canManage(int $tableId, string $userId): bool;
    public function canUpdateRows(int $tableId, string $userId): bool;
}
```

`metadata()` shape: `{id,title,emoji,description,ownership,columns:[{id,title,type,subtype,mandatory,description}],permissions:{canManage,canUpdateRows,sharesVisible},shares:[{receiverType,receiver,receiverDisplayName,read,create,update,delete,manage}],tablesUrl}`. Shares are returned only to a Tables manager and exclude share IDs, tokens, passwords and timestamps. `previewRows()` returns `[{id,cells:{<columnId>:mixed}}]` and intersects requested IDs with actual table columns. Adapter `NotFound` and `Permission` failures become `SetupException` status 404; Tables unavailable becomes 503 with a safe message.

All endpoints are under `/apps/serviceplanner`; JSON success responses use HTTP 200 for reads/updates/deletes and 201 for creates. `GET /` returns only the wizard page. `GET`/`POST`/`PUT`/`DELETE` API routes use `#[NoAdminRequired]` but never `#[NoCSRFRequired]`; only the HTML page may use `NoCSRFRequired`.

| Method and path | Request | Success response and authorization |
|---|---|---|
| `GET /api/tables` | none | `{tables:[metadata without columns/shares],tablesAvailable:bool}`; current user's Tables-readable tables |
| `POST /api/tables` | `{title,emoji,description,template:"service-basic"}` | `{table:metadata}`; creates custom table plus date/title/text/person-template columns as current user |
| `GET /api/tables/{tableId}` | none | `{table:metadata}`; Tables read required, shares only with manage |
| `GET /api/groups` | none | `{groups:[{id,displayName}]}`; logged-in setup user, for group selectors |
| `GET /api/plans` | none | `{plans:[{id,revision,updatedAt,configuration fields}]}`; only plans passing current manager + Tables manage checks |
| `GET /api/plans/{id}` | none | `{plan,table,issues}`; manager required, otherwise 404; issues expose missing/type-changed columns without row content |
| `POST /api/setup/validate` | `{definition}` | `{issues:[{code,path,message}],normalized:configuration}`; Tables manage required, no persistence |
| `POST /api/setup/preview` | `{definition}` | `{issues,normalized,table,rows,summary}`; Tables manage required; `rows` is empty when issues exist; summary contains counts and configured labels/rules |
| `POST /api/plans` | `{definition}` | `{plan}`; normalized full definition required; Tables manage + configured manager-group membership required |
| `PUT /api/plans/{id}` | `{definition,expectedRevision:int}` | `{plan}`; existing manager and new-definition manager checks; one atomic compare-and-update statement |
| `DELETE /api/plans/{id}` | none | `{deleted:true}`; existing manager required; configuration only |

Validation failures return 422 `{error:"Configuration is invalid.",issues}`; stale updates return 409 `{error:"The plan changed in another session. Reload before saving."}`; malformed JSON/scalar types return 400; unauthenticated requests return 401; denied existing plan access returns 404; missing Tables returns 503. Save errors never clear the browser draft.

## File Structure

- `lib/Domain/PlanConfiguration.php`: strict normalization, UUID and cross-field validation for the complete schema.
- `lib/Domain/ConfigurationValidator.php`: validates normalized configuration against current Tables column IDs/types/subtypes and emits missing/type-changed issues.
- `lib/Service/Tables/TablesGateway.php`: only Tables boundary used by setup/application services.
- `lib/Service/Tables/TablesAdapter.php`: lazy Tables 2.3.2 service lookup, DTO mapping, template creation, permission/share inspection and limited real-row reads.
- `lib/Service/PlanAccess.php`: group intersection, manager checks and non-enumerating plan access.
- `lib/Service/PlanSetupService.php`: validate/preview/create/update/delete use cases and preview summaries.
- `lib/Service/SetupException.php`: safe HTTP status and structured validation issues.
- `lib/Controller/PageController.php`, `lib/Controller/SetupController.php`: HTML bootstrap and thin authenticated JSON API.
- `lib/Repository/PlanRepository.php`, `DatabasePlanRepository.php`, `lib/Db/PlanMapper.php`: add find and atomic compare-and-update/delete while retaining existing create/list methods.
- `appinfo/routes.php`, `lib/AppInfo/Application.php`: route and interface registrations.
- `package.json`, `package-lock.json`, `vite.config.js`, `vitest.config.js`, `src/main.js`, `templates/main.php`: browser build/test scaffold.
- `src/api.js`, `src/draft.js`, `src/App.vue`: API client, UUID/default draft helpers, plan list and wizard orchestration.
- `src/components/wizard/DataSourceStep.vue`, `ColumnsServicesStep.vue`, `PermissionsStep.vue`, `ViewsStep.vue`, `FlowPreviewStep.vue`, `SetupWizard.vue`: five setup steps plus navigation and save lifecycle.
- `src/components/PreviewTable.vue`: real metadata/row preview and configuration summary, not a runtime view.
- `tests/Unit/Domain/`, `tests/Unit/Service/`: normalization, structure, permissions and setup service tests; HTTP guards are covered by the real API/browser integration.
- `tests/Unit/Support/InMemoryPlanRepository.php`, `InMemoryTablesGateway.php`, `InMemoryGroupDirectory.php`, `PlanFixtures.php`: shared test doubles and valid schema fixture.
- `tests/Integration/tables_adapter.php`, `tests/Integration/setup.sh`: real NC35/Tables2.3.2 adapter and API/browser orchestration.
- `tests/Frontend/setup.spec.js`, `tests/Frontend/wizard.spec.js`, `tests/Frontend/views-preview.spec.js`: Vitest component/draft tests.
- `tests/E2E/setup.spec.js`, `playwright.config.js`: real Chromium setup/edit/delete flow against the integration instance.

### Task 1: Normalized Configuration and Current-Structure Validation

**Files:**
- Create: `lib/Domain/PlanConfiguration.php`
- Create: `lib/Domain/ConfigurationValidator.php`
- Create: `tests/Unit/Support/PlanFixtures.php`
- Test: `tests/Unit/Domain/PlanConfigurationTest.php`
- Test: `tests/Unit/Domain/ConfigurationValidatorTest.php`

**Interfaces:**
- Consumes: existing `PlanDefinition::fromArray(array $input): PlanDefinition` for metadata only.
- Produces: `PlanConfiguration::fromArray(array $input): self`, `jsonSerialize(): array`; `ConfigurationValidator::validate(array $configuration, array $metadata): array` returning `list<array{code:string,path:string,message:string}>`; `PlanFixtures::definition(): array`, `PlanFixtures::metadata(): array`.

- [ ] **Step 1: Add the exact valid fixture and failing normalization tests**

Create `tests/Unit/Support/PlanFixtures.php` with static `definition()` returning the JSON configuration above as PHP arrays and `metadata()` returning table 42 plus columns 101 `datetime/date`, 102 `text/line`, 103 `text/long`, and 104 `text/line`. In both domain test files add `require_once dirname(__DIR__) . '/Support/PlanFixtures.php';` after the namespace/imports, matching the existing foundation test convention:

```php
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

#[DataProvider('invalidDefinitions')]
public function testRejectsInvalidDefinitions(array $change, string $path): void {
    $input = PlanFixtures::definition();
    foreach ($change as $key => $value) {
        $input[$key] = $value;
    }
    try {
        PlanConfiguration::fromArray($input);
        self::fail('Expected configuration rejection.');
    } catch (InvalidArgumentException $error) {
        self::assertStringContainsString($path, $error->getMessage());
    }
}

public static function invalidDefinitions(): iterable {
    yield 'schema' => [['schemaVersion' => 2], 'schemaVersion'];
    yield 'groups' => [['permissions' => ['managerGroups' => [], 'visibleGroups' => []]], 'permissions.managerGroups'];
    yield 'views' => [['views' => []], 'views'];
    yield 'default view' => [['defaultViewId' => 'missing'], 'defaultViewId'];
    yield 'separators' => [['personNames' => ['separators' => ['|']]], 'personNames.separators'];
}
```

Add separate mutations that remove the date role, add a second date/title role, duplicate a column ID, use a malformed service UUID, reference an unknown view service/column, use every unsupported filter operator, set `open` on text 103, enable leave without signup, set `upcoming.days` to 0, and point Flow `setCell` at date 101. Each assertion must name the exact failing path; do not reduce these to a single generic invalid-input test.

- [ ] **Step 2: Run the normalization tests and observe RED**

Run: `composer test -- --filter PlanConfigurationTest`

Expected: FAIL because `OCA\ServicePlanner\Domain\PlanConfiguration` does not exist.

- [ ] **Step 3: Implement strict normalization and cross-field invariants**

Implement `PlanConfiguration` with a private array constructor and one public factory/serializer. Use these exact helpers so all collections follow the same rules rather than coercing malformed input:

```php
private static function arrayAt(array $input, string $key): array {
    if (!isset($input[$key]) || !is_array($input[$key]) || !array_is_list($input[$key])) {
        throw new InvalidArgumentException($key . ' must be a list.');
    }
    return $input[$key];
}

private static function id(mixed $value, string $path): string {
    if (!is_string($value)) {
        throw new InvalidArgumentException($path . ' must be a UUID.');
    }
    $value = strtolower($value);
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
        throw new InvalidArgumentException($path . ' must be a UUID.');
    }
    return $value;
}

private static function positiveId(mixed $value, string $path): int {
    if (!is_int($value) || $value < 1) {
        throw new InvalidArgumentException($path . ' must be a positive integer.');
    }
    return $value;
}

private static function assertUnique(array $values, string $path): void {
    if (count($values) !== count(array_unique($values, SORT_REGULAR))) {
        throw new InvalidArgumentException($path . ' must not contain duplicates.');
    }
}
```

Before calling `PlanDefinition`, require `name`, `description`, `icon`, and `color` to be strings and `tableId` to be an integer; use `positiveId()` for `tableId` as well as column IDs. Then normalize metadata through `PlanDefinition::fromArray($input)->jsonSerialize()` and add `schemaVersion` and all collections from the contract. Add failing data-provider cases for numeric-string IDs, non-string UUIDs and scalar metadata so no `TypeError` or coercion is possible. Reject booleans/integers supplied as strings, non-list collections, non-string labels/groups/filter values, unknown enum values, duplicate references, and every invariant listed above. Build column/service/view maps once and use them for all reference checks. Keep this class free of Nextcloud and Tables imports.

- [ ] **Step 4: Write failing current-structure tests**

Use a valid normalized fixture, then test this exact issue contract:

```php
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
    self::assertContains('service.columnMissing', collectCodes($issues));
    self::assertContains('view.columnMissing', collectCodes($issues));
    self::assertContains('flow.columnMissing', collectCodes($issues));
}
```

Implement `collectCodes()` in the test as `static fn (array $issues): array => array_column($issues, 'code')` rather than introducing a production helper. Add cases proving table ID mismatch is rejected, `datetime/date` is required for date, text types are required for title/person/text in this MVP, and a title rename alone produces no issue because IDs are authoritative.

- [ ] **Step 5: Run structure tests RED, implement validator, then run both GREEN**

Run: `composer test -- --filter ConfigurationValidatorTest` and expect missing-class failure. Implement the validator as an issue collector. The first pass maps actual/configured columns and reports role compatibility:

```php
final class ConfigurationValidator {
    public function validate(array $configuration, array $metadata): array {
        $issues = [];
        if ($configuration['tableId'] !== $metadata['id']) {
            return [['code' => 'table.mismatch', 'path' => 'tableId', 'message' => 'The Tables table does not match the plan.']];
        }
        $actual = array_column($metadata['columns'], null, 'id');
        $mapped = [];
        foreach ($configuration['columns'] as $index => $column) {
            $id = $column['columnId'];
            $mapped[$id] = $column;
            $current = $actual[$id] ?? null;
            if ($current === null) {
                $issues[] = ['code' => 'column.missing', 'path' => "columns.$index.columnId", 'message' => "Column $id no longer exists in the Tables table."];
                continue;
            }
            $valid = $column['role'] === 'date'
                ? $current['type'] === 'datetime' && $current['subtype'] === 'date'
                : $current['type'] === 'text';
            if (!$valid) {
                $expected = $column['role'] === 'date' ? 'datetime/date' : 'text';
                $issues[] = ['code' => 'column.typeChanged', 'path' => "columns.$index.columnId", 'message' => "Column $id must remain $expected for role {$column['role']}."];
            }
        }
        $this->validateReferences($configuration, $actual, $mapped, $issues);
        return $issues;
    }
}
```

Add `validateReferences()` with explicit loops over `services.*.columnIds`, `views.*.columnIds`, `views.*.sort`, `views.*.filters`, and `flow.actions.*.columnIds`. For each reference, call this helper with its exact path and required role:

```php
private function checkReference(array &$issues, int $id, string $path, string $code, array $actual, array $mapped, ?string $requiredRole = null): void {
    if (!isset($actual[$id])) {
        $issues[] = ['code' => "$code.columnMissing", 'path' => $path, 'message' => "Referenced column $id no longer exists."];
        return;
    }
    if (!isset($mapped[$id]) || ($requiredRole !== null && $mapped[$id]['role'] !== $requiredRole)) {
        $issues[] = ['code' => "$code.columnTypeChanged", 'path' => $path, 'message' => "Referenced column $id no longer has the required role."];
    }
}
```

Service references require `person` and code `service`; view column/sort references require no role and codes `view`/`sort`; filters use code `filter`, requiring `person` only for `open`; Flow uses code `flow`, requiring `person` for signup/leave and rejecting role `date` for setCell. Emit one issue per broken reference. Messages contain numeric IDs only, never column titles or row values.

Run: `composer test -- --filter 'PlanConfigurationTest|ConfigurationValidatorTest'`. Expected: all new domain tests pass.

- [ ] **Step 6: Run foundation regression and commit**

Run `composer test && git diff --check`. Expected: all foundation and new tests pass; no whitespace errors.

```bash
git config --local user.email "2646931+dborgards@users.noreply.github.com"
git add lib/Domain/PlanConfiguration.php lib/Domain/ConfigurationValidator.php tests/Unit/Domain tests/Unit/Support/PlanFixtures.php
git commit -m "feat: validate complete plan configuration"
```

### Task 2: Isolated Tables 2.3.2 Adapter and Real Integration

**Files:**
- Create: `lib/Service/SetupException.php`
- Create: `lib/Service/Tables/TablesGateway.php`
- Create: `lib/Service/Tables/TablesAdapter.php`
- Test: `tests/Integration/tables_adapter.php`

**Interfaces:**
- Consumes: the Tables DTO contract above; Tables 2.3.2 `TableService`, `ColumnService`, `RowService`, `ShareService`, `PermissionsService`, and `OCA\Tables\Dto\Column::createFromArray`.
- Produces: the exact `TablesGateway` methods above; `SetupException::getStatus(): int` and `getIssues(): array`; a repeatable real adapter check.

- [ ] **Step 1: Write the real adapter integration check before implementation**

Create `tests/Integration/tables_adapter.php` as a Nextcloud-bootstrapped executable. Require `NEXTCLOUD_ROOT` and `SERVICEPLANNER_TEST_USER`, bootstrap `$NEXTCLOUD_ROOT/lib/base.php`, set the current `IUserSession` user from `IUserManager`, and register the repository's `tests/bootstrap.php` autoloader. Define `check(bool $condition, string $message): void` to throw `RuntimeException($message)` when false, then execute this sequence:

```php
$adapter = Server::get(TablesAdapter::class);
$title = 'ServicePlanner adapter ' . bin2hex(random_bytes(4));
$table = $adapter->createServiceTable($title, '📋', 'adapter integration', $uid);
check($table['id'] > 0, 'created numeric table ID');
check(count($table['columns']) === 4, 'date, title, notes and team columns');
check($table['columns'][0]['type'] === 'datetime', 'date type');
check($table['columns'][0]['subtype'] === 'date', 'date subtype');
check($table['permissions']['canManage'] === true, 'creator manages table');
check($table['permissions']['canUpdateRows'] === true, 'creator updates rows');
check($table['tablesUrl'] === '/apps/tables/#/table/' . $table['id'], 'deep link');

$listed = array_column($adapter->listTables($uid), null, 'id');
check(isset($listed[$table['id']]), 'existing table selection');
$columnIds = array_column($table['columns'], 'id');
$row = Server::get(RowService::class)->create($table['id'], null, [
    ['columnId' => $columnIds[0], 'value' => '2027-01-10'],
    ['columnId' => $columnIds[1], 'value' => 'Real preview title'],
    ['columnId' => $columnIds[3], 'value' => 'Ada, Grace'],
], $uid);
$preview = $adapter->previewRows($table['id'], $uid, [$columnIds[1], $columnIds[3]], 10);
check(count($preview) === 1, 'one real row');
check($preview[0]['cells'][$columnIds[1]] === 'Real preview title', 'real title value');
check(!isset($preview[0]['cells'][$columnIds[0]]), 'unrequested column omitted');

Server::get(ShareService::class)->create(new ShareCreate(
    $table['id'], 'table', 'serviceplanner-adapter-group', 'group',
    true, false, false, false, false, 0,
));
$withShares = $adapter->metadata($table['id'], $uid);
check($withShares['permissions']['sharesVisible'] === true, 'manager share inspection');
check($withShares['shares'][0]['receiver'] === 'serviceplanner-adapter-group', 'group share');
check(!array_key_exists('token', $withShares['shares'][0]), 'no token leak');
check(!array_key_exists('password', $withShares['shares'][0]), 'no password leak');
```

Before the sequence, create the test group through `IGroupManager` if absent. At the end, disable Tables with `IAppManager->disableApp('tables')`, assert `isAvailable($uid) === false` and that `metadata()` throws status 503, then re-enable Tables in `finally`. The integration script creates fixture rows through Tables only; ServicePlanner never persists them.

- [ ] **Step 2: Run integration RED on the pinned instance**

On a disposable Nextcloud 35 instance using its supported image PHP runtime, with Tables 2.3.2 enabled, run `php -v` to record the actual version, then run:

```bash
NEXTCLOUD_ROOT=/absolute/path/to/nextcloud35 SERVICEPLANNER_TEST_USER=admin php tests/Integration/tables_adapter.php
```

Expected: FAIL at autoloading `TablesAdapter`; record the missing-class failure. Do not substitute mocks for this integration run.

- [ ] **Step 3: Add safe exception and gateway contracts**

Create `SetupException` with safe status/issues and no raw Tables exception messages:

```php
final class SetupException extends Exception {
    public function __construct(string $message, private int $status = 400, private array $issues = [], ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
    public function getStatus(): int { return $this->status; }
    public function getIssues(): array { return $this->issues; }
}
```

Create `TablesGateway` verbatim from the contract. `TablesAdapter` receives `IAppManager` and `IUserManager`; `isAvailable($userId)` resolves the user and calls `isEnabledForUser('tables', $user)`. Resolve Tables services with `Server::get()` inside private methods only after that check succeeds. This avoids container failures when Tables is absent and retains per-user app availability.

- [ ] **Step 4: Implement table listing, metadata and permission DTO mapping**

Use the verified calls exactly: `TableService::findAll($userId, true, false, false)`, `find($tableId, true, $userId)`, `PermissionsService::canManageTableById($tableId, $userId)`, and `canUpdateRowsByTableId($tableId, $userId)`. Map columns from `findAllByTable($tableId, $userId, $table)` with explicit integer casts. Only when `canManage` is true, map `ShareService::findAll('table', $tableId, $userId)` as follows:

```php
$shares = array_map(static fn ($share): array => [
    'receiverType' => $share->getReceiverType(),
    'receiver' => $share->getReceiver(),
    'receiverDisplayName' => $share->getReceiverDisplayName(),
    'read' => $share->getPermissionRead(),
    'create' => $share->getPermissionCreate(),
    'update' => $share->getPermissionUpdate(),
    'delete' => $share->getPermissionDelete(),
    'manage' => $share->getPermissionManage(),
], $shareService->findAll('table', $tableId, $userId));
```

The central private mapper returns exactly this shape:

```php
private function tableDto($table, array $columns, string $userId): array {
    $id = (int)$table->getId();
    $canManage = $this->canManage($id, $userId);
    return [
        'id' => $id,
        'title' => $table->getTitle(),
        'emoji' => $table->getEmoji() ?? '',
        'description' => $table->getDescription(),
        'ownership' => $table->getOwnership() ?? '',
        'columns' => $columns,
        'permissions' => [
            'canManage' => $canManage,
            'canUpdateRows' => $this->canUpdateRows($id, $userId),
            'sharesVisible' => $canManage,
        ],
        'shares' => $canManage ? $this->shares($id, $userId) : [],
        'tablesUrl' => '/apps/tables/#/table/' . $id,
    ];
}
```

`listTables()` uses the same mapper but replaces `columns` and `shares` with `[]` and `sharesVisible` with `false`; the UI reloads full metadata after selection. Catch Tables `NotFoundError|PermissionError` and throw `new SetupException('Tables table is not available.', 404, [], $error)`. Catch other `Throwable` only at the public adapter boundary, log no content, and return status 500 `Tables operation failed.`; never expose raw messages.

- [ ] **Step 5: Implement service template creation and limited preview reads**

`createServiceTable()` calls `create($title, 'custom', $emoji, $description, $userId)` and then creates exactly these DTOs in order:

```php
$definitions = [
    ['title' => 'Date', 'type' => 'datetime', 'subtype' => 'date', 'mandatory' => true, 'description' => 'Service date'],
    ['title' => 'Title', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'description' => 'Service title'],
    ['title' => 'Notes', 'type' => 'text', 'subtype' => 'long', 'mandatory' => false, 'description' => 'Additional information'],
    ['title' => 'Team', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'description' => 'Comma, semicolon or newline separated display names'],
];
foreach ($definitions as $definition) {
    $this->columnService()->create($userId, (int)$table->getId(), null, ColumnDto::createFromArray($definition));
}
return $this->metadata((int)$table->getId(), $userId);
```

Do not catch-and-delete a partially created table. If a column fails, throw status 500 with message `Table <id> was created, but its template is incomplete; select it to inspect the structure.` so no data is destroyed and the user can recover through existing-table selection.

`previewRows()` calls `RowService::findAllByTable($tableId, $userId, min(10, max(1, $limit)), 0)`, intersects requested IDs with the actual metadata IDs, and maps only those cells:

```php
$wanted = array_fill_keys(array_intersect($columnIds, array_column($metadata['columns'], 'id')), true);
$rows = [];
foreach ($entities as $entity) {
    $cells = [];
    foreach ($entity->getData() ?? [] as $cell) {
        $id = (int)$cell['columnId'];
        if (isset($wanted[$id])) {
            $cells[$id] = $cell['value'];
        }
    }
    $rows[] = ['id' => (int)$entity->getId(), 'cells' => $cells];
}
```

- [ ] **Step 6: Run adapter integration and all unit tests GREEN**

Re-run the command from Step 2, then `composer test`. Expected: adapter script prints one success line, Tables is re-enabled, all existing unit tests remain green. Also run `NEXTCLOUD_ROOT=/absolute/path/to/nextcloud35 OCC_USER='' tests/Integration/smoke.sh`; expected existing smoke test remains green on NC35.

- [ ] **Step 7: Commit the isolated adapter**

```bash
git add lib/Service/SetupException.php lib/Service/Tables tests/Integration/tables_adapter.php
git commit -m "feat: add tested Tables setup adapter"
```

### Task 3: Authorized Setup API, Preview and Atomic Configuration Persistence

**Files:**
- Create: `lib/Service/Groups/GroupDirectory.php`
- Create: `lib/Service/Groups/NextcloudGroupDirectory.php`
- Create: `lib/Service/PlanAccess.php`
- Create: `lib/Service/PlanSetupService.php`
- Create: `lib/Controller/PageController.php`
- Create: `lib/Controller/SetupController.php`
- Create: `templates/main.php`
- Create: `lib/Migration/Version000002Date20261025000001.php`
- Modify: `lib/Repository/PlanRepository.php`
- Modify: `lib/Repository/DatabasePlanRepository.php`
- Modify: `lib/Db/Plan.php`
- Modify: `lib/Db/PlanMapper.php`
- Modify: `lib/AppInfo/Application.php`
- Modify: `appinfo/routes.php`
- Test: `tests/Unit/Support/InMemoryGroupDirectory.php`
- Test: `tests/Unit/Support/InMemoryTablesGateway.php`
- Test: `tests/Unit/Service/PlanAccessTest.php`
- Test: `tests/Unit/Service/PlanSetupServiceTest.php`
- Test: `tests/Unit/Support/InMemoryPlanRepository.php`

**Interfaces:**
- Consumes: Task 1 configuration/validator and Task 2 gateway/exception; existing repository create/list behavior remains compatible with OCC.
- Produces: `GroupDirectory::{groupsForUser(string):array,listGroups():array}`; `PlanAccess::{assertTableManager(int,string):void,assertPlanManager(array,string):void}`; `PlanSetupService::{list,get,validate,preview,create,update,delete,listTables,createTable,table,groups}`; HTTP contract above.

- [ ] **Step 1: Write failing permission and no-rights-expansion tests**

Create an in-memory group directory whose constructor receives `array $groupsByUser` and `array $allGroups`; `groupsForUser()` returns only that user's IDs. Create an in-memory gateway seeded with metadata and two booleans per table. Test `PlanAccess` directly:

```php
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
```

Add tests that a matching manager with Tables manage succeeds, visible-group membership without manager-group membership fails, and a legacy definition with no `permissions` key is manageable only by a Tables manager so existing OCC-created plans can be adopted. The legacy exception applies only to reading/updating/deleting that legacy record; every saved new definition must contain valid manager rules.

- [ ] **Step 2: Run permission tests RED and implement the narrow group boundary**

Run `composer test -- --filter PlanAccessTest`; expected missing `PlanAccess` failure. Implement:

```php
final class PlanAccess {
    public function __construct(private GroupDirectory $groups, private TablesGateway $tables) {}
    public function assertTableManager(int $tableId, string $userId): void {
        if (!$this->tables->canManage($tableId, $userId)) {
            throw new SetupException('Tables table is not available for setup.', 404);
        }
    }
    public function assertPlanManager(array $plan, string $userId): void {
        $legacy = !isset($plan['permissions']['managerGroups']);
        $member = $legacy || array_intersect($plan['permissions']['managerGroups'], $this->groups->groupsForUser($userId)) !== [];
        if (!$member || !$this->tables->canManage((int)$plan['tableId'], $userId)) {
            throw new SetupException('Plan is not available.', 404);
        }
    }
}
```

`NextcloudGroupDirectory` maps `IGroupManager->getUserGroupIds($user)` and `search('')`; throw 404 rather than 403 for inaccessible existing plans. `listGroups()` returns sorted `{id,displayName}` DTOs and is only a selector; it never grants access.

- [ ] **Step 3: Extend the repository contract with failing atomic-update service tests**

Add these exact methods to `PlanRepository`: `findById(int $id): ?array`, `update(int $id, int $expectedRevision, array $definition): array`, `delete(int $id): void`. Extend the in-memory repository with timestamps and `revision`, starting at 1; `update()` throws `new SetupException('The plan changed in another session. Reload before saving.', 409)` when `revision !== expectedRevision` and otherwise replaces the entire definition and increments revision in one operation.

Write `PlanSetupServiceTest` using a valid fixture and a gateway whose metadata can be mutated. Add `require_once dirname(__DIR__) . '/Support/PlanFixtures.php';` and equivalent requires for all three in-memory support classes, matching the existing test bootstrap convention. Cover:

```php
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
}
```

Define the test helper with the production constructor so doubles have no hidden dependencies:

```php
private function service(PlanRepository $repository, TablesGateway $gateway, array $groupsByUser): PlanSetupService {
    $groups = new InMemoryGroupDirectory($groupsByUser, []);
    return new PlanSetupService(
        $repository,
        $gateway,
        $groups,
        new PlanAccess($groups, $gateway),
        new ConfigurationValidator(),
    );
}
```

Add tests that semantic schema rejection returns `SetupException` status 422 with a `configuration.invalid` issue rather than a generic 400, missing/type-changed columns return issues and no rows, create persists normalized stable IDs, create fails when the creator lacks the submitted manager group, update rejects stale `expectedRevision` without changing the old record, update cannot switch to a table the user cannot manage, delete calls no Tables delete method, and `get()` returns current structure issues for an existing plan. Verify a gateway call log contains only `metadata`, `previewRows`, `canManage` and list/create calls, never row writes or table deletion.

- [ ] **Step 4: Run service tests RED, then implement setup use cases**

Run `composer test -- --filter PlanSetupServiceTest`; expected missing `PlanSetupService` failure. Implement the constructor `__construct(private PlanRepository $repository, private TablesGateway $tables, private GroupDirectory $groups, private PlanAccess $access, private ConfigurationValidator $validator)`. `list()` filters `repository->all()` by calling `assertPlanManager` and retaining only plans that do not throw; `get()` uses `findById` or returns the same 404. Implement one private normalization path used by validate/preview/create/update:

```php
private function checked(array $input, string $userId): array {
    try {
        $normalized = PlanConfiguration::fromArray($input)->jsonSerialize();
    } catch (InvalidArgumentException $error) {
        throw new SetupException('Configuration is invalid.', 422, [[
            'code' => 'configuration.invalid',
            'path' => 'configuration',
            'message' => $error->getMessage(),
        ]], $error);
    }
    $this->access->assertTableManager($normalized['tableId'], $userId);
    $metadata = $this->tables->metadata($normalized['tableId'], $userId);
    $issues = $this->validator->validate($normalized, $metadata);
    return [$normalized, $metadata, $issues];
}

private function assertSaveAllowed(array $normalized, array $issues, string $userId): void {
    $member = array_intersect($normalized['permissions']['managerGroups'], $this->groups->groupsForUser($userId));
    if ($member === []) {
        throw new SetupException('Plan is not available.', 404);
    }
    if ($issues !== []) {
        throw new SetupException('Configuration is invalid.', 422, $issues);
    }
}
```

`preview()` additionally fetches `previewRows(tableId,userId,array_column(columns,'columnId'),10)` only when issues are empty and returns `summary` with `tableTitle`, `columnCount`, `serviceCount`, `managerGroupCount`, `visibleGroupCount`, `enabledViewCount`, `defaultViewTitle`, `selfSignupServiceCount`, and `flowActionCount`. `create()` and `update()` call `assertSaveAllowed` before repository mutation. `update()` loads by ID, checks the existing plan manager before inspecting the submitted definition, checks the new table/manager rules, then calls atomic update. `get()` never normalizes an incomplete legacy record; it returns raw definition plus metadata and a `configuration.incomplete` issue when `PlanConfiguration::fromArray` fails. `delete()` checks manager then calls only repository `delete`. `listTables()` catches only the adapter's 503 and returns `{tables:[],tablesAvailable:false}`; other failures remain errors. `createTable()` rejects any `template !== 'service-basic'` with `InvalidArgumentException('Unknown table template.')` before calling Tables.

- [ ] **Step 5: Implement single-statement compare-and-update persistence**

Add `revision` to `Plan` with default 1 and expose `id`, `revision`, `createdAt`, `updatedAt` plus definition fields in `toArray()`. Create `Version000002Date20261025000001`; when `serviceplanner_plans` exists and lacks `revision`, add `integer` with `notnull:true`, `unsigned:true`, `default:1`, and return the schema. Set revision 1 explicitly in `insertDefinition()`. Implement `PlanMapper::updateDefinition()` as one query so concurrent editors cannot overwrite each other, even within the same second:

```php
public function updateDefinition(int $id, int $expectedRevision, array $definition, int $now): ?Plan {
    $qb = $this->db->getQueryBuilder();
    $qb->update($this->getTableName())
        ->set('name', $qb->createNamedParameter($definition['name']))
        ->set('name_key', $qb->createNamedParameter(PlanDefinition::nameKey($definition['name'])))
        ->set('definition', $qb->createNamedParameter(json_encode($definition, JSON_THROW_ON_ERROR)))
        ->set('updated_at', $qb->createNamedParameter($now))
        ->set('revision', $qb->createFunction('revision + 1'))
        ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
        ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision)));
    if ($qb->executeStatement() !== 1) {
        return null;
    }
    return $this->findById($id);
}
```

Add `findById()` with `setMaxResults(1)`, and repository `update()` converts `null` to the exact 409 contract. `delete()` removes by plan ID only after service authorization. Database unique-name violations retain the existing safe `DomainException` mapping. The NC35 smoke/integration run must apply both migrations and verify existing OCC create/list behavior remains unchanged.

- [ ] **Step 6: Add routes, DI and thin controllers with CSRF/auth boundaries**

Retain the existing repository registration and add `$context->registerService(TablesGateway::class, static fn () => Server::get(TablesAdapter::class));` plus the equivalent `GroupDirectory`/`NextcloudGroupDirectory` registration. Replace `appinfo/routes.php` with:

```php
<?php
return ['routes' => [
    ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'setup#tables', 'url' => '/api/tables', 'verb' => 'GET'],
    ['name' => 'setup#createTable', 'url' => '/api/tables', 'verb' => 'POST'],
    ['name' => 'setup#table', 'url' => '/api/tables/{tableId}', 'verb' => 'GET', 'requirements' => ['tableId' => '\\d+']],
    ['name' => 'setup#groups', 'url' => '/api/groups', 'verb' => 'GET'],
    ['name' => 'setup#plans', 'url' => '/api/plans', 'verb' => 'GET'],
    ['name' => 'setup#createPlan', 'url' => '/api/plans', 'verb' => 'POST'],
    ['name' => 'setup#plan', 'url' => '/api/plans/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
    ['name' => 'setup#updatePlan', 'url' => '/api/plans/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
    ['name' => 'setup#deletePlan', 'url' => '/api/plans/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
    ['name' => 'setup#validate', 'url' => '/api/setup/validate', 'verb' => 'POST'],
    ['name' => 'setup#preview', 'url' => '/api/setup/preview', 'verb' => 'POST'],
]];
```

The route controller names are the exact `SetupController` public methods; each API method has `#[NoAdminRequired]`.

`SetupController` receives `IRequest`, `PlanSetupService`, and `?string $userId`. A private runner returns 401 `Authentication required.` for null/empty user, maps `InvalidArgumentException` to 400, `DomainException` to 409 with its safe duplicate-name message, and `SetupException` to its status plus `issues` only when non-empty. Do not add a catch-all that leaks exception messages. Before delegation, require `definition` to be an array and `expectedRevision` to be an integer (not a numeric string, float, or boolean); return 400 for malformed JSON, missing values, or wrong scalar/container types. Only table and plan route IDs are cast after their `\\d+` route requirement. `update()` passes the validated definition and exact integer revision. Task 6 explicitly asserts scalar definitions and `expectedRevision: 1.5` return 400 without mutation.

`PageController::index()` follows the reference pattern: `#[NoAdminRequired]`, `#[NoCSRFRequired]`, `#[OpenAPI(OpenAPI::SCOPE_IGNORE)]`, loads `serviceplanner-main` script/style and returns `templates/main.php` containing only `<div id="serviceplanner"></div>`. The JSON API has no `NoCSRFRequired` attribute, so `@nextcloud/axios` supplies the request token.

- [ ] **Step 7: Run backend regression and syntax checks**

Run:

```bash
composer test
find appinfo lib tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all foundation/new tests pass, all files pass syntax. Real HTTP authorization/CSRF and atomic updates are exercised in Task 6; no NC36 or extra database claim is made.

- [ ] **Step 8: Commit the authorized setup backend**

```bash
git add appinfo/routes.php lib templates/main.php tests/Unit
git commit -m "feat: add authorized atomic setup API"
```

### Task 4: Vue Setup Shell, Draft Lifecycle, Data/Columns/Services/Permissions

**Files:**
- Create: `package.json`, `package-lock.json`
- Create: `vite.config.js`, `vitest.config.js`
- Create: `src/main.js`, `src/api.js`, `src/draft.js`, `src/App.vue`
- Create: `src/components/wizard/SetupWizard.vue`
- Create: `src/components/wizard/DataSourceStep.vue`
- Create: `src/components/wizard/ColumnsServicesStep.vue`
- Create: `src/components/wizard/PermissionsStep.vue`
- Test: `tests/Frontend/setup.spec.js`
- Test: `tests/Frontend/wizard.spec.js`

**Interfaces:**
- Consumes: Task 3 HTTP endpoints and DTOs; reference-only Vite/Axios patterns.
- Produces: `newId():string`, `draftFromTable(metadata, previous?):object`, `draftFromPlan(plan,metadata):object`; API functions `listPlans`, `listTables`, `createTable`, `getTable`, `listGroups`, `getPlan`, `validateDefinition`, `previewDefinition`, `createPlan`, `updatePlan`, `deletePlan`; wizard props `initialPlan:object|null`, `initialDraft:object|null`, `expectedRevision:int|null`, and event `saved`.

- [ ] **Step 1: Add the pinned frontend toolchain and failing draft test**

Create `package.json`:

```json
{
  "name": "serviceplanner",
  "version": "0.1.0",
  "private": true,
  "license": "AGPL-3.0-or-later",
  "type": "module",
  "scripts": {
    "build": "vite --mode production build",
    "dev": "vite --mode development build",
    "watch": "vite --mode development build --watch",
    "test": "vitest run",
    "test:watch": "vitest"
  },
  "dependencies": {
    "@nextcloud/axios": "^2.5.1",
    "@nextcloud/dialogs": "^7.4.0",
    "@nextcloud/initial-state": "^2.2.0",
    "@nextcloud/router": "^3.0.1",
    "@nextcloud/vue": "^9.0.0",
    "vue": "^3.5.13",
    "vue-material-design-icons": "^5.3.1"
  },
  "devDependencies": {
    "@nextcloud/vite-config": "^2.3.5",
    "@vitejs/plugin-vue": "^6.0.1",
    "@vue/test-utils": "^2.4.6",
    "jsdom": "^26.1.0",
    "vite": "^7.3.6",
    "vitest": "^3.2.4"
  },
  "engines": { "node": "^22.0.0", "npm": "^10.0.0" }
}
```

Use Node 22 and run `npm install`. Create `vite.config.js` exactly with `createAppConfig({main:'src/main.js'}, {inlineCSS:false,minify:true})`. Create `vitest.config.js` using `vue()` and `test:{environment:'jsdom'}`. `src/main.js` mounts `App` at `#serviceplanner` and imports `@nextcloud/dialogs/style.css`.

Write `tests/Frontend/setup.spec.js` with metadata from `PlanFixtures` represented in JS and tests:

```js
import { describe, expect, it } from 'vitest'
import { draftFromPlan, draftFromTable, newId } from '../../src/draft.js'

describe('setup draft', () => {
  it('maps actual column IDs and keeps them stable across an edit', () => {
    const first = draftFromTable(metadata)
    const plan = { id: 9, revision: 1, updatedAt: 100, ...first, name: 'Edited', services: [{ ...first.services[0], id: newId() }] }
    const edited = draftFromPlan(plan, metadata)
    expect(edited.columns.map((column) => column.columnId)).toEqual([101, 102, 103, 104])
    expect(edited.services[0].id).toBe(plan.services[0].id)
    expect(edited.defaultViewId).toBe(first.defaultViewId)
    expect(edited.tableId).toBe(42)
  })

  it('creates an editable draft for a legacy OCC plan without inventing groups', () => {
    const edited = draftFromPlan({ id: 9, name: 'Legacy', tableId: 42, icon: 'calendar', color: '#0082c9', description: '' }, metadata)
    expect(edited.permissions).toEqual({ managerGroups: [], visibleGroups: [] })
    expect(edited.columns[0].role).toBe('date')
    expect(edited.views.map((view) => view.type)).toEqual(['cards', 'year', 'mine'])
  })
})
```

Run `npm test -- tests/Frontend/setup.spec.js`; expected FAIL because `src/draft.js` is absent.

- [ ] **Step 2: Implement UUID/default/migration draft helpers**

`newId()` returns `crypto.randomUUID()`. `draftFromTable()` identifies the first `datetime/date` column as date; the first remaining `text/line` as optional title; all other supported text columns as `text` initially. It never infers person roles from titles. Create one service named `Services` containing no columns and three enabled views (`cards`, `year`, `mine`) with fresh stable UUIDs, real mapped column IDs, ascending date sort, no filters, periods 90 days/current year/365 days, and cards as default. Manager/visible groups default to empty and Flow actions to empty. When `previous` exists, preserve plan metadata and permissions while resetting table-specific mappings only after explicit table change confirmation.

`draftFromPlan()` returns a deep clone of a complete valid stored definition, preserving every UUID and column ID. For a legacy/incomplete plan, start with `draftFromTable(metadata)` and overlay only scalar `name,description,icon,color,tableId`; the wizard displays the server `configuration.incomplete` issue and requires the user to complete all rules. Use `structuredClone`, not JSON stringify, so newline separators survive.

Implement `src/api.js` with `generateUrl('/apps/serviceplanner' + path)`, `@nextcloud/axios`, and one function per contract. `updatePlan(id,definition,expectedRevision)` sends `{definition,expectedRevision}`; `errorMessage(error)` returns only `error.response.data.error` or a generic localized fallback. Validation issues come from `error.response.data.issues`, not from fake client success.

- [ ] **Step 3: Write failing component tests for source, mappings and rights**

In `tests/Frontend/wizard.spec.js`, mount components with real draft/metadata and stub only Nextcloud visual components that do not affect behavior. Include these concrete tests:

```js
it('creates a real template through the emitted command', async () => {
  const wrapper = mount(DataSourceStep, { props: { draft: draftFromTable(metadata), tables: [], tablesAvailable: true } })
  await wrapper.get('[data-test="new-table-title"]').setValue('Real table')
  await wrapper.get('[data-test="create-table"]').trigger('click')
  expect(wrapper.emitted('create-table')[0][0]).toEqual({ title: 'Real table', emoji: '📋', description: '', template: 'service-basic' })
})

it('does not offer person role for an unsupported column type', () => {
  const changed = structuredClone(metadata)
  changed.columns[3] = { ...changed.columns[3], type: 'number', subtype: '' }
  const wrapper = mount(ColumnsServicesStep, { props: { draft: draftFromTable(changed), metadata: changed, issues: [] } })
  const select = wrapper.get('[data-test="role-104"]')
  expect(select.find('option[value="person"]').exists()).toBe(false)
})

it('shows actual shares and a Tables deep link without claiming rights expansion', () => {
  const shared = { ...metadata, permissions: { canManage: true, canUpdateRows: false, sharesVisible: true }, shares: [{ receiverType: 'group', receiver: 'volunteers', receiverDisplayName: 'Volunteers', read: true, create: false, update: false, delete: false, manage: false }], tablesUrl: '/apps/tables/#/table/42' }
  const wrapper = mount(PermissionsStep, { props: { draft: draftFromTable(metadata), metadata: shared, groups: [{ id: 'volunteers', displayName: 'Volunteers' }] } })
  expect(wrapper.text()).toContain('Volunteers')
  expect(wrapper.text()).toContain('read only')
  expect(wrapper.get('[data-test="tables-link"]').attributes('href')).toBe('/apps/tables/#/table/42')
  expect(wrapper.text()).toContain('only restrict Tables permissions')
})
```

Add a test that editing a display label does not mutate the Tables title, one that `leaveEnabled` becomes false when signup is disabled, and one that `notAssignedValues` remains service-specific. Run `npm test -- tests/Frontend/wizard.spec.js`; expected missing-component failures.

- [ ] **Step 4: Implement the first three wizard steps with real events and controls**

`DataSourceStep` binds plan name/description/icon/color, lists actual Tables tables, disables choices where `canManage` is false with an explanation, emits `select-table` with numeric ID, and emits the exact `create-table` payload above. While Tables is unavailable it shows the 503-safe empty state and no create/select controls. Existing-table selection calls `getTable`; creation calls `createTable`; both replace draft mappings from returned real metadata. Show adapter errors without clearing the draft.

`ColumnsServicesStep` renders actual column ID/title/type/subtype, label and position controls. Role options are `date` only for `datetime/date`, and `title/person/text` only for `text`; unsupported types have no role mapping and display an explanatory row. Add/remove service uses `newId()`; service checkboxes accept only configured person columns, icon/name are editable, placeholders default to `['--','–','-']`, and self-signup/leave switches only edit `service.selfSignup`. Label this area `Rules saved for the runtime block`; provide no join/leave button.

`PermissionsStep` renders real group checkboxes for manager/visible lists, checks a manager selection in visible groups automatically, and displays Tables owner/capabilities/shares. Each share row shows receiver, localized permission names including `read only` where appropriate, and the actual deep link using `generateUrl(metadata.tablesUrl)` with `target="_blank" rel="noopener"`. Add a live capability matrix with one row per selected group and columns for plan visibility, configuration management, and each self-signup/leave-enabled service. Derive it from the current draft without exposing unselected user/group data, and state that these are prospective ServicePlanner capabilities for members of those groups, still bounded by Tables access. State that ServicePlanner visibility and self-signup can only restrict Tables permissions; effective read access still requires a Tables share. Add a component assertion that selecting a manager group and enabling signup updates this matrix. Do not offer share mutation.

- [ ] **Step 5: Add plan list and three-step wizard orchestration**

`App.vue` loads `listPlans()` and shows only configuration management: plan name, table ID, `Edit configuration`, `New plan`, and `Delete configuration` with a confirmation explicitly stating Tables data is retained. There is no runtime-open button. Implement hash routing on mount and `hashchange`: `#/plans` shows the list and `#/plan/(\d+)/edit` loads that numeric plan ID through `getPlan`; successful save/delete returns to `#/plans`. Thus the edit direct link survives plan and view renames.

`SetupWizard` owns `draft`, `metadata`, `issues`, `step`, `busy`, and `error`; initializes from `getPlan` for edit or `listTables/listGroups` for new. For this task render the three completed steps and a final disabled `Continue` control labelled `Views configuration required` rather than a dead clickable action. Task 5 replaces it with working Views/Flow steps and Save. Preserve draft on all failures; only a successful create/update emits `saved` and returns to the list. Use stable test selectors `new-plan`, `plan-name`, `new-table-title`, `create-table`, `table-id`, `go-step-0` through `go-step-4`, `edit-plan-{id}`, `delete-plan-{id}`, `preview`, `preview-table`, and `save-plan`; dynamic editors use the ID-based selectors shown in component tests.

- [ ] **Step 6: Run component tests, production build and backend regression**

Run:

```bash
npm test
npm run build
composer test
```

Expected: all draft/component tests pass; Vite emits `js/serviceplanner-main.js` and `css/serviceplanner-main.css` (or the exact paths printed by `createAppConfig`, which must match `PageController`); all PHP tests stay green. Fix asset names from actual Vite output rather than guessing.

- [ ] **Step 7: Commit the tested wizard foundation**

```bash
git add package.json package-lock.json vite.config.js vitest.config.js src templates/main.php tests/Frontend
git commit -m "feat: add setup draft and core wizard steps"
```

### Task 5: Views, Filters, Flow Allowlist, Real Preview and Draft Save/Edit

**Files:**
- Create: `src/components/wizard/ViewsStep.vue`
- Create: `src/components/wizard/FlowPreviewStep.vue`
- Create: `src/components/PreviewTable.vue`
- Modify: `src/components/wizard/SetupWizard.vue`
- Modify: `src/App.vue`
- Test: `tests/Frontend/views-preview.spec.js`

**Interfaces:**
- Consumes: draft helpers/API from Task 4 and validate/preview/create/update contracts from Task 3.
- Produces: complete five-step setup wizard; `PreviewTable` props `{table:object,rows:array,normalized:object,summary:object}`; no runtime view component or write/Flow API.

- [ ] **Step 1: Write failing view/filter/Flow configuration tests**

Mount `ViewsStep` with `draftFromTable(metadata)` after assigning column 104 to `person` and the default service. Add concrete tests for all four types and exact configuration mutations:

```js
it('adds a service view with stable ID, open filter and default selection', async () => {
  const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
  await wrapper.get('[data-test="add-service-view"]').trigger('click')
  const view = draft.views.at(-1)
  expect(view.type).toBe('service')
  expect(view.id).toMatch(/^[0-9a-f-]{36}$/)
  await wrapper.get(`[data-test="default-${view.id}"]`).setValue()
  await wrapper.get(`[data-test="add-filter-${view.id}"]`).trigger('click')
  const filter = view.filters[0]
  await wrapper.get(`[data-test="filter-column-${filter.id}"]`).setValue(104)
  await wrapper.get(`[data-test="filter-operator-${filter.id}"]`).setValue('open')
  expect(filter).toMatchObject({ columnId: 104, operator: 'open', value: '' })
  expect(draft.defaultViewId).toBe(view.id)
})
```

Add tests that `contains`/`equals` retain an entered value while `isEmpty`, `isNotEmpty`, and `open` clear it; disabling the default view selects another enabled view; year accepts `current` or 2000–2100; upcoming accepts 1–3660; reorder changes array order and normalized positions; service/column selectors use IDs after a title rename. Cover all five operators rather than testing only `open`.

Mount `FlowPreviewStep` and verify adding `signup`, `leave`, and `setCell` creates independent UUIDs and only permitted target options: signup requires service signup, leave requires service leave, setCell excludes date. Assert the explanatory text says these are stored allowlist entries and no execute action is rendered.

- [ ] **Step 2: Run view tests RED and implement exact editors**

Run `npm test -- tests/Frontend/views-preview.spec.js`; expected missing `ViewsStep`/`FlowPreviewStep` failure. Implement typed add functions so no control creates an incomplete implicit object:

```js
function addView(type) {
  const view = {
    id: newId(), type, title: titles[type], icon: icons[type], enabled: true,
    position: props.draft.views.length,
    columnIds: props.draft.columns.map((column) => column.columnId),
    serviceIds: type === 'service' ? props.draft.services.slice(0, 1).map((service) => service.id) : [],
    period: type === 'year' || type === 'service' ? { kind: 'year', year: 'current' } : { kind: 'upcoming', days: 90 },
    sort: [{ columnId: dateColumnId.value, direction: 'asc' }], filters: [],
  }
  props.draft.views.push(view)
  if (!enabledDefault.value) props.draft.defaultViewId = view.id
}

function addFilter(view) {
  view.filters.push({ id: newId(), columnId: view.columnIds[0], operator: 'isNotEmpty', value: '' })
}
```

Render add buttons for `cards`, `year`, `mine`, and `service` only when their required date/person/service mappings exist; otherwise show the precise unmet requirement. Every view has enable/name/icon/up/down/delete/default, columns, services, period, multi-sort, and filter controls. Filter operator change clears value for `isEmpty|isNotEmpty|open`; open is offered only for person columns. `FlowPreviewStep` emits no events and performs no network operation until `Preview` is clicked; its allowlist editors mutate only `draft.flow.actions`.

- [ ] **Step 3: Write failing real-preview, stale-draft and failed-save tests**

Add tests that mock `src/api.js` with Vitest `vi.mock` and use real component state:

```js
it('renders returned Tables values and never fabricates an empty preview', async () => {
  previewDefinition.mockResolvedValue({ issues: [], normalized: draft, table: metadata, rows: [{ id: 900, cells: { 101: '2027-01-10', 102: 'Actual value', 103: '', 104: 'Ada, Grace' } }], summary: { columnCount: 4, enabledViewCount: 3 } })
  const wrapper = await readyFinalWizard(draft)
  await wrapper.get('[data-test="preview"]').trigger('click')
  await flushPromises()
  expect(wrapper.get('[data-test="preview-table"]').text()).toContain('Actual value')
  expect(wrapper.get('[data-test="preview-table"]').text()).toContain('Ada, Grace')
  expect(wrapper.find('[data-test="runtime-view"]').exists()).toBe(false)
})

it('invalidates preview and preserves the draft when saving fails', async () => {
  previewDefinition.mockResolvedValue({ issues: [], normalized: draft, table: metadata, rows: [], summary: {} })
  createPlan.mockRejectedValue({ response: { data: { error: 'Configuration is invalid.', issues: [{ code: 'column.missing', path: 'columns.3.columnId', message: 'Column 104 no longer exists in the Tables table.' }] } } })
  const wrapper = await readyFinalWizard(draft)
  await wrapper.get('[data-test="preview"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-test="save-plan"]').trigger('click')
  await flushPromises()
  expect(wrapper.text()).toContain('Column 104 no longer exists')
  expect(wrapper.vm.draft.name).toBe(draft.name)
  expect(wrapper.emitted('saved')).toBeUndefined()
})
```

Define the helper against the production props and navigation, with no test-only API:

```js
async function readyFinalWizard(draft) {
  const wrapper = mount(SetupWizard, {
    props: { initialPlan: null, initialDraft: draft, expectedRevision: null },
  })
  await flushPromises()
  await wrapper.get('[data-test="go-step-4"]').trigger('click')
  await flushPromises()
  return wrapper
}
```

Use `initialDraft` consistently in tests and production and render `data-test="go-step-4"` on the real step navigation. Add assertions that any deep draft edit marks preview stale and disables Save, an edit made while preview is pending prevents that response from enabling Save, a failed preview leaves old rows hidden, and edit preserves service/view/filter/Flow IDs.

- [ ] **Step 4: Implement real preview and summary**

`PreviewTable` uses returned `normalized.columns` order and `displayLabel`, looks up values only by numeric `columnId`, and renders scalar values as text; arrays/objects use `JSON.stringify` solely for faithful display, never for editing. Empty `rows` shows `The Tables table has no visible rows.` rather than sample content. Header shows real table title/ID and each actual Tables title alongside its alternative label. Summary renders table title, column/service/group/enabled-view counts, default view title, self-signup service count and Flow action count from the server response. Include the explicit caption `Configuration preview with real Tables data; runtime views are not part of this setup block.`

`FlowPreviewStep` shows current issues beside their JSON paths and a `Preview` button. Before `previewDefinition(draft)`, capture `const requestRevision = draftRevision.value`; store `{...response, revision: requestRevision}` only if `requestRevision === draftRevision.value` when the response arrives, otherwise discard the stale response. The revision is browser-local freshness metadata, not a server field. On issues, show metadata and summary if returned but set rows to the returned empty list; on transport errors clear previous preview and retain draft.

- [ ] **Step 5: Implement five-step navigation and guarded atomic save**

Replace Task 4's terminal disabled Continue with Views and Flow/Preview steps. Back/Next always retain one reactive draft. Use `watch(draft, () => { if (applyingNormalized.value) return; draftRevision.value += 1; preview.value = null }, { deep: true })`; set `applyingNormalized` true only while applying a server normalized response and restore it immediately afterward. Save requires `preview.value?.revision === draftRevision.value` and no issues.

Save is enabled only when a preview exists for the current draft revision and `issues.length === 0`. On click, re-run `validateDefinition` to catch structure changes after preview; if issues exist, keep the draft and do not call create/update. Otherwise call `createPlan(draft)` or `updatePlan(planId,draft,expectedRevision)`. On success emit `saved` with returned plan. On 409 show the exact reload/conflict message and retain the draft; on 422 show all returned issues; on network failure retain the draft. A separate `Reload saved plan` action calls `getPlan` only after explicit user confirmation because it replaces the conflicting local draft.

On opening an existing plan, display `configuration.incomplete`, `column.missing`, and `column.typeChanged` issues in the relevant steps. The user remaps IDs/roles and previews again; saving remains blocked until current metadata validates. This is the correction path for deleted and type-changed Tables columns.

- [ ] **Step 6: Run all frontend/backend tests and production build**

Run `npm test && npm run build && composer test && git diff --check`. Expected: all component/draft tests and PHP tests pass, production bundle builds, no whitespace errors. Confirm the wizard exposes no cell-write, join/leave execution, Flow execution, or runtime-view endpoint/button by searching `src` for `/join`, `/leave`, `/cells`, and `runtime-view`; expected no production matches.

- [ ] **Step 7: Commit the complete setup interaction**

```bash
git add src tests/Frontend
git commit -m "feat: complete setup preview and draft editing"
```

### Task 6: Real NC35 + Tables 2.3.2 Browser/API End-to-End Verification

**Files:**
- Create: `playwright.config.js`
- Create: `tests/E2E/setup.spec.js`
- Create: `tests/Integration/setup_fixture.php`
- Create: `tests/Integration/setup.sh`
- Modify: `package.json`, `package-lock.json`

**Interfaces:**
- Consumes: the complete wizard and every Task 2/3 API; existing smoke test remains unchanged.
- Produces: one opt-in disposable-instance command covering build, Chromium setup/edit, API authorization, current-structure repair, stable IDs, atomic conflicts and configuration-only deletion.

- [ ] **Step 1: Add Playwright and write the failing browser journey**

Run `npm install --save-dev @playwright/test@^1.56.1` and add script `"test:e2e": "playwright test"`. Create config with `testDir:'./tests/E2E'`, `fullyParallel:false`, one worker, 60-second timeout, Chromium desktop, headless by default, `baseURL:process.env.NEXTCLOUD_URL`, and `trace:'retain-on-failure'`.

Create `tests/E2E/setup.spec.js` with these concrete helpers; all API calls execute in the logged-in browser origin and never bypass the application server:

```js
async function login(page, user, password) {
  await page.goto('/index.php/login')
  await page.locator('#user').fill(user)
  await page.locator('#password').fill(password)
  await page.locator('button[type="submit"]').click()
  await expect(page).not.toHaveURL(/\/login/)
}

async function api(page, method, path, body) {
  return page.evaluate(async ({ method, path, body }) => {
    const response = await fetch('/index.php/apps/serviceplanner' + path, {
      method,
      headers: { 'X-Request-Token': OC.requestToken, Accept: 'application/json', 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    return { status: response.status, body: await response.json() }
  }, { method, path, body })
}

const goStep = (page, index) => page.getByTestId(`go-step-${index}`).click()
```

The single serial journey must use `data-test` selectors from Tasks 4–5 and perform exactly these assertions:

1. Sign in as the Tables manager, open `/index.php/apps/serviceplanner#/plans`, assert the compiled main script response is 200 and collect no browser `pageerror` or console `error` messages.
2. Choose New plan, enter plan metadata, choose `service-basic`, create a real table, and capture its numeric ID from the displayed metadata. Assert four real column IDs; do not hard-code Tables-generated IDs.
3. Seed one row through `tests/Integration/setup_fixture.php` using the fixture command described below. Map Date/Title/Notes/Team by their returned IDs, set Team to person, configure its service icon/label/placeholders, enable signup and leave, select manager and visible groups, and assert real share/capability text plus the Tables deep link.
4. Enable/configure cards, year, mine and service views; set cards default; add `contains`, `equals`, `isEmpty`, `isNotEmpty`, and `open` filters across suitable views; configure signup/leave/setCell allowlists. Open preview and assert the exact seeded value and real labels appear and no sample/runtime view appears.
5. Save, then retrieve the plan through `GET /api/plans/{id}`. Assert all normalized rules, filter operators, self-signup/leave and Flow allowlists are persisted. Save the plan/service/view/filter/Flow UUIDs for later comparison.
6. In a second browser context sign in as a user in the configured manager group but without Tables manage permission. Assert `GET /api/plans/{id}` is 404 and `GET /api/plans` omits the plan. This proves app groups do not expand Tables rights.
7. Back as manager, edit through `#/plan/{id}/edit`, rename the plan and a display label, save, and reopen the same direct hash URL. Assert numeric plan ID and every saved UUID is unchanged and Tables column titles are unchanged.
8. Send `PUT /api/plans/{id}` with the pre-edit `expectedRevision`; assert 409 and verify the current stored name remains the renamed one. Send scalar `definition` and separately `expectedRevision: 1.5`; assert 400 and no mutation for each. In a fresh request without `X-Request-Token`, attempt `PUT` using the logged-in cookies; assert 412 and no configuration change. In an incognito request, assert `POST /api/plans` returns 401 and creates nothing.
9. Through the fixture command change Team from `text/line` to `number`. Reopen edit, assert `column.typeChanged` at the Team mapping and Save unavailable. Delete the Team column through the fixture, refresh metadata, assert `column.missing`, remap the service to Notes, preview real data and save successfully.
10. Delete only the ServicePlanner configuration and confirm the warning. Assert plan GET is 404, while `GET /api/tables/{tableId}` still returns the table and `setup_fixture.php assert-row` still finds the seeded Tables row. Finish with zero browser errors.

Write the full Playwright test before running it; each numbered item above must be represented by explicit Playwright locators/assertions and API response assertions, not a manual checklist.

- [ ] **Step 2: Implement the real Tables fixture commands**

`tests/Integration/setup_fixture.php` bootstraps Nextcloud exactly as `tables_adapter.php` and accepts:

```text
php tests/Integration/setup_fixture.php seed-row <tableId> <userId> <title>
php tests/Integration/setup_fixture.php change-team-number <tableId> <userId>
php tests/Integration/setup_fixture.php delete-team <tableId> <userId>
php tests/Integration/setup_fixture.php assert-row <tableId> <userId> <title>
```

Resolve columns through `ColumnService::findAllByTable` and actual titles only in this disposable fixture, never in production. `seed-row` uses real `RowService::create` with Date `2027-01-10`, supplied Title, Notes `Seed note`, and Team `Ada, Grace`, then prints the row ID. `change-team-number` uses `ColumnService::update($teamId,$userId,ColumnDto::createFromArray(['type'=>'number','subtype'=>'']))`. `delete-team` uses `ColumnService::delete($teamId,false,$userId)`. `assert-row` reads through ServicePlanner's `TablesGateway::previewRows` and exits nonzero unless at least one Title cell exactly matches. These commands are test-only and are never registered in the app.

- [ ] **Step 3: Run the browser test RED against the built app**

Run:

```bash
npm run build
npx playwright install chromium
NEXTCLOUD_ROOT=/absolute/path/to/nextcloud35 NEXTCLOUD_URL=https://nextcloud35.test \
SERVICEPLANNER_ADMIN_USER=admin SERVICEPLANNER_ADMIN_PASSWORD="$SERVICEPLANNER_ADMIN_PASSWORD" \
SERVICEPLANNER_TEST_USER=volunteer SERVICEPLANNER_TEST_PASSWORD="$SERVICEPLANNER_TEST_PASSWORD" \
npm run test:e2e
```

Expected before the fixture/app integration is complete: FAIL at the first missing fixture, selector or application route. Record the actual failure. Do not mock the network or run this against a component server.

- [ ] **Step 4: Add the disposable-instance orchestration script**

Create executable `tests/Integration/setup.sh` with `set -euo pipefail` and required variables `NEXTCLOUD_ROOT`, `OCC_USER`, `NEXTCLOUD_URL`, both usernames/passwords. Reuse the existing smoke script's safe `run_occ` and symlink/trap pattern; refuse to replace an existing app directory. Generate a unique run ID and unique `planner-admins-$RUN_ID`/`volunteers-$RUN_ID` groups, create them with `occ group:add`, add the manager to both and the non-Tables manager to `planner-admins-$RUN_ID`, and export these IDs for Playwright. Do not print password variables.

The script executes in this order:

```bash
php -v
node --version
npm --version
npm ci
npm test
npm run build
composer test
NEXTCLOUD_ROOT="$NEXTCLOUD_ROOT" OCC_USER="$OCC_USER" tests/Integration/smoke.sh
NEXTCLOUD_ROOT="$NEXTCLOUD_ROOT" SERVICEPLANNER_TEST_USER="$SERVICEPLANNER_ADMIN_USER" php tests/Integration/tables_adapter.php
npx playwright install chromium
npm run test:e2e
git diff --check
```

Run the existing smoke test before creating this script's symlink. Assert the actual runtime with this exact bootstrap check before adapter/browser execution:

```bash
NEXTCLOUD_ROOT="$NEXTCLOUD_ROOT" php -r '
require getenv("NEXTCLOUD_ROOT") . "/lib/base.php";
$nextcloud = OCP\Util::getVersion();
$tables = OCP\Server::get(OCP\App\IAppManager::class)->getAppVersion("tables");
if ($nextcloud[0] !== 35 || $tables !== "2.3.2") {
    fwrite(STDERR, "Requires Nextcloud 35 and Tables 2.3.2; got PHP " . PHP_VERSION . ", Nextcloud " . implode(".", $nextcloud) . ", Tables $tables\n");
    exit(1);
}
'
```

Only after the production build, smoke test and adapter check, create the symlink and run `run_occ app:enable serviceplanner` so `PageController` loads real emitted assets. The trap removes only the temporary symlink and test groups created by this run. Tables tables/rows are deliberately not deleted; use a disposable instance and retain them for failure diagnosis.

- [ ] **Step 5: Make the end-to-end journey GREEN and verify the real browser artifact**

With all required environment variables exported and `OCC_USER=''` for the current user, run `tests/Integration/setup.sh`.

Expected: PHP unit suite, existing smoke, Tables adapter integration, Vitest, Vite production build and the complete Chromium journey all pass. Inspect the Playwright report and built asset responses; a successful API-only run is not a substitute. If Nextcloud emits different login URLs, adjust only the login helper to the observed NC35 behavior, never weaken authorization or preview assertions.

- [ ] **Step 6: Perform final scoped regression and commit**

Run `git status --short`, `git diff --check`, `composer test`, `npm test`, and `npm run build` once more. Expected: only setup-block files changed, no changes under the read-only reference repository, all checks green. Review `git diff` to confirm no Tables row/model, cell-write, runtime renderer or Flow registration files were added.

```bash
git add package.json package-lock.json playwright.config.js tests/E2E tests/Integration/setup.sh tests/Integration/setup_fixture.php
git commit -m "test: verify setup on Nextcloud 35 and Tables 2.3.2"
```

## Release Hardening (outside this setup block)

- Repeat installation and the same central browser/API journey on Nextcloud 36 before declaring NC36 runtime coverage; the XML range alone is not evidence.
- Run the matrix on every database and PHP version intended for release; this plan verifies only the actual recorded PHP runtime of the integration instance and its configured database.
- Build and install the distributable app package in clean instances, verify asset/content-security-policy behavior and accessibility/localization, and test upgrade from the foundation migration.
- Subsequent independent blocks own configured runtime cards/year/mine/service rendering, cell writes and self-signup/leave execution with locking, and Flow entities/events/actions. The setup schema and allowlists are their input, not implementations in this plan.
