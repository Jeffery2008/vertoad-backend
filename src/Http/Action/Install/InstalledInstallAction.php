<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Install;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class InstalledInstallAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Installer disabled</title></head><body><main><h1>Installer disabled</h1><p>VertoAD is already installed.</p></main></body></html>');

        return $response
            ->withStatus(410)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
