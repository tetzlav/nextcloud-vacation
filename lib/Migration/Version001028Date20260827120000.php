<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001028Date20260827120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('vacation_special_leave')) {
            return null;
        }

        $table = $schema->getTable('vacation_special_leave');
        if ($table->hasColumn('corrects_id')) {
            return null;
        }

        $table->addColumn('corrects_id', 'bigint', [
            'notnull' => false,
            'unsigned' => true,
        ]);
        $table->addIndex(['corrects_id'], 'vac_special_corrects');

        return $schema;
    }
}
