<?php

declare(strict_types=1);

$openApiPath = $argv[1] ?? dirname(__DIR__) . '/docs/openapi.yaml';
$routesPath = $argv[2] ?? dirname(__DIR__) . '/config/routes.php';
if (!is_file($openApiPath)) {
    fwrite(STDERR, "OpenAPI file not found: {$openApiPath}\n");
    exit(1);
}

$contents = (string) file_get_contents($openApiPath);
if (function_exists('yaml_parse_file')) {
    $parser = 'ext-yaml';
    $parsed = yaml_parse_file($openApiPath);
    if (!is_array($parsed)) {
        fwrite(STDERR, "OpenAPI YAML could not be parsed.\n");
        exit(1);
    }

    if (($parsed['openapi'] ?? null) !== '3.1.0') {
        fwrite(STDERR, "OpenAPI contract must declare version 3.1.0.\n");
        exit(1);
    }

    $documentedRoutes = documentedRoutesFromParsedOpenApi($parsed);
    $operationErrors = operationErrorsFromParsedOpenApi($parsed);
    $metadataErrors = metadataErrorsFromParsedOpenApi($parsed, $contents);
} else {
    $parser = 'structural-fallback';
    $requiredPatterns = [
        'openapi: 3.1.0' => '/^openapi:\s*3\.1\.0\s*$/m',
        'SuccessEnvelope:' => '/^\s{4}SuccessEnvelope:\s*$/m',
        'ErrorEnvelope:' => '/^\s{4}ErrorEnvelope:\s*$/m',
    ];

    foreach ($requiredPatterns as $fragment => $pattern) {
        if (preg_match($pattern, $contents) !== 1) {
            fwrite(STDERR, "OpenAPI contract is missing required fragment: {$fragment}\n");
            exit(1);
        }
    }

    $documentedRoutes = documentedRoutesFromYaml($contents);
    $operationErrors = operationErrorsFromYaml($contents);
    $metadataErrors = metadataErrorsFromYaml($contents);
}

$operationErrors = array_merge($metadataErrors, $operationErrors);
$implementedSecurity = implementedRouteSecurityFromRoutesFile($routesPath);
if (function_exists('yaml_parse_file') && isset($parsed) && is_array($parsed)) {
    $operationErrors = array_merge(
        $operationErrors,
        securityErrorsFromParsedOpenApi($parsed, $implementedSecurity),
        queryParameterErrorsFromParsedOpenApi($parsed),
    );
} else {
    $operationErrors = array_merge(
        $operationErrors,
        securityErrorsFromYaml($contents, $implementedSecurity),
        queryParameterErrorsFromYaml($contents),
    );
}

if ($operationErrors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $operationErrors) . PHP_EOL);
    exit(1);
}

foreach (implementedRoutesFromRoutesFile($routesPath) as $route) {
    if (!in_array($route, $documentedRoutes, true)) {
        fwrite(STDERR, "OpenAPI contract is missing implemented route: {$route}\n");
        exit(1);
    }
}

$routeCount = count($documentedRoutes);
fwrite(STDERR, "OpenAPI contract check passed; parser={$parser}; documented_routes={$routeCount}.\n");

/**
 * @return list<string>
 */
function documentedRoutesFromParsedOpenApi(array $parsed): array
{
    $routes = [];
    foreach (($parsed['paths'] ?? []) as $path => $operations) {
        if (!is_array($operations)) {
            continue;
        }

        foreach (array_keys($operations) as $method) {
            if (in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                $routes[] = strtoupper((string) $method) . ' ' . $path;
            }
        }
    }

    return $routes;
}

/**
 * @return list<string>
 */
