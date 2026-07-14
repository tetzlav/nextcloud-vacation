<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001022Date20260713130000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('vacation_mail_queue')) {
            return null;
        }

        $table = $schema->createTable('vacation_mail_queue');
        $table->addColumn('id', 'bigint', [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('recipient_email', 'string', [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('recipient_name', 'string', [
            'notnull' => false,
            'length' => 255,
        ]);
        $table->addColumn('subject', 'string', [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('body', 'text', [
            'notnull' => true,
        ]);
        $table->addColumn('kind', 'string', [
            'notnull' => true,
            'length' => 32,
            'default' => 'employee',
        ]);
        $table->addColumn('attempts', 'integer', [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('created_at', 'integer', [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('sent_at', 'integer', [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('last_error', 'text', [
            'notnull' => false,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['sent_at', 'attempts'], 'vac_mail_pending');

        return $schema;
    }
}
