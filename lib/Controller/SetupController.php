<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Controller;

use DomainException;
use InvalidArgumentException;
use OCA\ServicePlanner\AppInfo\Application;
use OCA\ServicePlanner\Service\PlanSetupService;
use OCA\ServicePlanner\Service\SetupException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class SetupController extends Controller {
    public function __construct(IRequest $request, private PlanSetupService $setup, private ?string $userId) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function tables(): JSONResponse {
        return $this->run(fn (): array => $this->setup->listTables($this->userId));
    }

    #[NoAdminRequired]
    public function createTable(): JSONResponse {
        return $this->run(function (): array {
            $input = $this->request->getParams();
            return ['table' => $this->setup->createTable(
                $this->stringValue($input, 'title'),
                $this->stringValue($input, 'emoji'),
                $this->stringValue($input, 'description'),
                $this->stringValue($input, 'template'),
                $this->userId,
            )];
        }, 201);
    }

    #[NoAdminRequired]
    public function table(string $tableId): JSONResponse {
        return $this->run(fn (): array => ['table' => $this->setup->table((int)$tableId, $this->userId)]);
    }

    #[NoAdminRequired]
    public function groups(): JSONResponse {
        return $this->run(fn (): array => ['groups' => $this->setup->groups()]);
    }

    #[NoAdminRequired]
    public function plans(): JSONResponse {
        return $this->run(fn (): array => ['plans' => $this->setup->list($this->userId)]);
    }

    #[NoAdminRequired]
    public function createPlan(): JSONResponse {
        return $this->run(fn (): array => [
            'plan' => $this->setup->create($this->definition(), $this->userId),
        ], 201);
    }

    #[NoAdminRequired]
    public function plan(string $id): JSONResponse {
        return $this->run(fn (): array => $this->setup->get((int)$id, $this->userId));
    }

    #[NoAdminRequired]
    public function updatePlan(string $id): JSONResponse {
        return $this->run(function () use ($id): array {
            $input = $this->request->getParams();
            $definition = $this->definition($input);
            if (!array_key_exists('expectedRevision', $input) || !is_int($input['expectedRevision'])) {
                throw new InvalidArgumentException('expectedRevision must be an integer.');
            }
            return ['plan' => $this->setup->update((int)$id, $input['expectedRevision'], $definition, $this->userId)];
        });
    }

    #[NoAdminRequired]
    public function deletePlan(string $id): JSONResponse {
        return $this->run(function () use ($id): array {
            $this->setup->delete((int)$id, $this->userId);
            return ['deleted' => true];
        });
    }

    #[NoAdminRequired]
    public function validate(): JSONResponse {
        return $this->run(fn (): array => $this->setup->validate($this->definition(), $this->userId));
    }

    #[NoAdminRequired]
    public function preview(): JSONResponse {
        return $this->run(fn (): array => $this->setup->preview($this->definition(), $this->userId));
    }

    private function run(callable $action, int $successStatus = 200): JSONResponse {
        if ($this->userId === null || $this->userId === '') {
            return new JSONResponse(['error' => 'Authentication required.'], 401);
        }
        try {
            return new JSONResponse($action(), $successStatus);
        } catch (SetupException $error) {
            $response = ['error' => $error->getMessage()];
            if ($error->getIssues() !== []) {
                $response['issues'] = $error->getIssues();
            }
            return new JSONResponse($response, $error->getStatus());
        } catch (InvalidArgumentException $error) {
            return new JSONResponse(['error' => $error->getMessage()], 400);
        } catch (DomainException $error) {
            return new JSONResponse(['error' => $error->getMessage()], 409);
        }
    }

    private function definition(?array $input = null): array {
        $input ??= $this->request->getParams();
        if (!array_key_exists('definition', $input) || !is_array($input['definition'])) {
            throw new InvalidArgumentException('definition must be an object.');
        }
        return $input['definition'];
    }

    private function stringValue(array $input, string $key): string {
        if (!array_key_exists($key, $input) || !is_string($input[$key])) {
            throw new InvalidArgumentException("$key must be a string.");
        }
        return $input[$key];
    }
}
