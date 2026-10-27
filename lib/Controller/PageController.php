<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Controller;

use OCA\ServicePlanner\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

final class PageController extends Controller {
    public function __construct(IRequest $request) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI(OpenAPI::SCOPE_IGNORE)]
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'serviceplanner-main');
        Util::addStyle(Application::APP_ID, 'serviceplanner-main');
        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
