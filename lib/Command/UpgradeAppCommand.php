<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Command;

use OCA\NextcloudVacation\AppInfo\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpgradeAppCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('vacation:upgrade-app')
            ->setDescription('Run the Nextcloud update process only for the Vacation app');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Updating <%s> ...', Application::APP_ID));
        \OC_App::updateApp(Application::APP_ID);
        $output->writeln(sprintf('<info>Updated <%s>.</info>', Application::APP_ID));

        return self::SUCCESS;
    }
}
