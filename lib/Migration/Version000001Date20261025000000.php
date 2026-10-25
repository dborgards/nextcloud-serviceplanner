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
