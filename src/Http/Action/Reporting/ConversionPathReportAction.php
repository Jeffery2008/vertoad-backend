<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Reporting;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Reporting\ConversionPathReportService;

final readonly class ConversionPathReportAction
{
    private const RFC3339_DATE_TIME_PATTERN = '/\A(?<year>\d{4})-(?<month>0[1-9]|1[0-2])-(?<day>0[1-9]|[12]\d|3[01])T(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d):(?<second>[0-5]\d)(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/';

    public function __construct(private ConversionPathReportService $reports)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $payload = $this->reports->conversionPaths($this->filters($request));
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
            'portal' => 'advertiser',
            'limit' => 20,
            'max_touchpoints' => 10,
        ];

        if (array_key_exists('portal', $query) && $query['portal'] !== '' && $query['portal'] !== null) {
            if (!is_scalar($query['portal']) || !in_array((string) $query['portal'], ['advertiser', 'publisher'], true)) {
                throw new InvalidArgumentException('portal must be advertiser or publisher.');
            }

            $filters['portal'] = (string) $query['portal'];
        }

        foreach (['organization_id', 'campaign_id', 'site_id', 'slot_id'] as $field) {
            if (!array_key_exists($field, $query) || $query[$field] === '' || $query[$field] === null) {
                continue;
            }

            $filters[$field] = $this->positiveInteger($field, $query[$field]);
        }

        $contextOrganizationId = RequestUserContext::fromRequest($request)->organizationId;
        if ($contextOrganizationId !== null) {
            if (isset($filters['organization_id']) && $filters['organization_id'] !== $contextOrganizationId) {
                throw new InvalidArgumentException('organization_id must match the authenticated organization scope.');
            }

            $filters['organization_id'] = $contextOrganizationId;
        }

        foreach (['from', 'to'] as $field) {
            if (!array_key_exists($field, $query) || $query[$field] === '' || $query[$field] === null) {
                continue;
            }

            $filters[$field] = $this->rfc3339DateTime($field, $query[$field]);
        }

        foreach ([
            'limit' => [1, 100],
            'max_touchpoints' => [1, 25],
        ] as $field => [$minimum, $maximum]) {
            if (!array_key_exists($field, $query) || $query[$field] === '' || $query[$field] === null) {
                continue;
            }

            $filters[$field] = $this->boundedInteger($field, $query[$field], $minimum, $maximum);
        }

        if (isset($filters['from'], $filters['to']) && $filters['from'] >= $filters['to']) {
            throw new InvalidArgumentException('from must be earlier than to.');
        }

        return $filters;
    }

    private function positiveInteger(string $field, mixed $value): int
    {
        if (!is_scalar($value) || filter_var((string) $value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }

        return (int) $value;
    }

    private function boundedInteger(string $field, mixed $value, int $minimum, int $maximum): int
    {
        if (!is_scalar($value) || filter_var((string) $value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException($field . ' must be between ' . $minimum . ' and ' . $maximum . '.');
        }

        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException($field . ' must be between ' . $minimum . ' and ' . $maximum . '.');
        }

        return $integer;
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
