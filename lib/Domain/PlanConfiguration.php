<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Domain;

use InvalidArgumentException;
use JsonSerializable;

final class PlanConfiguration implements JsonSerializable {
    private function __construct(private array $configuration) {
    }

    public static function fromArray(array $input): self {
        if (($input['schemaVersion'] ?? null) !== 1) {
            throw new InvalidArgumentException('schemaVersion must be 1.');
        }
        foreach (['name', 'description', 'icon', 'color'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                throw new InvalidArgumentException($field . ' must be a string.');
            }
        }
        $tableId = self::positiveId($input['tableId'] ?? null, 'tableId');
        $metadata = PlanDefinition::fromArray([
            'name' => $input['name'],
            'description' => $input['description'],
            'icon' => $input['icon'],
            'color' => $input['color'],
            'tableId' => $tableId,
        ])->jsonSerialize();

        $personNames = self::record($input['personNames'] ?? null, 'personNames');
        $separators = self::nestedArrayAt($personNames, 'personNames.separators');
        if ($separators !== [',', ';', "\n"]) {
            throw new InvalidArgumentException('personNames.separators must be comma, semicolon and newline.');
        }

        [$columns, $columnMap] = self::columns(self::nestedArrayAt($input, 'columns'));
        [$services, $serviceMap, $serviceByColumn] = self::services(self::nestedArrayAt($input, 'services'), $columnMap);
        $permissions = self::permissions(self::record($input['permissions'] ?? null, 'permissions'));
        [$views, $viewMap] = self::views(self::nestedArrayAt($input, 'views'), $columnMap, $serviceMap);

        $defaultViewId = self::id($input['defaultViewId'] ?? null, 'defaultViewId');
        if (!isset($viewMap[$defaultViewId]) || !$viewMap[$defaultViewId]['enabled']) {
            throw new InvalidArgumentException('defaultViewId must reference an enabled view.');
        }

        $flow = self::flow(
            self::record($input['flow'] ?? null, 'flow'),
            $columnMap,
            $serviceByColumn,
        );

        return new self([
            'schemaVersion' => 1,
            'name' => $metadata['name'],
            'description' => $metadata['description'],
            'icon' => $metadata['icon'],
            'color' => $metadata['color'],
            'tableId' => $metadata['tableId'],
            'personNames' => ['separators' => $separators],
            'columns' => $columns,
            'services' => $services,
            'permissions' => $permissions,
            'views' => $views,
            'defaultViewId' => $defaultViewId,
            'flow' => $flow,
        ]);
    }

    public function jsonSerialize(): array {
        return $this->configuration;
    }

    private static function columns(array $input): array {
        $columns = [];
        $map = [];
        $hasDate = false;
        $hasTitle = false;
        foreach ($input as $index => $value) {
            $path = "columns.$index";
            $column = self::record($value, $path);
            $id = self::positiveId($column['columnId'] ?? null, "$path.columnId");
            if (isset($map[$id])) {
                throw new InvalidArgumentException("$path.columnId must not contain duplicates.");
            }
            $role = self::choice($column['role'] ?? null, ['date', 'title', 'person', 'text'], "$path.role");
            if ($role === 'date') {
                if ($hasDate) {
                    throw new InvalidArgumentException("$path.role must contain exactly one date column.");
                }
                $hasDate = true;
            }
            if ($role === 'title') {
                if ($hasTitle) {
                    throw new InvalidArgumentException("$path.role must contain at most one title column.");
                }
                $hasTitle = true;
            }
            $label = self::text($column['displayLabel'] ?? null, "$path.displayLabel", 190);
            self::integer($column['position'] ?? null, "$path.position");
            $normalized = [
                'columnId' => $id,
                'role' => $role,
                'displayLabel' => $label,
                'position' => $index,
            ];
            $columns[] = $normalized;
            $map[$id] = $normalized;
        }
        if (!$hasDate) {
            throw new InvalidArgumentException('columns.0.role must contain exactly one date column.');
        }
        return [$columns, $map];
    }

