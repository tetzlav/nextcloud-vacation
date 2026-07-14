<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001023Date20260713140000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('vacation_mail_queue')) {
            return null;
        }

        $table = $schema->getTable('vacation_mail_queue');
        if ($table->hasColumn('kind')) {
            return null;
        }

        $table->addColumn('kind', 'string', [
            'notnull' => true,
            'length' => 32,
            'default' => 'employee',
        ]);

        return $schema;
    }
}
