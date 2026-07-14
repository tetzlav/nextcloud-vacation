<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Migration;

use Closure;
use OCA\NextcloudVacation\Service\VacationRevisionService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001025Date20260714150000 extends SimpleMigrationStep
{
    public function __construct(private IDBConnection $db)
    {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('vacation_requests')) {
            $requests = $schema->getTable('vacation_requests');
            if (!$requests->hasColumn('current_revision')) {
                $requests->addColumn('current_revision', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
            }
        }

        if (!$schema->hasTable('vacation_request_revisions')) {
            $table = $schema->createTable('vacation_request_revisions');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('request_id', 'bigint', [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('revision', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('snapshot_json', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('snapshot_hash', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('approved_by', 'string', [
                'notnull' => false,
                'length' => 128,
            ]);
            $table->addColumn('approved_at', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('created_at', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['request_id', 'revision'], 'vac_rev_request_revision');
            $table->addIndex(['snapshot_hash'], 'vac_rev_snapshot_hash');
            $changed = true;
        }

        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_requests')
            ->where($qb->expr()->gt('approved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('current_revision', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $requests = [];
        try {
            while (($request = $result->fetch()) !== false) {
                $requests[] = $request;
            }
        } finally {
            $result->closeCursor();
        }

        foreach ($requests as $request) {
            $approvedSnapshot = $request;
            $approvedSnapshot['status'] = 'approved';
            $snapshotJson = VacationRevisionService::encodeSnapshot(
                VacationRevisionService::snapshotFromRequest($approvedSnapshot)
            );
            $requestId = (int)$request['id'];
            $approvedAt = (int)$request['approved_at'];

            $check = $this->db->getQueryBuilder();
            $check->select('id')
                ->from('vacation_request_revisions')
                ->where($check->expr()->eq('request_id', $check->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
                ->andWhere($check->expr()->eq('revision', $check->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);
            $existingResult = $check->executeQuery();
            try {
                $revisionExists = $existingResult->fetchOne() !== false;
            } finally {
                $existingResult->closeCursor();
            }

            if (!$revisionExists) {
                $insert = $this->db->getQueryBuilder();
                $insert->insert('vacation_request_revisions')->values([
                    'request_id' => $insert->createNamedParameter($requestId, IQueryBuilder::PARAM_INT),
                    'revision' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                    'snapshot_json' => $insert->createNamedParameter($snapshotJson),
                    'snapshot_hash' => $insert->createNamedParameter(hash('sha256', $snapshotJson)),
                    'approved_by' => $insert->createNamedParameter($request['approved_by'] ?? null),
                    'approved_at' => $insert->createNamedParameter($approvedAt, IQueryBuilder::PARAM_INT),
                    'created_at' => $insert->createNamedParameter($approvedAt, IQueryBuilder::PARAM_INT),
                ]);
                $insert->executeStatement();
            }

            $update = $this->db->getQueryBuilder();
            $update->update('vacation_requests')
                ->set('current_revision', $update->createNamedParameter(1, IQueryBuilder::PARAM_INT))
                ->where($update->expr()->eq('id', $update->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
            $update->executeStatement();
        }
    }
}