    private static function services(array $input, array $columnMap): array {
        $services = [];
        $map = [];
        $byColumn = [];
        foreach ($input as $index => $value) {
            $path = "services.$index";
            $service = self::record($value, $path);
            $id = self::id($service['id'] ?? null, "$path.id");
            if (isset($map[$id])) {
                throw new InvalidArgumentException("$path.id must not contain duplicates.");
            }
            $name = self::text($service['name'] ?? null, "$path.name");
            $icon = self::text($service['icon'] ?? null, "$path.icon");
            $columnIds = self::nestedArrayAt($service, "$path.columnIds");
            self::assertUnique($columnIds, "$path.columnIds");
            $normalizedColumnIds = [];
            foreach ($columnIds as $columnIndex => $columnValue) {
                $columnPath = "$path.columnIds.$columnIndex";
                $columnId = self::positiveId($columnValue, $columnPath);
                if (!isset($columnMap[$columnId]) || $columnMap[$columnId]['role'] !== 'person') {
                    throw new InvalidArgumentException("$columnPath must reference a configured person column.");
                }
                if (isset($byColumn[$columnId])) {
                    throw new InvalidArgumentException("$columnPath must not assign a person column to multiple services.");
                }
                $normalizedColumnIds[] = $columnId;
            }
            $placeholders = [];
            foreach (self::nestedArrayAt($service, "$path.notAssignedValues") as $valueIndex => $placeholder) {
                $placeholders[] = self::text($placeholder, "$path.notAssignedValues.$valueIndex");
            }
            self::assertUnique($placeholders, "$path.notAssignedValues");
            $signup = self::record($service['selfSignup'] ?? null, "$path.selfSignup");
            $enabled = self::boolean($signup['enabled'] ?? null, "$path.selfSignup.enabled");
            $leaveEnabled = self::boolean($signup['leaveEnabled'] ?? null, "$path.selfSignup.leaveEnabled");
            if ($leaveEnabled && !$enabled) {
                throw new InvalidArgumentException("$path.selfSignup.leaveEnabled requires signup to be enabled.");
            }
            $normalized = [
                'id' => $id,
                'name' => $name,
                'icon' => $icon,
                'columnIds' => $normalizedColumnIds,
                'notAssignedValues' => $placeholders,
                'selfSignup' => ['enabled' => $enabled, 'leaveEnabled' => $leaveEnabled],
            ];
            $services[] = $normalized;
            $map[$id] = $normalized;
            foreach ($normalizedColumnIds as $columnId) {
                $byColumn[$columnId] = $normalized;
            }
        }
        return [$services, $map, $byColumn];
    }

    private static function permissions(array $input): array {
        $managerGroups = self::groups(self::nestedArrayAt($input, 'permissions.managerGroups'), 'permissions.managerGroups');
        $visibleGroups = self::groups(self::nestedArrayAt($input, 'permissions.visibleGroups'), 'permissions.visibleGroups');
        $visible = array_fill_keys($visibleGroups, true);
        foreach ($managerGroups as $index => $group) {
            if (!isset($visible[$group])) {
                throw new InvalidArgumentException("permissions.managerGroups.$index must also be a visible group.");
            }
        }
        return ['managerGroups' => $managerGroups, 'visibleGroups' => $visibleGroups];
    }

    private static function groups(array $input, string $path): array {
        if ($input === []) {
            throw new InvalidArgumentException("$path must not be empty.");
        }
        $groups = [];
        foreach ($input as $index => $value) {
            $groups[] = self::text($value, "$path.$index");
        }
        self::assertUnique($groups, $path);
        return $groups;
    }

