<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001017Date20260710150000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('vacation_requests')) {
            return null;
        }

        $table = $schema->createTable('vacation_requests');
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
        $table->addColumn('fingerprint', 'string', [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('summary', 'string', [
            'notnull' => true,
            'length' => 255,
            'default' => '',
        ]);
        $table->addColumn('date_start', 'string', [
            'notnull' => true,
            'length' => 10,
        ]);
        $table->addColumn('date_end', 'string', [
            'notnull' => true,
            'length' => 10,
        ]);
        $table->addColumn('days_count', 'integer', [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('day_list_json', 'text', [
            'notnull' => false,
        ]);
        $table->addColumn('status', 'string', [
            'notnull' => true,
            'length' => 32,
            'default' => 'pending_detection',
        ]);
        $table->addColumn('first_seen_at', 'integer', [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('last_seen_at', 'integer', [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('notified_at', 'integer', [
            'notnull' => false,
        ]);
        $table->addColumn('approved_by', 'string', [
            'notnull' => false,
            'length' => 128,
        ]);
        $table->addColumn('approved_at', 'integer', [
            'notnull' => false,
        ]);
        $table->addColumn('updated_at', 'integer', [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['fingerprint'], 'vac_req_fingerprint');
        $table->addIndex(['user_id', 'year'], 'vac_req_user_year');
        $table->addIndex(['status'], 'vac_req_status');

        return $schema;
    }
}