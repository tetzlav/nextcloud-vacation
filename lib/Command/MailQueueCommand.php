<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Command;

use DateTimeImmutable;
use DateTimeZone;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MailQueueCommand extends Command
{
    private const STATUSES = ['pending', 'failed', 'sent', 'skipped', 'all'];

    public function __construct(private IDBConnection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('vacation:mail-queue')
            ->setDescription('Show the Nextcloud Vacation mail queue')
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by pending, failed, sent, skipped or all',
                'pending'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of rows',
                '50'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = strtolower((string)$input->getOption('status'));
        if (!in_array($status, self::STATUSES, true)) {
            $output->writeln('<error>Invalid status. Use pending, failed, sent, skipped or all.</error>');
            return self::INVALID;
        }

        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if ($limit === false) {
            $output->writeln('<error>Limit must be an integer between 1 and 1000.</error>');
            return self::INVALID;
        }

        $rows = $this->queueRows($status, $limit);
        $table = new Table($output);
        $table->setHeaders([
            'ID',
            'Status',
            'Kind',
            'Recipient',
            'Subject',
            'Attempts',
            'Created (UTC)',
            'Sent (UTC)',
            'Last error',
        ]);

        foreach ($rows as $row) {
            $table->addRow([
                (string)$row['id'],
                $this->rowStatus($row),
                (string)$row['kind'],
                (string)$row['recipient_email'],
                (string)$row['subject'],
                (string)$row['attempts'],
                $this->formatTimestamp((int)$row['created_at']),
                $this->formatTimestamp((int)$row['sent_at']),
                (string)($row['last_error'] ?? ''),
            ]);
        }

        $table->render();
        $output->writeln(sprintf('%d queue entr%s shown.', count($rows), count($rows) === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }

    private function queueRows(string $status, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'id',
            'recipient_email',
            'subject',
            'kind',
            'attempts',
            'created_at',
            'sent_at',
            'last_error'
        )
            ->from('vacation_mail_queue')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        if ($status === 'pending') {
            $qb->where($qb->expr()->eq('sent_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        } elseif ($status === 'failed') {
            $qb->where($qb->expr()->eq('sent_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->gt('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        } elseif ($status === 'sent') {
            $qb->where($qb->expr()->gt('sent_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->isNull('last_error'));
        } elseif ($status === 'skipped') {
            $qb->where($qb->expr()->gt('sent_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->isNotNull('last_error'));
        }

        $result = $qb->executeQuery();
        try {
            $rows = [];
            while (($row = $result->fetch()) !== false) {
                $rows[] = $row;
            }
            return $rows;
        } finally {
            $result->closeCursor();
        }
    }

    private function rowStatus(array $row): string
    {
        if ((int)$row['sent_at'] > 0) {
            return empty($row['last_error']) ? 'sent' : 'skipped';
        }

        return (int)$row['attempts'] > 0 ? 'failed' : 'pending';
    }

    private function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '-';
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