function operationErrorsFromParsedOpenApi(array $parsed): array
{
    $errors = [];
    foreach (($parsed['paths'] ?? []) as $path => $operations) {
        if (!is_array($operations) || !str_starts_with((string) $path, '/api/v1/')) {
            continue;
        }

        foreach ($operations as $method => $operation) {
            $method = strtolower((string) $method);
            if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) || !is_array($operation)) {
                continue;
            }

            $route = strtoupper($method) . ' ' . $path;
            foreach (['operationId', 'tags'] as $field) {
                if (!array_key_exists($field, $operation) || $operation[$field] === [] || $operation[$field] === '') {
                    $errors[] = "OpenAPI operation {$route} is missing required field: {$field}";
                }
            }

            $responses = $operation['responses'] ?? null;
            if (!is_array($responses)) {
                $errors[] = "OpenAPI operation {$route} is missing required field: responses";
                continue;
            }

            if (!hasSuccessResponse($responses)) {
                $errors[] = "OpenAPI operation {$route} is missing success response.";
            }

            if (!array_key_exists('default', $responses)) {
                $errors[] = "OpenAPI operation {$route} is missing default error response.";
            }

            foreach (requiredDocumentedResponseStatuses($responses) as $statusCode) {
                if (responseMustDeclareHtml($route, $statusCode)) {
                    if (!responseHasContentType($responses[$statusCode] ?? null, $parsed, 'text/html')) {
                        $errors[] = "OpenAPI operation {$route} response {$statusCode} must declare text/html content.";
                    }

                    continue;
                }

                if (!responseHasContentType($responses[$statusCode] ?? null, $parsed, 'application/json')) {
                    $errors[] = "OpenAPI operation {$route} response {$statusCode} must declare application/json content.";
                }
            }
        }
    }

    return $errors;
}

/**
 * @return list<string>
 */
function metadataErrorsFromParsedOpenApi(array $parsed, string $contents): array
{
    $errors = metadataPlaceholderErrors($contents);
    $tagNames = [];
    foreach (($parsed['tags'] ?? []) as $tag) {
        if (!is_array($tag) || !isset($tag['name'])) {
            continue;
        }

        $name = (string) $tag['name'];
        if (in_array($name, $tagNames, true)) {
            $errors[] = "OpenAPI tags contain duplicate name: {$name}";
            continue;
        }

        $tagNames[] = $name;
    }

    return $errors;
}

/**
 * @return list<string>
 */
function documentedRoutesFromYaml(string $contents): array
{
    $routes = [];
    foreach (operationBlocksFromYaml($contents) as $operation) {
        $routes[] = $operation['route'];
    }

    return array_values(array_unique($routes));
}

/**
 * @return list<string>
 */
function operationErrorsFromYaml(string $contents): array
{
    $errors = [];
    foreach (operationBlocksFromYaml($contents) as $operation) {
        $route = $operation['route'];
        $block = $operation['block'];

        foreach (['operationId', 'tags'] as $field) {
            if (preg_match('/^\s{6}' . preg_quote($field, '/') . ':\s*(?:\S.*)?$/m', $block) !== 1) {
                $errors[] = "OpenAPI operation {$route} is missing required field: {$field}";
            }
        }

        if (preg_match('/^\s{6}responses:\s*$/m', $block) !== 1) {
            $errors[] = "OpenAPI operation {$route} is missing required field: responses";
            continue;
        }

        if (preg_match('/^\s{8}"?2\d\d"?:\s*$/m', $block) !== 1) {
            $errors[] = "OpenAPI operation {$route} is missing success response.";
        }

        if (preg_match('/^\s{8}default:\s*$/m', $block) !== 1) {
            $errors[] = "OpenAPI operation {$route} is missing default error response.";
        }

        foreach (requiredDocumentedResponseStatusesFromYamlBlock($block) as $statusCode) {
            if (responseMustDeclareHtml($route, $statusCode)) {
                if (!yamlResponseHasContentType($contents, $block, $statusCode, 'text/html')) {
                    $errors[] = "OpenAPI operation {$route} response {$statusCode} must declare text/html content.";
                }

                continue;
            }

            if (!yamlResponseHasContentType($contents, $block, $statusCode, 'application/json')) {
                $errors[] = "OpenAPI operation {$route} response {$statusCode} must declare application/json content.";
            }
        }
    }

    return $errors;
}

/**
 * @return list<string>
 */