    private static function views(array $input, array $columnMap, array $serviceMap): array {
        if ($input === []) {
            throw new InvalidArgumentException('views must contain at least one enabled view.');
        }
        $views = [];
        $map = [];
        $enabledCount = 0;
        foreach ($input as $index => $value) {
            $path = "views.$index";
            $view = self::record($value, $path);
            $id = self::id($view['id'] ?? null, "$path.id");
            if (isset($map[$id])) {
                throw new InvalidArgumentException("$path.id must not contain duplicates.");
            }
            $type = self::choice($view['type'] ?? null, ['cards', 'year', 'mine', 'service'], "$path.type");
            $title = self::text($view['title'] ?? null, "$path.title");
            $icon = self::text($view['icon'] ?? null, "$path.icon");
            $enabled = self::boolean($view['enabled'] ?? null, "$path.enabled");
            self::integer($view['position'] ?? null, "$path.position");

            $columnIds = self::nestedArrayAt($view, "$path.columnIds");
            self::assertUnique($columnIds, "$path.columnIds");
            $normalizedColumnIds = [];
            $viewColumns = [];
            foreach ($columnIds as $columnIndex => $columnValue) {
                $columnPath = "$path.columnIds.$columnIndex";
                $columnId = self::positiveId($columnValue, $columnPath);
                if (!isset($columnMap[$columnId])) {
                    throw new InvalidArgumentException("$columnPath must reference a configured column.");
                }
                $normalizedColumnIds[] = $columnId;
                $viewColumns[$columnId] = true;
            }

            $serviceIds = self::nestedArrayAt($view, "$path.serviceIds");
            self::assertUnique($serviceIds, "$path.serviceIds");
            $normalizedServiceIds = [];
            foreach ($serviceIds as $serviceIndex => $serviceValue) {
                $servicePath = "$path.serviceIds.$serviceIndex";
                $serviceId = self::id($serviceValue, $servicePath);
                if (!isset($serviceMap[$serviceId])) {
                    throw new InvalidArgumentException("$servicePath must reference a configured service.");
                }
                $normalizedServiceIds[] = $serviceId;
            }
            if ($type === 'service' && $normalizedServiceIds === []) {
                throw new InvalidArgumentException("$path.serviceIds must not be empty for a service view.");
            }

            $period = self::period(self::record($view['period'] ?? null, "$path.period"), "$path.period");
            $sort = self::sort(self::nestedArrayAt($view, "$path.sort"), "$path.sort", $viewColumns);
            $filters = self::filters(self::nestedArrayAt($view, "$path.filters"), "$path.filters", $viewColumns, $columnMap);
            $normalized = [
                'id' => $id,
                'type' => $type,
                'title' => $title,
                'icon' => $icon,
                'enabled' => $enabled,
                'position' => $index,
                'columnIds' => $normalizedColumnIds,
                'serviceIds' => $normalizedServiceIds,
                'period' => $period,
                'sort' => $sort,
                'filters' => $filters,
            ];
            $views[] = $normalized;
            $map[$id] = $normalized;
            if ($enabled) {
                $enabledCount++;
            }
        }
        if ($enabledCount === 0) {
            throw new InvalidArgumentException('views must contain at least one enabled view.');
        }
        return [$views, $map];
    }

    private static function period(array $input, string $path): array {
        $kind = self::choice($input['kind'] ?? null, ['upcoming', 'year'], "$path.kind");
        if ($kind === 'upcoming') {
            $days = self::integer($input['days'] ?? null, "$path.days");
            if ($days < 1 || $days > 3660) {
                throw new InvalidArgumentException("$path.days must be between 1 and 3660.");
            }
            return ['kind' => $kind, 'days' => $days];
        }
        $year = $input['year'] ?? null;
        if ($year !== 'current' && (!is_int($year) || $year < 2000 || $year > 2100)) {
            throw new InvalidArgumentException("$path.year must be current or an integer between 2000 and 2100.");
        }
        return ['kind' => $kind, 'year' => $year];
    }

    private static function sort(array $input, string $path, array $viewColumns): array {
        $sort = [];
        $columnIds = [];
        foreach ($input as $index => $value) {
            $itemPath = "$path.$index";
            $item = self::record($value, $itemPath);
            $columnId = self::positiveId($item['columnId'] ?? null, "$itemPath.columnId");
            if (!isset($viewColumns[$columnId])) {
                throw new InvalidArgumentException("$itemPath.columnId must be included in the view.");
            }
            $columnIds[] = $columnId;
            $sort[] = [
                'columnId' => $columnId,
                'direction' => self::choice($item['direction'] ?? null, ['asc', 'desc'], "$itemPath.direction"),
            ];
        }
        self::assertUnique($columnIds, $path);
        return $sort;
    }

