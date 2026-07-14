<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001019Date20260710190000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('vacation_requests')) {
            $table = $schema->getTable('vacation_requests');

            if (!$table->hasColumn('rejected_by')) {
                $table->addColumn('rejected_by', 'string', [
                    'notnull' => false,
                    'length' => 128,
                ]);
                $changed = true;
            }

            if (!$table->hasColumn('rejected_at')) {
                $table->addColumn('rejected_at', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
            }

            if (!$table->hasColumn('rejection_reason')) {
                $table->addColumn('rejection_reason', 'text', [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
