<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Service\Operations\Backup\BackupService;

final readonly class BackupAction
{
    public function __construct(private BackupService $backups)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        try {
            $payload = $this->backups->list(
                isset($query['job_type']) ? (string) $query['job_type'] : null,
                $this->integer($query['limit'] ?? 50, 'limit'),
                $this->integer($query['offset'] ?? 0, 'offset'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, 'invalid_request', $exception->getMessage(), 422);
        }

        return OperationsJson::write($response, $payload);
    }

    /** @param array<string, string> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            return OperationsJson::write($response, $this->backups->get($args['job_id'] ?? ''));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'backup_job_not_found') {
                return $this->error($response, 'backup_job_not_found', 'Backup job was not found.', 404);
            }

            throw $exception;
        }
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        try {
            $job = $this->backups->queueBackup(
                $context->user?->id ?? 0,
                RequestIdContext::fromRequest($request) ?? '',
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, 'invalid_request', $exception->getMessage(), 422);
        }

        return OperationsJson::write($response, $this->backups->serialize($job), 202);
    }

    /** @param array<string, string> $args */
    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $context = RequestUserContext::fromRequest($request);
        try {
            $job = $this->backups->queueRestore(
                (string) ($args['job_id'] ?? ''),
                (string) ($payload['confirmation'] ?? ''),
                (string) ($payload['reason'] ?? ''),
                $context->user?->id ?? 0,
                RequestIdContext::fromRequest($request) ?? '',
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, 'invalid_request', $exception->getMessage(), 422);
        } catch (RuntimeException $exception) {
            return match ($exception->getMessage()) {
                'backup_not_found' => $this->error($response, 'backup_not_found', 'Backup was not found.', 404),
                'backup_not_restorable' => $this->error($response, 'backup_not_restorable', 'Backup is not completed and restorable.', 409),
                'restore_already_queued' => $this->error($response, 'restore_already_queued', 'A restore is already queued for this backup.', 409),
                'Backup restore is not allowed in the current environment.' =>
                    $this->error($response, 'restore_environment_forbidden', $exception->getMessage(), 403),
                default => throw $exception,
            };
        }

        return OperationsJson::write($response, $this->backups->serialize($job), 202);
    }

    private function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException($field . ' must be an integer.');
        }

        return (int) $value;
    }

    private function error(ResponseInterface $response, string $code, string $message, int $status): ResponseInterface
    {
        return OperationsJson::write($response, ['code' => $code, 'message' => $message], $status);
    }
}
