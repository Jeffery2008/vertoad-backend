<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class OpenApiCheckScriptTest extends TestCase
{
    public function testCheckerReportsStrongFallbackWhenYamlExtensionIsUnavailable(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/health:
    get:
      tags:
        - Health
      operationId: getHealth
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            type: object
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString('OpenAPI contract check passed', implode(PHP_EOL, $output));
        self::assertStringContainsString('parser=', implode(PHP_EOL, $output));
        self::assertStringNotContainsString('unavailable', implode(PHP_EOL, $output));
    }

    public function testCheckerFailsWhenImplementedApiV1RouteIsMissingFromOpenApiContract(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/health:
    get:
      tags:
        - Health
      operationId: getHealth
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            type: object
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
$app->get('/api/v1/unlisted', UnlistedAction::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(1, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString(
            'OpenAPI contract is missing implemented route: GET /api/v1/unlisted',
            implode(PHP_EOL, $output),
        );
    }

    public function testCheckerFailsWhenApiV1SuccessResponseLacksJsonContent(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/health:
    get:
      tags:
        - Health
      operationId: getHealth
      responses:
        "200":
          description: ok
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            type: object
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(1, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/health response 200 must declare application/json content.',
            implode(PHP_EOL, $output),
        );
    }

    public function testCheckerFailsWhenRouteMiddlewareSecurityIsNotDocumented(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/auth/me:
    get:
      tags:
        - Auth
      operationId: getCurrentUser
      security: []
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            type: object
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;

$app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(1, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/auth/me must document BearerAuth security required by routes.php.',
            implode(PHP_EOL, $output),
        );
    }

    public function testCheckerFailsWhenFrontendQueryParametersAreNotDocumented(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/auth/me:
    get:
      tags:
        - Auth
      operationId: getCurrentUser
      security:
        - BearerAuth: []
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            type: object
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;

$app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(1, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/auth/me is missing query parameter documented from frontend usage: organization_id',
            implode(PHP_EOL, $output),
        );
    }

    public function testCheckerAcceptsDocumentedSecurityAndQueryParameterListsInStructuralFallback(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/auth/me:
    get:
      tags:
        - Auth
      operationId: getCurrentUser
      security:
        - BearerAuth: []
      parameters:
        - name: organization_id
          in: query
          required: false
          schema:
            type: integer
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
  /api/v1/cron/status:
    get:
      tags:
        - Cron
      operationId: getCronStatus
      security:
        - CronTokenHeader: []
        - CronTokenQuery: []
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            type: object
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

use Slim\Routing\RouteCollectorProxy;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\CronAuthMiddleware;

$app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
$app->group('/api/v1/cron', function (RouteCollectorProxy $group): void {
    $group->get('/status', CronStatusAction::class);
})->add(CronAuthMiddleware::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString('OpenAPI contract check passed', implode(PHP_EOL, $output));
    }

    public function testCheckerFailsWhenOperationMetadataIsIncomplete(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/health:
    get:
      tags:
        - Health
      responses:
        "200":
          description: ok
components:
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(1, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/health is missing required field: operationId',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/health is missing default error response.',
            implode(PHP_EOL, $output),
        );
    }

    public function testCheckerFailsWhenContractMetadataKeepsPlaceholdersOrDuplicateTags(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
info:
  title: VertoAD API
  description: OAuth authorization endpoints remain outside this slice.
servers:
  - url: https://api.example.com
    description: Production placeholder
tags:
  - name: Assets
    description: Creative uploads.
  - name: Assets
    description: Duplicated asset tag.
paths:
  /api/v1/health:
    get:
      tags:
        - Health
      operationId: getHealth
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            type: object
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(1, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString('OpenAPI metadata contains forbidden placeholder phrase: Production placeholder', implode(PHP_EOL, $output));
        self::assertStringContainsString('OpenAPI metadata contains forbidden placeholder phrase: outside this slice', implode(PHP_EOL, $output));
        self::assertStringContainsString('OpenAPI tags contain duplicate name: Assets', implode(PHP_EOL, $output));
    }

    public function testCheckerFailsWhenYamlContainsDuplicatePathKeys(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/health:
    get:
      tags:
        - Health
      operationId: getHealth
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
  /api/v1/health:
    post:
      tags:
        - Health
      operationId: duplicateHealth
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                type: object
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            type: object
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
PHP);

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(1, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString('OpenAPI paths contain duplicate key: /api/v1/health', implode(PHP_EOL, $output));
    }
}
