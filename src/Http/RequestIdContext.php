<?php

declare(strict_types=1);

namespace VertoAD\Http;

use Psr\Http\Message\ServerRequestInterface;

final class RequestIdContext
{
    public const string ATTRIBUTE = 'vertoad.request_id';

    private static ?string $currentRequestId = null;
    private static ?string $deferredErrorRequestId = null;

    public static function fromRequest(ServerRequestInterface $request): ?string
    {
        $attribute = $request->getAttribute(self::ATTRIBUTE);
        if (is_string($attribute) && trim($attribute) !== '') {
            return trim($attribute);
        }

        $header = trim($request->getHeaderLine('X-Request-Id'));
        if ($header !== '') {
            return $header;
        }

        return self::$currentRequestId;
    }

    public static function ensure(ServerRequestInterface $request): string
    {
        $requestId = self::fromRequest($request) ?? self::consumeDeferredErrorRequestId() ?? bin2hex(random_bytes(16));
        self::$currentRequestId = $requestId;

        return $requestId;
    }

    public static function begin(ServerRequestInterface $request): string
    {
        $requestId = self::fromMessage($request) ?? bin2hex(random_bytes(16));
        self::$currentRequestId = $requestId;

        return $requestId;
    }

    public static function current(): ?string
    {
        return self::$currentRequestId;
    }

    public static function deferForErrorHandler(string $requestId): void
    {
        $requestId = trim($requestId);
        if ($requestId !== '') {
            self::$deferredErrorRequestId = $requestId;
        }
    }

    public static function clear(?string $requestId = null): void
    {
        if ($requestId === null) {
            self::$currentRequestId = null;
            self::$deferredErrorRequestId = null;

            return;
        }

        if (self::$currentRequestId === $requestId) {
            self::$currentRequestId = null;
        }
    }

    private static function consumeDeferredErrorRequestId(): ?string
    {
        $requestId = self::$deferredErrorRequestId;
        if ($requestId !== null) {
            self::$deferredErrorRequestId = null;
        }

        return $requestId;
    }

    private static function fromMessage(ServerRequestInterface $request): ?string
    {
        $attribute = $request->getAttribute(self::ATTRIBUTE);
        if (is_string($attribute) && trim($attribute) !== '') {
            return trim($attribute);
        }

        $header = trim($request->getHeaderLine('X-Request-Id'));

        return $header === '' ? null : $header;
    }
}
