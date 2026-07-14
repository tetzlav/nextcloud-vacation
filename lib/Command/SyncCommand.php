<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Command;

use OCA\NextcloudVacation\Service\ApprovalService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command
{
    public function __construct(private ApprovalService $approvalService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('vacation:sync')
            ->setDescription('Synchronize vacation requests with the calendar data')
            ->addArgument('year', InputArgument::OPTIONAL, 'Year to synchronize');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $yearValue = $input->getArgument('year');
        if ($yearValue !== null && !$this->validYear((string)$yearValue)) {
            $output->writeln('<error>Year must be between 2000 and 2100.</error>');
            return self::INVALID;
        }

        $years = $yearValue === null ? null : [(int)$yearValue];
        $this->approvalService->syncOpenRequests($years);
        $output->writeln($yearValue === null
            ? '<info>Current and next year synchronized.</info>'
            : sprintf('<info>Year %d synchronized.</info>', (int)$yearValue));
        $output->writeln('Generated notifications remain in the mail queue until the background job sends them.');

        return self::SUCCESS;
    }

    private function validYear(string $year): bool
    {
        return preg_match('/^\d{4}$/', $year) === 1 && (int)$year >= 2000 && (int)$year <= 2100;
    }
}