function metadataErrorsFromYaml(string $contents): array
{
    $errors = metadataPlaceholderErrors($contents);
    $tagNames = [];

    if (preg_match_all('/^\s{2}-\s+name:\s*([^\r\n]+)\s*$/m', $contents, $matches) === false) {
        return $errors;
    }

    foreach ($matches[1] as $rawName) {
        $name = trim($rawName, " \t'\"");
        if (in_array($name, $tagNames, true)) {
            $errors[] = "OpenAPI tags contain duplicate name: {$name}";
            continue;
        }

        $tagNames[] = $name;
    }

    return $errors;
}

/**
 * @return list<string>
 */
function metadataPlaceholderErrors(string $contents): array
{
    $errors = [];
    foreach (['Production placeholder', 'outside this slice'] as $phrase) {
        if (stripos($contents, $phrase) !== false) {
            $errors[] = "OpenAPI metadata contains forbidden placeholder phrase: {$phrase}";
        }
    }

    $pathKeys = [];
    if (preg_match_all('/^\s{2}(\/api\/v1\/[^:]+):\s*$/m', $contents, $matches) !== false) {
        foreach ($matches[1] as $path) {
            if (in_array($path, $pathKeys, true)) {
                $errors[] = "OpenAPI paths contain duplicate key: {$path}";
                continue;
            }

            $pathKeys[] = $path;
        }
    }

    return $errors;
}

/**
 * @return list<array{route: string, block: string}>
 */
function operationBlocksFromYaml(string $contents): array
{
    $blocks = [];
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        return [];
    }

    $currentPath = null;
    $currentMethod = null;
    $currentBlock = [];

    $flush = static function () use (&$blocks, &$currentPath, &$currentMethod, &$currentBlock): void {
        if ($currentPath !== null && $currentMethod !== null) {
            $blocks[] = [
                'route' => strtoupper($currentMethod) . ' ' . $currentPath,
                'block' => implode(PHP_EOL, $currentBlock),
            ];
        }

        $currentMethod = null;
        $currentBlock = [];
    };

    foreach ($lines as $line) {
        if (preg_match('/^\s{2}(\/api\/v1\/[^:]+):\s*$/', $line, $pathMatch) === 1) {
            $flush();
            $currentPath = $pathMatch[1];
            continue;
        }

        if ($currentPath !== null && preg_match('/^\s{4}(get|post|put|patch|delete):\s*$/', $line, $methodMatch) === 1) {
            $flush();
            $currentMethod = $methodMatch[1];
            $currentBlock = [$line];
            continue;
        }

        if ($currentMethod !== null) {
            if (preg_match('/^\s{4}\S/', $line) === 1) {
                $flush();
            }

            if ($currentMethod !== null) {
                $currentBlock[] = $line;
            }
        }
    }

    $flush();

    return $blocks;
}

/**
 * @return list<string>
 */
