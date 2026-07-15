<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001027Date20260715160000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('vacation_approvers')) {
            return null;
        }

        $table = $schema->createTable('vacation_approvers');
        $table->addColumn('id', 'bigint', [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('employee_id', 'string', [
            'notnull' => true,
            'length' => 128,
        ]);
        $table->addColumn('approver_id', 'string', [
            'notnull' => true,
            'length' => 128,
        ]);
        $table->addColumn('assigned_by', 'string', [
            'notnull' => true,
            'length' => 128,
        ]);
        $table->addColumn('assigned_at', 'integer', [
            'notnull' => true,
        ]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['employee_id'], 'vac_approver_employee');
        $table->addIndex(['approver_id'], 'vac_approver_user');

        return $schema;
    }
}
