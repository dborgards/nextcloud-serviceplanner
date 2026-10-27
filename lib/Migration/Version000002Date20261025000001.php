<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000002Date20261025000001 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('serviceplanner_plans')) {
            return null;
        }
        $table = $schema->getTable('serviceplanner_plans');
        if ($table->hasColumn('revision')) {
            return null;
        }
        $table->addColumn('revision', 'integer', ['notnull' => true, 'unsigned' => true, 'default' => 1]);
        return $schema;
    }
}