function implementedRoutesFromRoutesFile(string $routesPath): array
{
    if (!is_file($routesPath)) {
        return [];
    }

    $contents = (string) file_get_contents($routesPath);
    $routes = [];
    $groupPrefixes = [];

    if (preg_match_all('/->group\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $groupMatches)) {
        $groupPrefixes = $groupMatches[1];
    }

    if (preg_match_all('/(?:\$app|\$group)->(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

    foreach ($matches as $match) {
        $method = strtoupper($match[1]);
        $path = $match[2];

        if (str_starts_with($path, '/api/v1/')) {
            $routes[] = "{$method} {$path}";
            continue;
        }

        if (!str_starts_with($path, '/')) {
            continue;
        }

        foreach ($groupPrefixes as $prefix) {
            if (str_starts_with($prefix, '/api/v1/')) {
                $routes[] = "{$method} {$prefix}{$path}";
                break;
            }
        }
    }

    return array_values(array_unique($routes));
}

/**
 * @return array<string, string>
 */
function implementedRouteSecurityFromRoutesFile(string $routesPath): array
{
    if (!is_file($routesPath)) {
        return [];
    }

    $contents = (string) file_get_contents($routesPath);
    $security = [];

    if (preg_match_all(
        '/\$app->group\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*:\s*void\s*\{(?P<body>.*?)\}\)->add\((?P<middleware>[A-Za-z0-9_\\\\]+)::class\)/s',
        $contents,
        $groupMatches,
        PREG_SET_ORDER,
    ) !== false) {
        foreach ($groupMatches as $groupMatch) {
            $prefix = $groupMatch[1];
            $scheme = securityRequirementFromMiddleware($groupMatch['middleware']);
            if ($scheme === null) {
                continue;
            }

            if (preg_match_all(
                '/\$group->(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]/',
                $groupMatch['body'],
                $routeMatches,
                PREG_SET_ORDER,
            ) === false) {
                continue;
            }

            foreach ($routeMatches as $routeMatch) {
                $security[strtoupper($routeMatch[1]) . ' ' . $prefix . $routeMatch[2]] = $scheme;
            }
        }
    }

    if (preg_match_all(
        '/\$app->(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"].*?(?:;)/s',
        $contents,
        $routeMatches,
        PREG_SET_ORDER,
    ) === false) {
        return $security;
    }

    foreach ($routeMatches as $routeMatch) {
        $path = $routeMatch[2];
        if (!str_starts_with($path, '/api/v1/')) {
            continue;
        }

        $route = strtoupper($routeMatch[1]) . ' ' . $path;
        $security[$route] = securityRequirementFromMiddleware($routeMatch[0]) ?? ($security[$route] ?? 'public');
    }

    return $security;
}

function securityRequirementFromMiddleware(string $middleware): ?string
{
    if (str_contains($middleware, 'AuthenticateRequestMiddleware')) {
        return 'bearer';
    }

    if (str_contains($middleware, 'CronAuthMiddleware')) {
        return 'cron';
    }

    return null;
}

/**
 * @param array<string, string> $implementedSecurity
 * @return list<string>
 */
function securityErrorsFromParsedOpenApi(array $parsed, array $implementedSecurity): array
{
    $errors = [];
    foreach ($implementedSecurity as $route => $requirement) {
        [$method, $path] = explode(' ', $route, 2);
        $operation = $parsed['paths'][$path][strtolower($method)] ?? null;
        if (!is_array($operation)) {
            continue;
        }

        $security = $operation['security'] ?? null;
        if ($requirement === 'bearer' && !parsedSecurityIncludes($security, 'BearerAuth')) {
            $errors[] = "OpenAPI operation {$route} must document BearerAuth security required by routes.php.";
        } elseif ($requirement === 'cron' && !parsedSecurityIncludesAny($security, ['CronTokenHeader', 'CronTokenQuery'])) {
            $errors[] = "OpenAPI operation {$route} must document cron token security required by routes.php.";
        } elseif ($requirement === 'public' && parsedSecurityIncludesAny($security, ['BearerAuth', 'CronTokenHeader', 'CronTokenQuery'])) {
            $errors[] = "OpenAPI operation {$route} documents protected security but routes.php leaves it public.";
        }
    }

    return $errors;
}

/**
 * @param array<string, string> $implementedSecurity
 * @return list<string>
 */
function securityErrorsFromYaml(string $contents, array $implementedSecurity): array
{
    $blocksByRoute = [];
    foreach (operationBlocksFromYaml($contents) as $operation) {
        $blocksByRoute[$operation['route']] = $operation['block'];
    }

    $errors = [];
    foreach ($implementedSecurity as $route => $requirement) {
        $block = $blocksByRoute[$route] ?? null;
        if ($block === null) {
            continue;
        }

        if ($requirement === 'bearer' && !yamlSecurityIncludes($block, 'BearerAuth')) {
            $errors[] = "OpenAPI operation {$route} must document BearerAuth security required by routes.php.";
        } elseif ($requirement === 'cron' && !yamlSecurityIncludesAny($block, ['CronTokenHeader', 'CronTokenQuery'])) {
            $errors[] = "OpenAPI operation {$route} must document cron token security required by routes.php.";
        } elseif ($requirement === 'public' && yamlSecurityIncludesAny($block, ['BearerAuth', 'CronTokenHeader', 'CronTokenQuery'])) {
            $errors[] = "OpenAPI operation {$route} documents protected security but routes.php leaves it public.";
        }
    }

    return $errors;
}

/**
 * @return list<string>
 */
function queryParameterErrorsFromParsedOpenApi(array $parsed): array
{
    $errors = [];
    foreach (frontendUsedQueryParameters() as $route => $requiredParameters) {
        [$method, $path] = explode(' ', $route, 2);
        $operation = $parsed['paths'][$path][strtolower($method)] ?? null;
        if (!is_array($operation)) {
            continue;
        }

        $documented = [];
        foreach (($operation['parameters'] ?? []) as $parameter) {
            if (is_array($parameter) && ($parameter['in'] ?? null) === 'query' && isset($parameter['name'])) {
                $documented[] = (string) $parameter['name'];
            }
        }

        foreach ($requiredParameters as $parameter) {
            if (!in_array($parameter, $documented, true)) {
                $errors[] = "OpenAPI operation {$route} is missing query parameter documented from frontend usage: {$parameter}";
            }
        }
    }

    return $errors;
}

/**
 * @return list<string>
 */
function queryParameterErrorsFromYaml(string $contents): array
{
    $blocksByRoute = [];
    foreach (operationBlocksFromYaml($contents) as $operation) {
        $blocksByRoute[$operation['route']] = $operation['block'];
    }

    $errors = [];
    foreach (frontendUsedQueryParameters() as $route => $requiredParameters) {
        $block = $blocksByRoute[$route] ?? null;
        if ($block === null) {
            continue;
        }

        foreach ($requiredParameters as $parameter) {
            if (!yamlQueryParameterExists($block, $parameter)) {
                $errors[] = "OpenAPI operation {$route} is missing query parameter documented from frontend usage: {$parameter}";
            }
        }
    }

    return $errors;
}

/**
 * @return array<string, list<string>>
 */
function frontendUsedQueryParameters(): array
{
    return [
        'GET /api/v1/auth/me' => ['organization_id'],
        'GET /api/v1/billing/balance' => ['organization_id'],
        'GET /api/v1/billing/ledger' => ['organization_id', 'limit'],
        'POST /api/v1/billing/recharge-keys/redeem' => ['organization_id'],
        'GET /api/v1/feature-flags' => ['environment'],
    ];
}

function hasSuccessResponse(array $responses): bool
{
    foreach (array_keys($responses) as $statusCode) {
        if (preg_match('/^2\d\d$/', (string) $statusCode) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function requiredDocumentedResponseStatuses(array $responses): array
{
    $statuses = [];
    foreach (array_keys($responses) as $statusCode) {
        $statusCode = (string) $statusCode;
        if ($statusCode === 'default' || (preg_match('/^2\d\d$/', $statusCode) === 1 && $statusCode !== '204')) {
            $statuses[] = $statusCode;
        }
    }

    return $statuses;
}

function responseHasContentType(mixed $response, array $parsed, string $contentType): bool
{
    if (is_array($response) && isset($response['$ref']) && is_string($response['$ref'])) {
        $response = resolveLocalRef($parsed, $response['$ref']);
    }

    return is_array($response) && isset($response['content'][$contentType]);
}

function resolveLocalRef(array $document, string $ref): mixed
{
    if (!str_starts_with($ref, '#/')) {
        return null;
    }

    $value = $document;
    foreach (explode('/', substr($ref, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }

        $value = $value[$segment];
    }

    return $value;
}

/**
 * @return list<string>
 */
function requiredDocumentedResponseStatusesFromYamlBlock(string $block): array
{
    $statuses = [];
    if (preg_match_all('/^\s{8}("?2\d\d"?|default):\s*$/m', $block, $matches) === false) {
        return [];
    }

    foreach ($matches[1] as $statusCode) {
        $statusCode = trim($statusCode, '"');
        if ($statusCode === 'default' || $statusCode !== '204') {
            $statuses[] = $statusCode;
        }
    }

    return $statuses;
}

function responseMustDeclareHtml(string $route, string $statusCode): bool
{
    return $route === 'GET /api/v1/ads/serve' && $statusCode === '200';
}

function yamlResponseHasContentType(string $contents, string $operationBlock, string $statusCode, string $contentType): bool
{
    $responseBlock = yamlNestedBlock($operationBlock, 8, $statusCode);
    if ($responseBlock === null) {
        return false;
    }

    if (preg_match('/^\s{10}\$ref:\s*[\'"]?#\/components\/responses\/([^\'"\s]+)[\'"]?\s*$/m', $responseBlock, $refMatch) === 1) {
        $responseBlock = yamlNestedBlock($contents, 4, $refMatch[1]);
        if ($responseBlock === null) {
            return false;
        }
    }

    return preg_match('/^\s+' . preg_quote($contentType, '/') . ':\s*$/m', $responseBlock) === 1;
}

function yamlNestedBlock(string $contents, int $indent, string $key): ?string
{
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        return null;
    }

    $block = [];
    $capturing = false;
    $quotedKey = preg_quote($key, '/');
    $keyPattern = '/^\s{' . $indent . '}"?' . $quotedKey . '"?:\s*(?:\S.*)?$/';
    $nextPeerPattern = '/^\s{' . $indent . '}\S/';

    foreach ($lines as $line) {
        if (!$capturing && preg_match($keyPattern, $line) === 1) {
            $capturing = true;
            $block[] = $line;
            continue;
        }

        if ($capturing) {
            if (preg_match($nextPeerPattern, $line) === 1) {
                break;
            }

            $block[] = $line;
        }
    }

    return $capturing ? implode(PHP_EOL, $block) : null;
}

function parsedSecurityIncludes(mixed $security, string $scheme): bool
{
    return parsedSecurityIncludesAny($security, [$scheme]);
}

/**
 * @param list<string> $schemes
 */
function parsedSecurityIncludesAny(mixed $security, array $schemes): bool
{
    if (!is_array($security)) {
        return false;
    }

    foreach ($security as $requirement) {
        if (!is_array($requirement)) {
            continue;
        }

        foreach ($schemes as $scheme) {
            if (array_key_exists($scheme, $requirement)) {
                return true;
            }
        }
    }

    return false;
}

function yamlSecurityIncludes(string $block, string $scheme): bool
{
    return yamlSecurityIncludesAny($block, [$scheme]);
}

/**
 * @param list<string> $schemes
 */
function yamlSecurityIncludesAny(string $block, array $schemes): bool
{
    $securityBlock = yamlNestedBlock($block, 6, 'security');
    if ($securityBlock === null) {
        return false;
    }

    foreach ($schemes as $scheme) {
        if (preg_match('/^\s*-?\s*' . preg_quote($scheme, '/') . ':\s*\[\]\s*$/m', normalizeYamlBlockIndent($securityBlock)) === 1) {
            return true;
        }
    }

    return false;
}

function yamlQueryParameterExists(string $operationBlock, string $parameterName): bool
{
    $parametersBlock = yamlNestedBlock($operationBlock, 6, 'parameters');
    if ($parametersBlock === null) {
        return false;
    }

    if (preg_match_all('/^\s*-\s+name:\s*([^\s]+)\s*\R(?P<body>(?:^\s{10,}\S.*\R?)*)/m', $parametersBlock, $matches, PREG_SET_ORDER) === false) {
        return false;
    }

    foreach ($matches as $match) {
        if ($match[1] !== $parameterName) {
            continue;
        }

        if (preg_match('/^\s*in:\s*query\s*$/m', normalizeYamlBlockIndent($match['body'])) === 1) {
            return true;
        }
    }

    return false;
}

function normalizeYamlBlockIndent(string $block): string
{
    $lines = preg_split('/\R/', $block);
    if ($lines === false || $lines === []) {
        return $block;
    }

    $minIndent = null;
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        preg_match('/^ */', $line, $match);
        $indent = strlen($match[0] ?? '');
        $minIndent = $minIndent === null ? $indent : min($minIndent, $indent);
    }

    if ($minIndent === null || $minIndent === 0) {
        return $block;
    }

    return implode(PHP_EOL, array_map(
        static fn (string $line): string => trim($line) === '' ? $line : substr($line, $minIndent),
        $lines,
    ));
}
