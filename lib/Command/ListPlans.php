<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Command;

use OCA\ServicePlanner\Service\PlanService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListPlans extends Command {
    public function __construct(private PlanService $plans) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('serviceplanner:list')->setDescription('List ServicePlanner plans');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $rows = array_map(static fn (array $plan): array => [
            $plan['id'], $plan['name'], $plan['tableId'], $plan['icon'], $plan['color'],
        ], $this->plans->list());
        $output->writeln(['ID | Name | Tables ID | Icon | Color']);
        foreach ($rows as $row) {
            $output->writeln(implode(' | ', array_map('strval', $row)));
        }
        return Command::SUCCESS;
    }
}
