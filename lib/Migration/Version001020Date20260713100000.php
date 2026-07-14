<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001020Date20260713100000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('vacation_entitlements')) {
            $table = $schema->createTable('vacation_entitlements');
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
            $table->addUniqueIndex(['user_id', 'year'], 'vac_ent_user_year');
            $changed = true;
        }

        if ($schema->hasTable('vacation_requests')) {
            $table = $schema->getTable('vacation_requests');

            if (!$table->hasColumn('auto_approved')) {
                $table->addColumn('auto_approved', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
            }

            if (!$table->hasColumn('auto_approval_reason')) {
                $table->addColumn('auto_approval_reason', 'string', [
                    'notnull' => false,
                    'length' => 255,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
