<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LazyContainerMiddleware implements MiddlewareInterface
{
    /** @var array<string, bool> */
    private array $enabledEndpoints;

    /**
     * @param list<string> $enabledEndpoints
     */
    public function __construct(
        private ContainerInterface $container,
        private string $middlewareId,
        array $enabledEndpoints = [],
    ) {
        $this->enabledEndpoints = array_fill_keys(array_map(
            static fn (string $endpoint): string => strtoupper(trim($endpoint)),
            $enabledEndpoints,
        ), true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->enabledEndpoints !== [] && !$this->shouldResolve($request)) {
            return $handler->handle($request);
        }

        $middleware = $this->container->get($this->middlewareId);
        if (!$middleware instanceof MiddlewareInterface) {
            throw new \RuntimeException($this->middlewareId . ' must resolve to a PSR-15 middleware.');
        }

        return $middleware->process($request, $handler);
    }

    private function shouldResolve(ServerRequestInterface $request): bool
    {
        $path = '/' . ltrim($request->getUri()->getPath(), '/');
        $key = strtoupper($request->getMethod()) . ':' . $path;

        return isset($this->enabledEndpoints[$key]);
    }
}
