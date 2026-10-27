<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Service;

use Exception;
use Throwable;

final class SetupException extends Exception {
	public function __construct(string $message, private int $status = 400, private array $issues = [], ?Throwable $previous = null, private ?string $errorCode = null) {
		parent::__construct($message, 0, $previous);
	}

	public function getStatus(): int {
		return $this->status;
	}

	public function getIssues(): array {
		return $this->issues;
	}

	public function getErrorCode(): ?string {
		return $this->errorCode;
	}
}
