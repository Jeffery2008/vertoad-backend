<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Reporting;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Reporting\ReportQueryService;

final readonly class ReportDashboardAction
{
    private const RFC3339_DATE_TIME_PATTERN = '/\A(?<year>\d{4})-(?<month>0[1-9]|1[0-2])-(?<day>0[1-9]|[12]\d|3[01])T(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d):(?<second>[0-5]\d)(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/';

    public function __construct(private ReportQueryService $reports)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $payload = $this->reports->dashboard($this->filters($request));
        } catch (DateMalformedStringException | InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return $this->json($response, $payload, 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $filters = [
            'portal' => 'admin',
            'granularity' => 'day',
        ];

        if (array_key_exists('portal', $query) && $query['portal'] !== '' && $query['portal'] !== null) {
            if (!is_scalar($query['portal']) || !in_array((string) $query['portal'], ['admin', 'advertiser', 'publisher'], true)) {
                throw new InvalidArgumentException('portal must be admin, advertiser, or publisher.');
            }

            $filters['portal'] = (string) $query['portal'];
        }

        if (array_key_exists('granularity', $query) && $query['granularity'] !== '' && $query['granularity'] !== null) {
            if (!is_scalar($query['granularity']) || !in_array((string) $query['granularity'], ['hour', 'day'], true)) {
                throw new InvalidArgumentException('granularity must be hour or day.');
            }

            $filters['granularity'] = (string) $query['granularity'];
        }

        foreach (['organization_id', 'campaign_id', 'site_id', 'slot_id'] as $field) {
            if (!array_key_exists($field, $query) || $query[$field] === '' || $query[$field] === null) {
                continue;
            }

            if (!is_scalar($query[$field]) || filter_var((string) $query[$field], FILTER_VALIDATE_INT) === false || (int) $query[$field] <= 0) {
                throw new InvalidArgumentException($field . ' must be a positive integer.');
            }

            $filters[$field] = (int) $query[$field];
        }

        foreach (['from', 'to'] as $field) {
            if (!array_key_exists($field, $query) || $query[$field] === '' || $query[$field] === null) {
                continue;
            }

            $filters[$field] = $this->rfc3339DateTime($field, $query[$field]);
        }

        if (isset($filters['from'], $filters['to']) && $filters['from'] >= $filters['to']) {
            throw new InvalidArgumentException('from must be earlier than to.');
        }

        return $filters;
    }

    private function rfc3339DateTime(string $field, mixed $value): DateTimeImmutable
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException($field . ' must be an RFC3339 date-time string.');
        }

        $dateTime = (string) $value;
        if (preg_match(self::RFC3339_DATE_TIME_PATTERN, $dateTime, $matches) !== 1
            || !checkdate((int) $matches['month'], (int) $matches['day'], (int) $matches['year'])
        ) {
            throw new InvalidArgumentException($field . ' must be an RFC3339 date-time string.');
        }

        return new DateTimeImmutable($dateTime);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
