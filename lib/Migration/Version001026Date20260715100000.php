<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001026Date20260715100000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('vacation_special_leave')) {
            return null;
        }

        $table = $schema->createTable('vacation_special_leave');
        $table->addColumn('id', 'bigint', [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('user_id', 'string', [
            'notnull' => true,
            'length' => 128,
        ]);
        $table->addColumn('year', 'integer', [
            'notnull' => true,
        ]);
        $table->addColumn('amount_hundredths', 'integer', [
            'notnull' => true,
        ]);
        $table->addColumn('reason', 'string', [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('granted_by', 'string', [
            'notnull' => true,
            'length' => 128,
        ]);
        $table->addColumn('granted_at', 'integer', [
            'notnull' => true,
        ]);
        $table->addColumn('previous_hash', 'string', [
            'notnull' => true,
            'default' => '',
            'length' => 64,
        ]);
        $table->addColumn('entry_hash', 'string', [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id', 'year', 'id'], 'vac_special_user_year');
        $table->addIndex(['entry_hash'], 'vac_special_hash');

        return $schema;
    }
}
