<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001018Date20260710170000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('vacation_carryovers')) {
            $table = $schema->createTable('vacation_carryovers');
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
                'default' => 0,
            ]);
            $table->addColumn('updated_by', 'string', [
                'notnull' => false,
                'length' => 128,
            ]);
            $table->addColumn('updated_at', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'year'], 'vac_carry_user_year');
            $changed = true;
        }

        if ($schema->hasTable('vacation_requests')) {
            $table = $schema->getTable('vacation_requests');
            if (!$table->hasColumn('days_count_hundredths')) {
                $table->addColumn('days_count_hundredths', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}