    private static function filters(array $input, string $path, array $viewColumns, array $columnMap): array {
        $filters = [];
        $ids = [];
        foreach ($input as $index => $value) {
            $itemPath = "$path.$index";
            $filter = self::record($value, $itemPath);
            $id = self::id($filter['id'] ?? null, "$itemPath.id");
            if (in_array($id, $ids, true)) {
                throw new InvalidArgumentException("$itemPath.id must not contain duplicates.");
            }
            $ids[] = $id;
            $columnId = self::positiveId($filter['columnId'] ?? null, "$itemPath.columnId");
            if (!isset($viewColumns[$columnId])) {
                throw new InvalidArgumentException("$itemPath.columnId must be included in the view.");
            }
            $operator = self::choice(
                $filter['operator'] ?? null,
                ['contains', 'equals', 'isEmpty', 'isNotEmpty', 'open'],
                "$itemPath.operator",
            );
            $filterValue = $filter['value'] ?? null;
            if (!is_string($filterValue)) {
                throw new InvalidArgumentException("$itemPath.value must be a string.");
            }
            if (in_array($operator, ['contains', 'equals'], true)) {
                if (trim($filterValue) === '') {
                    throw new InvalidArgumentException("$itemPath.value must not be empty for $operator.");
                }
            } elseif ($filterValue !== '') {
                throw new InvalidArgumentException("$itemPath.value must be empty for $operator.");
            }
            if ($operator === 'open' && $columnMap[$columnId]['role'] !== 'person') {
                throw new InvalidArgumentException("$itemPath.operator requires a person column.");
            }
            $filters[] = ['id' => $id, 'columnId' => $columnId, 'operator' => $operator, 'value' => $filterValue];
        }
        return $filters;
    }

    private static function flow(array $input, array $columnMap, array $serviceByColumn): array {
        $actions = [];
        $map = [];
        foreach (self::nestedArrayAt($input, 'flow.actions') as $index => $value) {
            $path = "flow.actions.$index";
            $action = self::record($value, $path);
            $id = self::id($action['id'] ?? null, "$path.id");
            if (isset($map[$id])) {
                throw new InvalidArgumentException("$path.id must not contain duplicates.");
            }
            $type = self::choice($action['type'] ?? null, ['signup', 'leave', 'setCell'], "$path.type");
            $columnIds = self::nestedArrayAt($action, "$path.columnIds");
            if ($columnIds === []) {
                throw new InvalidArgumentException("$path.columnIds must not be empty.");
            }
            self::assertUnique($columnIds, "$path.columnIds");
            $normalizedColumnIds = [];
            foreach ($columnIds as $columnIndex => $columnValue) {
                $columnPath = "$path.columnIds.$columnIndex";
                $columnId = self::positiveId($columnValue, $columnPath);
                if (!isset($columnMap[$columnId])) {
                    throw new InvalidArgumentException("$columnPath must reference a configured column.");
                }
                if ($type === 'setCell' && $columnMap[$columnId]['role'] === 'date') {
                    throw new InvalidArgumentException("$columnPath must not target the date column.");
                }
                if ($type !== 'setCell') {
                    $service = $serviceByColumn[$columnId] ?? null;
                    $allowed = $service !== null && $service['selfSignup'][$type === 'signup' ? 'enabled' : 'leaveEnabled'];
                    if (!$allowed) {
                        throw new InvalidArgumentException("$columnPath must belong to a service with $type enabled.");
                    }
                }
                $normalizedColumnIds[] = $columnId;
            }
            $normalized = ['id' => $id, 'type' => $type, 'columnIds' => $normalizedColumnIds];
            $actions[] = $normalized;
            $map[$id] = $normalized;
        }
        return ['actions' => $actions];
    }

    private static function record(mixed $value, string $path): array {
        if (!is_array($value)) {
            throw new InvalidArgumentException("$path must be an object.");
        }
        return $value;
    }

    private static function text(mixed $value, string $path, ?int $maxLength = null): string {
        if (!is_string($value)) {
            throw new InvalidArgumentException("$path must be a string.");
        }
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("$path must not be empty.");
        }
        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("$path must not exceed $maxLength characters.");
        }
        return $value;
    }

    private static function boolean(mixed $value, string $path): bool {
        if (!is_bool($value)) {
            throw new InvalidArgumentException("$path must be a boolean.");
        }
        return $value;
    }

    private static function integer(mixed $value, string $path): int {
        if (!is_int($value)) {
            throw new InvalidArgumentException("$path must be an integer.");
        }
        return $value;
    }

    private static function choice(mixed $value, array $choices, string $path): string {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new InvalidArgumentException("$path has an unsupported value.");
        }
        return $value;
    }

    private static function nestedArrayAt(array $input, string $path): array {
        $separator = strrpos($path, '.');
        $key = $separator === false ? $path : substr($path, $separator + 1);
        try {
            return self::arrayAt($input, $key);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException($path . ' must be a list.');
        }
    }

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
}
