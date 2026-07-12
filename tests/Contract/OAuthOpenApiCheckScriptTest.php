<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class OAuthOpenApiCheckScriptTest extends TestCase
{
    private string $openApi;

    protected function setUp(): void
    {
        $this->openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    }

    public function testCheckerAcceptsProductionOAuthContract(): void
    {
        $result = $this->runChecker($this->openApi);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('OpenAPI contract check passed', $result['output']);
    }

    public function testCheckerRejectsMissingOauth2Scheme(): void
    {
        $mutated = $this->replaceOnce(
            '/(^    OAuth2:\R)      type: oauth2/m',
            '$1      type: http',
        );
        $result = $this->runChecker($mutated);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('must define components.securitySchemes.OAuth2 with type oauth2', $result['output']);
    }

    public function testCheckerRejectsOptionalClientCredentialsSecret(): void
    {
        $schema = $this->block(
            $this->openApi,
            '    OAuthClientCredentialsTokenRequest:',
            '    OAuthRefreshTokenRequest:',
        );
        $mutatedSchema = $this->replaceOnce('/^        - client_secret\R/m', '', $schema);
        $result = $this->runChecker(str_replace($schema, $mutatedSchema, $this->openApi));

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('OAuthClientCredentialsTokenRequest must require client_secret', $result['output']);
    }

    public function testCheckerRejectsScopeCatalogDrift(): void
    {
        $mutated = $this->replaceOnce(
            '/^            report\.read\.own: Read reports for the bound advertiser organization\.\R/m',
            '',
        );
        $result = $this->runChecker($mutated);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('flow is missing catalog scopes: report.read.own', $result['output']);
    }

    public function testCheckerRejectsPublicClientCredentialsSchema(): void
    {
        $schema = $this->block($this->openApi, '    OAuthClientCreateRequest:', '    OAuthClient:');
        $mutatedSchema = $this->replaceOnce(
            '/(                  enum:\R                    - authorization_code\R)(                    - refresh_token)/',
            '$1                    - client_credentials' . PHP_EOL . '$2',
            $schema,
        );
        $result = $this->runChecker(str_replace($schema, $mutatedSchema, $this->openApi));

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('must forbid client_credentials when is_confidential is false', $result['output']);
    }

    public function testCheckerRejectsClientCreationScopeCatalogDrift(): void
    {
        $schema = $this->block($this->openApi, '    OAuthClientCreateRequest:', '    OAuthClient:');
        $mutatedSchema = $this->replaceOnce('/^              - report\.read\.own\R/m', '', $schema);
        $result = $this->runChecker(str_replace($schema, $mutatedSchema, $this->openApi));

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('OAuthClientCreateRequest is missing catalog scopes: report.read.own', $result['output']);
    }

    private function replaceOnce(string $pattern, string $replacement, ?string $subject = null): string
    {
        $subject ??= $this->openApi;
        $count = 0;
        $mutated = preg_replace($pattern, $replacement, $subject, 1, $count);
        self::assertIsString($mutated);
        self::assertSame(1, $count, 'Expected OpenAPI mutation target was not found exactly once.');

        return $mutated;
    }

    private function block(string $document, string $start, string $end): string
    {
        $startOffset = strpos($document, $start);
        self::assertNotFalse($startOffset, $start);
        $endOffset = strpos($document, $end, $startOffset + strlen($start));
        self::assertNotFalse($endOffset, $end);

        return substr($document, $startOffset, $endOffset - $startOffset);
    }

    /** @return array{exit_code:int, output:string} */
    private function runChecker(string $document): array
    {
        $openApiPath = tempnam(sys_get_temp_dir(), 'vertoad-oauth-openapi-');
        self::assertIsString($openApiPath);
        file_put_contents($openApiPath, $document);

        $output = [];
        $exitCode = 0;
        exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/scripts/openapi-check.php'),
            escapeshellarg($openApiPath),
            escapeshellarg(dirname(__DIR__, 2) . '/config/routes.php'),
        ), $output, $exitCode);
        unlink($openApiPath);

        return ['exit_code' => $exitCode, 'output' => implode(PHP_EOL, $output)];
    }
}
