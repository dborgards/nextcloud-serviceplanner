<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service;

use InvalidArgumentException;
use OCA\ServicePlanner\Domain\ConfigurationValidator;
use OCA\ServicePlanner\Domain\PlanConfiguration;
use OCA\ServicePlanner\Repository\PlanRepository;
use OCA\ServicePlanner\Service\Groups\GroupDirectory;
use OCA\ServicePlanner\Service\Tables\TablesGateway;

final class PlanSetupService {
    public function __construct(
        private PlanRepository $repository,
        private TablesGateway $tables,
        private GroupDirectory $groups,
        private PlanAccess $access,
        private ConfigurationValidator $validator,
    ) {
    }

    public function list(string $userId): array {
        $plans = [];
        foreach ($this->repository->all() as $plan) {
            try {
                $this->access->assertPlanManager($plan, $userId);
                $plans[] = $plan;
            } catch (SetupException) {
            }
        }
        return $plans;
    }

    public function get(int $id, string $userId): array {
        $plan = $this->plan($id, $userId);
        $table = $this->tables->metadata((int)$plan['tableId'], $userId);
        try {
            $normalized = PlanConfiguration::fromArray($plan)->jsonSerialize();
            $issues = $this->validator->validate($normalized, $table);
        } catch (InvalidArgumentException $error) {
            $issues = [[
                'code' => 'configuration.incomplete',
                'path' => 'configuration',
                'message' => $error->getMessage(),
            ]];
        }
        return ['plan' => $plan, 'table' => $table, 'issues' => $issues];
    }

    public function validate(array $input, string $userId): array {
        [$normalized, , $issues] = $this->checked($input, $userId);
        return ['issues' => $issues, 'normalized' => $normalized];
    }

    public function preview(array $input, string $userId): array {
        [$normalized, $metadata, $issues] = $this->checked($input, $userId);
        $rows = $issues === [] ? $this->tables->previewRows(
            $normalized['tableId'],
            $userId,
            array_column($normalized['columns'], 'columnId'),
            10,
        ) : [];
        return [
            'issues' => $issues,
            'normalized' => $normalized,
            'table' => $metadata,
            'rows' => $rows,
            'summary' => $this->summary($normalized, $metadata),
        ];
    }

    public function create(array $input, string $userId): array {
        [$normalized, , $issues] = $this->checked($input, $userId);
        $this->assertSaveAllowed($normalized, $issues, $userId);
        return $this->repository->add($normalized);
    }

    public function update(int $id, int $expectedRevision, array $input, string $userId): array {
        $this->plan($id, $userId);
        [$normalized, , $issues] = $this->checked($input, $userId);
        $this->assertSaveAllowed($normalized, $issues, $userId);
        return $this->repository->update($id, $expectedRevision, $normalized);
    }

    public function delete(int $id, string $userId): void {
        $this->plan($id, $userId);
        $this->repository->delete($id);
    }

    public function listTables(string $userId): array {
        try {
            $tables = $this->tables->listTables($userId);
        } catch (SetupException $error) {
            if ($error->getStatus() === 503) {
                return ['tables' => [], 'tablesAvailable' => false];
            }
            throw $error;
        }
        foreach ($tables as &$table) {
            unset($table['columns'], $table['shares']);
        }
        unset($table);
        return ['tables' => $tables, 'tablesAvailable' => true];
    }

    public function createTable(string $title, string $emoji, string $description, string $template, string $userId): array {
        if ($template !== 'service-basic') {
            throw new InvalidArgumentException('Unknown table template.');
        }
        return $this->tables->createServiceTable($title, $emoji, $description, $userId);
    }

    public function table(int $tableId, string $userId): array {
        return $this->tables->metadata($tableId, $userId);
    }

    public function groups(): array {
        return $this->groups->listGroups();
    }

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

    private function plan(int $id, string $userId): array {
        $plan = $this->repository->findById($id);
        if ($plan === null) {
            throw new SetupException('Plan is not available.', 404);
        }
        $this->access->assertPlanManager($plan, $userId);
        return $plan;
    }

    private function summary(array $normalized, array $metadata): array {
        $views = array_column($normalized['views'], null, 'id');
        return [
            'tableTitle' => $metadata['title'],
            'columnCount' => count($normalized['columns']),
            'serviceCount' => count($normalized['services']),
            'managerGroupCount' => count($normalized['permissions']['managerGroups']),
            'visibleGroupCount' => count($normalized['permissions']['visibleGroups']),
            'enabledViewCount' => count(array_filter($normalized['views'], static fn (array $view): bool => $view['enabled'])),
            'defaultViewTitle' => $views[$normalized['defaultViewId']]['title'],
            'selfSignupServiceCount' => count(array_filter($normalized['services'], static fn (array $service): bool => $service['selfSignup']['enabled'])),
            'flowActionCount' => count($normalized['flow']['actions']),
        ];
    }
}
