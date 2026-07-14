<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001024Date20260713170000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('vacation_request_audit')) {
            return null;
        }

        $table = $schema->createTable('vacation_request_audit');
        $table->addColumn('id', 'bigint', [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('request_id', 'bigint', [
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('action', 'string', [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('actor_id', 'string', [
            'notnull' => false,
            'length' => 128,
        ]);
        $table->addColumn('reason', 'text', [
            'notnull' => false,
        ]);
        $table->addColumn('created_at', 'integer', [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['request_id', 'created_at'], 'vac_req_audit_request');

        return $schema;
    }
}
