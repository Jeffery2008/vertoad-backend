<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class OpenApiCheckScriptTest extends TestCase
{
    public function testCheckerUsesFullYamlParserWhenYamlExtensionIsUnavailable(): void
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
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
        self::assertStringNotContainsString('parser=structural-fallback', implode(PHP_EOL, $output));
        self::assertStringNotContainsString('unavailable', implode(PHP_EOL, $output));
    }

    public function testCheckerFailsMalformedYamlThatLooksStructurallyComplete(): void
    {
        $result = self::runChecker(<<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/health:
    get:
      tags: [Health
      operationId: getHealth
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
PHP);

        self::assertSame(1, $result['exitCode'], $result['output']);
        self::assertStringContainsString('OpenAPI YAML could not be parsed.', $result['output']);
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
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
            $ref: "#/components/schemas/ErrorEnvelope"
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

    public function testCheckerFailsWhenJsonSuccessResponseUsesBareDataSchema(): void
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
                $ref: "#/components/schemas/HealthData"
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
    HealthData:
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
            'OpenAPI operation GET /api/v1/health response 200 schema must wrap JSON payload with SuccessEnvelope.',
            implode(PHP_EOL, $output),
        );
    }

    public function testCheckerFailsWhenSharedErrorResponseUsesBareErrorObject(): void
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        $ref: "#/components/schemas/HealthData"
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            $ref: "#/components/schemas/ErrorObject"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
    ErrorObject:
      type: object
    HealthData:
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
            'OpenAPI component response Error must use ErrorEnvelope for JSON error payloads.',
            implode(PHP_EOL, $output),
        );
    }

    public function testCheckerAcceptsServeFrameHtmlSuccessResponse(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/ads/serve:
    get:
      tags:
        - Serving
      operationId: serveAdFrame
      responses:
        "200":
          description: iframe html
          content:
            text/html:
              schema:
                type: string
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/ads/serve', ServeFrameAction::class);
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

    public function testCheckerAcceptsRedirectSuccessResponseWithoutJsonContent(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/ads/click:
    get:
      tags:
        - Serving
      operationId: redirectAdClick
      responses:
        "302":
          description: redirect
          headers:
            Location:
              schema:
                type: string
                format: uri
        default:
          $ref: "#/components/responses/Error"
components:
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/ads/click', ClickAction::class);
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

    public function testCheckerRequiresServeFrameHtmlSuccessResponse(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/ads/serve:
    get:
      tags:
        - Serving
      operationId: serveAdFrame
      responses:
        "200":
          description: iframe html
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

$app->get('/api/v1/ads/serve', ServeFrameAction::class);
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
            'OpenAPI operation GET /api/v1/ads/serve response 200 must declare text/html content.',
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
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

    public function testCheckerFailsWhenOperationsCorrelationEntryTypeQueryParameterIsNotDocumented(): void
    {
        $result = self::runChecker(<<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/operations/request-correlations:
    get:
      tags:
        - Operations
      operationId: searchOperationRequestCorrelations
      parameters:
        - name: request_id
          in: query
          schema:
            type: string
        - name: actor_user_id
          in: query
          schema:
            type: integer
        - name: actor
          in: query
          schema:
            type: string
        - name: action
          in: query
          schema:
            type: string
        - name: subject_type
          in: query
          schema:
            type: string
        - name: subject_id
          in: query
          schema:
            type: string
        - name: ip_address
          in: query
          schema:
            type: string
        - name: endpoint
          in: query
          schema:
            type: string
        - name: occurred_from
          in: query
          schema:
            type: string
        - name: occurred_to
          in: query
          schema:
            type: string
        - name: limit
          in: query
          schema:
            type: integer
        - name: offset
          in: query
          schema:
            type: integer
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML, <<<'PHP'
<?php

$app->get('/api/v1/operations/request-correlations', GetOperationRequestCorrelationsAction::class);
PHP);

        self::assertSame(1, $result['exitCode'], $result['output']);
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/operations/request-correlations is missing query parameter documented from frontend usage: entry_type',
            $result['output'],
        );
    }

    public function testCheckerFailsWhenDesignerUploadAndReviewQueryParametersAreNotDocumented(): void
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';

        file_put_contents($openApiPath, <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/assets/upload-intents:
    post:
      tags:
        - Assets
      operationId: createAssetUploadIntent
      security:
        - BearerAuth: []
      responses:
        "201":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        type: object
        default:
          $ref: "#/components/responses/Error"
  /api/v1/assets/confirm:
    post:
      tags:
        - Assets
      operationId: confirmAssetUpload
      security:
        - BearerAuth: []
      responses:
        "201":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        type: object
        default:
          $ref: "#/components/responses/Error"
  /api/v1/reviews/assets/{asset_id}/ai-review:
    post:
      tags:
        - Reviews
      operationId: startAssetAiReview
      security:
        - BearerAuth: []
      parameters:
        - name: asset_id
          in: path
          required: true
          schema:
            type: integer
      responses:
        "201":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        type: object
        default:
          $ref: "#/components/responses/Error"
  /api/v1/reviews:
    get:
      tags:
        - Reviews
      operationId: listCreativeReviewQueue
      security:
        - BearerAuth: []
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        type: object
        default:
          $ref: "#/components/responses/Error"
  /api/v1/reviews/{review_id}:
    get:
      tags:
        - Reviews
      operationId: getCreativeReviewStatus
      security:
        - BearerAuth: []
      parameters:
        - name: review_id
          in: path
          required: true
          schema:
            type: integer
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        type: object
        default:
          $ref: "#/components/responses/Error"
  /api/v1/reviews/{review_id}/approve:
    post:
      tags:
        - Reviews
      operationId: approveCreativeReview
      security:
        - BearerAuth: []
      parameters:
        - name: review_id
          in: path
          required: true
          schema:
            type: integer
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        type: object
        default:
          $ref: "#/components/responses/Error"
  /api/v1/reviews/{review_id}/reject:
    post:
      tags:
        - Reviews
      operationId: rejectCreativeReview
      security:
        - BearerAuth: []
      parameters:
        - name: review_id
          in: path
          required: true
          schema:
            type: integer
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML);

        file_put_contents($routesPath, <<<'PHP'
<?php

use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;

$app->post('/api/v1/assets/upload-intents', CreateAssetUploadIntentAction::class)->add(AuthenticateRequestMiddleware::class);
$app->post('/api/v1/assets/confirm', ConfirmAssetUploadAction::class)->add(AuthenticateRequestMiddleware::class);
$app->post('/api/v1/reviews/assets/{asset_id}/ai-review', StartAiReviewAction::class)->add(AuthenticateRequestMiddleware::class);
$app->get('/api/v1/reviews', ListReviewQueueAction::class)->add(AuthenticateRequestMiddleware::class);
$app->get('/api/v1/reviews/{review_id}', GetReviewStatusAction::class)->add(AuthenticateRequestMiddleware::class);
$app->post('/api/v1/reviews/{review_id}/approve', ApproveReviewAction::class)->add(AuthenticateRequestMiddleware::class);
$app->post('/api/v1/reviews/{review_id}/reject', RejectReviewAction::class)->add(AuthenticateRequestMiddleware::class);
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
            'OpenAPI operation POST /api/v1/assets/upload-intents is missing query parameter documented from frontend usage: organization_id',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation POST /api/v1/assets/confirm is missing query parameter documented from frontend usage: organization_id',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation POST /api/v1/reviews/assets/{asset_id}/ai-review is missing query parameter documented from frontend usage: organization_id',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/reviews is missing query parameter documented from frontend usage: organization_id',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/reviews is missing query parameter documented from frontend usage: status',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/reviews is missing query parameter documented from frontend usage: limit',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation GET /api/v1/reviews/{review_id} is missing query parameter documented from frontend usage: organization_id',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation POST /api/v1/reviews/{review_id}/approve is missing query parameter documented from frontend usage: organization_id',
            implode(PHP_EOL, $output),
        );
        self::assertStringContainsString(
            'OpenAPI operation POST /api/v1/reviews/{review_id}/reject is missing query parameter documented from frontend usage: organization_id',
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
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

    public function testCheckerAcceptsOperationLevelQueryParameterRefsInStructuralFallback(): void
    {
        $result = self::runChecker(<<<'YAML'
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
        - $ref: "#/components/parameters/OrganizationId"
      responses:
        "200":
          description: ok
          content:
            application/json:
              schema:
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        type: object
        default:
          $ref: "#/components/responses/Error"
components:
  parameters:
    OrganizationId:
      name: organization_id
      in: query
      required: false
      schema:
        type: integer
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML, <<<'PHP'
<?php

use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;

$app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
PHP);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertStringContainsString('OpenAPI contract check passed', $result['output']);
    }

    public function testCheckerAcceptsPathItemQueryParametersInStructuralFallback(): void
    {
        $result = self::runChecker(<<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/auth/me:
    parameters:
      - name: organization_id
        in: query
        required: false
        schema:
          type: integer
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML, <<<'PHP'
<?php

use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;

$app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
PHP);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertStringContainsString('OpenAPI contract check passed', $result['output']);
    }

    public function testCheckerAcceptsPathItemQueryParameterRefsInParsedOpenApi(): void
    {
        $openApi = <<<'YAML'
openapi: 3.1.0
paths:
  /api/v1/auth/me:
    parameters:
      - $ref: "#/components/parameters/OrganizationId"
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
                        type: object
        default:
          $ref: "#/components/responses/Error"
components:
  parameters:
    OrganizationId:
      name: organization_id
      in: query
      required: false
      schema:
        type: integer
  responses:
    Error:
      description: error
      content:
        application/json:
          schema:
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
YAML;

        $result = self::runChecker($openApi, <<<'PHP'
<?php

use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;

$app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
PHP, [
            'openapi' => '3.1.0',
            'paths' => [
                '/api/v1/auth/me' => [
                    'parameters' => [
                        ['$ref' => '#/components/parameters/OrganizationId'],
                    ],
                    'get' => [
                        'tags' => ['Auth'],
                        'operationId' => 'getCurrentUser',
                        'security' => [['BearerAuth' => []]],
                        'responses' => [
                            '200' => [
                                'description' => 'ok',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'allOf' => [
                                                ['$ref' => '#/components/schemas/SuccessEnvelope'],
                                                ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'default' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'parameters' => [
                    'OrganizationId' => [
                        'name' => 'organization_id',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'integer'],
                    ],
                ],
                'responses' => [
                    'Error' => [
                        'description' => 'error',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'],
                            ],
                        ],
                    ],
                ],
                'schemas' => [
                    'SuccessEnvelope' => ['type' => 'object'],
                    'ErrorEnvelope' => ['type' => 'object'],
                ],
            ],
        ]);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertStringContainsString('OpenAPI contract check passed', $result['output']);
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
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

    public function testCheckerFailsWhenYamlContainsDuplicateComponentSchemaKeys(): void
    {
        $result = self::runChecker(<<<'YAML'
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
    DuplicateSchema:
      type: object
    DuplicateSchema:
      type: object
YAML, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
PHP);

        self::assertSame(1, $result['exitCode'], $result['output']);
        self::assertStringContainsString('OpenAPI components.schemas contain duplicate key: DuplicateSchema', $result['output']);
    }

    public function testCheckerFailsWhenSchemaRequiredFieldIsMissingFromProperties(): void
    {
        $result = self::runChecker(<<<'YAML'
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
                allOf:
                  - $ref: "#/components/schemas/SuccessEnvelope"
                  - type: object
                    properties:
                      data:
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
            $ref: "#/components/schemas/ErrorEnvelope"
  schemas:
    SuccessEnvelope:
      type: object
    ErrorEnvelope:
      type: object
    BrokenSchema:
      type: object
      required:
        - missing_field
      properties:
        present_field:
          type: string
YAML, <<<'PHP'
<?php

$app->get('/api/v1/health', HealthAction::class);
PHP);

        self::assertSame(1, $result['exitCode'], $result['output']);
        self::assertStringContainsString(
            'OpenAPI schema BrokenSchema required fields are missing from properties: missing_field',
            $result['output'],
        );
    }

    /**
     * @param array<string, mixed>|null $parsedOpenApi
     * @return array{exitCode: int, output: string}
     */
    private static function runChecker(string $openApi, string $routes, ?array $parsedOpenApi = null): array
    {
        $workspace = sys_get_temp_dir() . '/vertoad-openapi-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));

        $openApiPath = $workspace . '/openapi.yaml';
        $routesPath = $workspace . '/routes.php';
        file_put_contents($openApiPath, $openApi);
        file_put_contents($routesPath, $routes);

        $scriptPath = dirname(__DIR__, 2) . '/scripts/openapi-check.php';
        $commandPath = $scriptPath;
        if ($parsedOpenApi !== null) {
            $commandPath = $workspace . '/run-openapi-check.php';
            file_put_contents($commandPath, '<?php' . PHP_EOL
                . 'if (!function_exists("yaml_parse_file")) {' . PHP_EOL
                . '    function yaml_parse_file(string $path): array {' . PHP_EOL
                . '        return ' . var_export($parsedOpenApi, true) . ';' . PHP_EOL
                . '    }' . PHP_EOL
                . '}' . PHP_EOL
                . '$argv = [__FILE__, ' . var_export($openApiPath, true) . ', ' . var_export($routesPath, true) . '];' . PHP_EOL
                . 'require ' . var_export($scriptPath, true) . ';' . PHP_EOL);
        }

        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($commandPath),
                escapeshellarg($openApiPath),
                escapeshellarg($routesPath),
            ),
            $output,
            $exitCode,
        );

        return ['exitCode' => $exitCode, 'output' => implode(PHP_EOL, $output)];
    }
}
