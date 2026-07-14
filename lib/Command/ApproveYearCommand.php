<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Command;

use OCA\NextcloudVacation\Service\ApprovalService;
use OCA\NextcloudVacation\Service\VacationReportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ApproveYearCommand extends Command
{
    public function __construct(
        private ApprovalService $approvalService,
        private VacationReportService $reportService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('vacation:approve-year')
            ->setDescription('Approve all open vacation requests for a year')
            ->addArgument('year', InputArgument::REQUIRED, 'Year to approve')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Calendar manager user ID')
            ->addOption('no-notify', null, InputOption::VALUE_NONE, 'Do not queue employee notification emails')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Approve without an interactive confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $year = $this->year((string)$input->getArgument('year'), $output);
        if ($year === null) {
            return self::INVALID;
        }
        $actor = trim((string)$input->getOption('actor'));
        if ($actor === '' || !$this->reportService->isCalendarAdmin($actor)) {
            $output->writeln('<error>--actor must name a user in a configured calendar manager group.</error>');
            return self::FAILURE;
        }
        $notify = !$input->getOption('no-notify');

        if (!$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Use --yes for non-interactive execution.</error>');
                return self::INVALID;
            }
            if (!$this->confirm($input, $output, sprintf(
                'Approve every open vacation request for %d as %s%s? [y/N] ',
                $year,
                $actor,
                $notify ? ' and queue employee notifications' : ' without employee notifications'
            ))) {
                $output->writeln('No requests changed.');
                return self::SUCCESS;
            }
        }

        $updated = $this->approvalService->approveOpenRequestsForYear($year, $actor, $notify);
        $output->writeln(sprintf('<info>%d request%s approved.</info>', $updated, $updated === 1 ? '' : 's'));
        if ($notify && $updated > 0) {
            $output->writeln('Employee notifications were queued where enabled and an email address is available.');
        }
        return self::SUCCESS;
    }

    private function confirm(InputInterface $input, OutputInterface $output, string $question): bool
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        return $helper->ask($input, $output, new ConfirmationQuestion($question, false));
    }

    private function year(string $value, OutputInterface $output): ?int
    {
        if (preg_match('/^\d{4}$/', $value) !== 1 || (int)$value < 2000 || (int)$value > 2100) {
            $output->writeln('<error>Year must be between 2000 and 2100.</error>');
            return null;
        }
        return (int)$value;
    }
}
