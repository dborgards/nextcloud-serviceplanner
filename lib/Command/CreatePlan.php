<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Command;

use OCA\ServicePlanner\Service\PlanService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class CreatePlan extends Command {
    public function __construct(private PlanService $plans) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('serviceplanner:create')
            ->setDescription('Create a ServicePlanner plan')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Plan name')
            ->addOption('table-id', null, InputOption::VALUE_REQUIRED, 'Tables table ID')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Description', '')
            ->addOption('icon', null, InputOption::VALUE_REQUIRED, 'Icon name', 'calendar')
            ->addOption('color', null, InputOption::VALUE_REQUIRED, 'Hex color', '#0082c9');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        try {
            $plan = $this->plans->create([
                'name' => $input->getOption('name'),
                'tableId' => $input->getOption('table-id'),
                'description' => $input->getOption('description'),
                'icon' => $input->getOption('icon'),
                'color' => $input->getOption('color'),
            ]);
            $io->success('Created plan ' . $plan['id'] . ': ' . $plan['name']);
            return Command::SUCCESS;
        } catch (Throwable $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        }
    }
}
