<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Command;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use OCA\NextcloudVacation\Service\VacationRevisionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RequestCommand extends Command
{
    public function __construct(private VacationRevisionService $revisionService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('vacation:request')
            ->setDescription('Show a vacation request, immutable approval revisions and audit history')
            ->addArgument('id', InputArgument::REQUIRED, 'Vacation request ID')
            ->addOption('revision', 'r', InputOption::VALUE_REQUIRED, 'Select a specific approval revision')
            ->addOption('compare-current', null, InputOption::VALUE_NONE, 'Compare the selected revision with the current calendar entry')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $idValue = (string)$input->getArgument('id');
        $revisionValue = $input->getOption('revision');
        if (preg_match('/^[1-9]\d*$/', $idValue) !== 1) {
            $output->writeln('<error>ID must be a positive integer.</error>');
            return self::INVALID;
        }
        if ($revisionValue !== null && preg_match('/^[1-9]\d*$/', (string)$revisionValue) !== 1) {
            $output->writeln('<error>Revision must be a positive integer.</error>');
            return self::INVALID;
        }

        $revision = $revisionValue === null ? null : (int)$revisionValue;
        $details = $this->revisionService->requestDetails(
            (int)$idValue,
            $revision,
            (bool)$input->getOption('compare-current')
        );
        if ($details === null) {
            $output->writeln('<error>Vacation request not found.</error>');
            return self::FAILURE;
        }
        if ($revision !== null && $details['selected_revision'] === null) {
            $output->writeln(sprintf('<error>Revision R%d does not exist for request #%d.</error>', $revision, (int)$idValue));
            return self::FAILURE;
        }

        if ($input->getOption('json')) {
            try {
                $output->writeln(json_encode($details, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } catch (JsonException $exception) {
                $output->writeln('<error>Could not encode request details as JSON.</error>');
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        $request = $details['request'];
        $output->writeln(sprintf('<info>Vacation request #%d</info>', (int)$request['id']));
        $table = new Table($output);
        $table->setRows([
            ['User', (string)$request['user_id']],
            ['Year', (string)$request['year']],
            ['Period', $this->period((string)$request['date_start'], (string)$request['date_end'])],
            ['Days', $this->days((int)$request['days_count_hundredths'])],
            ['Status', (string)$request['status']],
            ['Source key', (string)($request['source_key'] ?? '')],
            ['Current revision', (int)$request['current_revision'] > 0 ? 'R' . $request['current_revision'] : '-'],
            ['Updated (UTC)', $this->timestamp((int)$request['updated_at'])],
        ]);
        $table->render();

        $output->writeln('<info>Approval revisions</info>');
        $revisionTable = new Table($output);
        $revisionTable->setHeaders(['DB ID', 'Reference', 'Period', 'Days', 'Approved by', 'Approved (UTC)', 'Hash']);
        foreach ($details['revisions'] as $entry) {
            $snapshot = $entry['snapshot'];
            $revisionTable->addRow([
                (string)$entry['id'],
                sprintf('#%d-R%d', (int)$entry['request_id'], (int)$entry['revision']),
                $this->period((string)$snapshot['date_start'], (string)$snapshot['date_end']),
                $this->days((int)$snapshot['days_count_hundredths']),
                (string)($entry['approved_by'] ?? ($snapshot['auto_approved'] ? 'automatic' : '-')),
                $this->timestamp((int)$entry['approved_at']),
                ($entry['hash_valid'] ? 'OK ' : 'INVALID ') . substr((string)$entry['snapshot_hash'], 0, 12),
            ]);
        }
        $revisionTable->render();

        if ($details['comparison'] !== null) {
            $comparison = $details['comparison'];
            $output->writeln($comparison['matches']
                ? '<info>Current calendar entry matches the selected approval revision.</info>'
                : '<error>Current calendar entry differs from the selected approval revision.</error>');
            foreach ($comparison['differences'] as $field => $difference) {
                $output->writeln(sprintf(
                    '  %s: snapshot=%s current=%s',
                    $field,
                    $this->value($difference['snapshot']),
                    $this->value($difference['current'])
                ));
            }
        }

        $output->writeln('<info>Audit history</info>');
        $auditTable = new Table($output);
        $auditTable->setHeaders(['ID', 'Action', 'Actor', 'Reason', 'Created (UTC)']);
        foreach ($details['audit'] as $entry) {
            $auditTable->addRow([
                (string)$entry['id'],
                (string)$entry['action'],
                (string)($entry['actor_id'] ?? '-'),
                (string)($entry['reason'] ?? ''),
                $this->timestamp((int)$entry['created_at']),
            ]);
        }
        $auditTable->render();

        return self::SUCCESS;
    }

    private function period(string $start, string $end): string
    {
        return $start === $end ? $start : $start . ' - ' . $end;
    }

    private function days(int $hundredths): string
    {
        return rtrim(rtrim(number_format($hundredths / 100, 2, '.', ''), '0'), '.');
    }

    private function timestamp(int $timestamp): string
    {
        return $timestamp > 0
            ? (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            : '-';
    }

    private function value(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        return $value === null ? 'null' : (string)$value;
    }
}
