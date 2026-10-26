<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\AppInfo;

use OCA\ServicePlanner\Repository\DatabasePlanRepository;
use OCA\ServicePlanner\Repository\PlanRepository;
use OCA\ServicePlanner\Service\Groups\GroupDirectory;
use OCA\ServicePlanner\Service\Groups\NextcloudGroupDirectory;
use OCA\ServicePlanner\Service\Tables\TablesAdapter;
use OCA\ServicePlanner\Service\Tables\TablesGateway;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Server;

final class Application extends App implements IBootstrap {
    public const APP_ID = 'serviceplanner';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerService(PlanRepository::class, static fn () => Server::get(DatabasePlanRepository::class));
        $context->registerService(TablesGateway::class, static fn () => Server::get(TablesAdapter::class));
        $context->registerService(GroupDirectory::class, static fn () => Server::get(NextcloudGroupDirectory::class));
    }

    public function boot(IBootContext $context): void {
    }
}
