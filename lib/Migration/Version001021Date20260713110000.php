<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001021Date20260713110000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('vacation_requests')) {
            return null;
        }

        $table = $schema->getTable('vacation_requests');
        if ($table->hasColumn('source_key')) {
            return null;
        }

        $table->addColumn('source_key', 'string', [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addIndex(['user_id', 'year', 'source_key'], 'vac_req_user_src');

        return $schema;
    }
}
