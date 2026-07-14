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

class ResetAutoApprovalWaitCommand extends Command
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
            ->setName('vacation:reset-auto-approval-wait')
            ->setDescription('Reset accidental manual approvals so configured automatic approval waits again')
            ->addArgument('year', InputArgument::REQUIRED, 'Year to repair')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User ID whose approvals should be reset')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Calendar manager user ID performing the repair')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Reset without an interactive confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $year = $this->year((string)$input->getArgument('year'), $output);
        if ($year === null) {
            return self::INVALID;
        }

        $userId = trim((string)$input->getOption('user'));
        if ($userId === '') {
            $output->writeln('<error>--user is required.</error>');
            return self::INVALID;
        }

        $actor = trim((string)$input->getOption('actor'));
        if ($actor === '' || !$this->reportService->isCalendarAdmin($actor)) {
            $output->writeln('<error>--actor must name a user in a configured calendar manager group.</error>');
            return self::FAILURE;
        }

        $reason = $this->approvalService->autoApprovalReasonForUser($userId);
        if ($reason === null) {
            $output->writeln('<error>The selected user is not covered by the configured auto-approval users or groups.</error>');
            return self::FAILURE;
        }

        if (!$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Use --yes for non-interactive execution.</error>');
                return self::INVALID;
            }

            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $confirmed = $helper->ask($input, $output, new ConfirmationQuestion(sprintf(
                'Reset all manual approvals for %s in %d and restart automatic stabilization (%s)? [y/N] ',
                $userId,
                $year,
                $reason
            ), false));
            if (!$confirmed) {
                $output->writeln('No approvals changed.');
                return self::SUCCESS;
            }
        }

        $updated = $this->approvalService->resetManualApprovalsForAutoApprovalWait($year, $userId, $actor);
        $output->writeln(sprintf(
            '<info>%d approval%s reset to stabilization. No emails were queued.</info>',
            $updated,
            $updated === 1 ? '' : 's'
        ));

        return self::SUCCESS;
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
