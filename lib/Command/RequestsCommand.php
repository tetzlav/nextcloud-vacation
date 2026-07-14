<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Command;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NextcloudVacation\Service\ApprovalService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RequestsCommand extends Command
{
    private const TYPES = ['open', 'cancellations', 'all'];
    private const OPEN_STATUSES = [
        ApprovalService::STATUS_PENDING_DETECTION,
        ApprovalService::STATUS_PENDING_APPROVAL,
        ApprovalService::STATUS_CHANGED_AFTER_APPROVAL,
    ];

    public function __construct(private ApprovalService $approvalService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('vacation:requests')
            ->setDescription('Show vacation approval requests for a year')
            ->addArgument('year', InputArgument::REQUIRED, 'Year to show')
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Show open requests, cancellations or all requests',
                'open'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $yearValue = (string)$input->getArgument('year');
        $type = strtolower((string)$input->getOption('type'));
        if (!$this->validYear($yearValue)) {
            $output->writeln('<error>Year must be between 2000 and 2100.</error>');
            return self::INVALID;
        }
        if (!in_array($type, self::TYPES, true)) {
            $output->writeln('<error>Type must be open, cancellations or all.</error>');
            return self::INVALID;
        }

        $requests = array_values(array_filter(
            $this->approvalService->requestsForYear((int)$yearValue),
            fn (array $request): bool => $this->matchesType((string)$request['status'], $type)
        ));
        usort($requests, static fn (array $a, array $b): int => [
            (string)$a['date_start'],
            (string)$a['user_id'],
            (int)$a['id'],
        ] <=> [
            (string)$b['date_start'],
            (string)$b['user_id'],
            (int)$b['id'],
        ]);

        $table = new Table($output);
        $table->setHeaders(['ID', 'User', 'Period', 'Days', 'Status', 'Updated (UTC)']);
        foreach ($requests as $request) {
            $table->addRow([
                (string)$request['id'],
                (string)$request['user_id'],
                $this->period((string)$request['date_start'], (string)$request['date_end']),
                $this->days($request),
                (string)$request['status'],
                $this->timestamp((int)$request['updated_at']),
            ]);
        }
        $table->render();
        $output->writeln(sprintf('%d request%s shown.', count($requests), count($requests) === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    private function matchesType(string $status, string $type): bool
    {
        if ($type === 'all') {
            return true;
        }
        if ($type === 'cancellations') {
            return $status === ApprovalService::STATUS_CANCELLATION_PENDING;
        }
        return in_array($status, self::OPEN_STATUSES, true);
    }

    private function period(string $start, string $end): string
    {
        return $start === $end ? $start : $start . ' - ' . $end;
    }

    private function days(array $request): string
    {
        $hundredths = isset($request['days_count_hundredths'])
            ? (int)$request['days_count_hundredths']
            : ((int)$request['days_count'] * 100);
        return rtrim(rtrim(number_format($hundredths / 100, 2, '.', ''), '0'), '.');
    }

    private function timestamp(int $timestamp): string
    {
        return $timestamp > 0
            ? (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            : '-';
    }

    private function validYear(string $year): bool
    {
        return preg_match('/^\d{4}$/', $year) === 1 && (int)$year >= 2000 && (int)$year <= 2100;
    }
}
