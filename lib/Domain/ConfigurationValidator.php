<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Domain;

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

    private function validateReferences(array $configuration, array $actual, array $mapped, array &$issues): void {
        foreach ($configuration['services'] as $serviceIndex => $service) {
            foreach ($service['columnIds'] as $columnIndex => $id) {
                $this->checkReference($issues, $id, "services.$serviceIndex.columnIds.$columnIndex", 'service', $actual, $mapped, 'person');
            }
        }
        foreach ($configuration['views'] as $viewIndex => $view) {
            foreach ($view['columnIds'] as $columnIndex => $id) {
                $this->checkReference($issues, $id, "views.$viewIndex.columnIds.$columnIndex", 'view', $actual, $mapped);
            }
            foreach ($view['sort'] as $sortIndex => $sort) {
                $this->checkReference($issues, $sort['columnId'], "views.$viewIndex.sort.$sortIndex.columnId", 'sort', $actual, $mapped);
            }
            foreach ($view['filters'] as $filterIndex => $filter) {
                $requiredRole = $filter['operator'] === 'open' ? 'person' : null;
                $this->checkReference($issues, $filter['columnId'], "views.$viewIndex.filters.$filterIndex.columnId", 'filter', $actual, $mapped, $requiredRole);
            }
        }
        foreach ($configuration['flow']['actions'] as $actionIndex => $action) {
            foreach ($action['columnIds'] as $columnIndex => $id) {
                $path = "flow.actions.$actionIndex.columnIds.$columnIndex";
                $requiredRole = in_array($action['type'], ['signup', 'leave'], true) ? 'person' : null;
                $this->checkReference($issues, $id, $path, 'flow', $actual, $mapped, $requiredRole);
                if ($action['type'] === 'setCell' && isset($actual[$id], $mapped[$id]) && $mapped[$id]['role'] === 'date') {
                    $issues[] = ['code' => 'flow.columnTypeChanged', 'path' => $path, 'message' => "Referenced column $id no longer has the required role."];
                }
            }
        }
    }

    private function checkReference(array &$issues, int $id, string $path, string $code, array $actual, array $mapped, ?string $requiredRole = null): void {
        if (!isset($actual[$id])) {
            $issues[] = ['code' => "$code.columnMissing", 'path' => $path, 'message' => "Referenced column $id no longer exists."];
            return;
        }
        if (!isset($mapped[$id]) || ($requiredRole !== null && $mapped[$id]['role'] !== $requiredRole)) {
            $issues[] = ['code' => "$code.columnTypeChanged", 'path' => $path, 'message' => "Referenced column $id no longer has the required role."];
        }
    }
}
