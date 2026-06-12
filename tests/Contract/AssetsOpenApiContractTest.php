<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class AssetsOpenApiContractTest extends TestCase
{
    public function testPresignedUploadStatusCodesDocumentProductionAndLocalSigners(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $schema = $this->block($openApi, '    PresignedUploadData:', '    CreativeReviewStartRequest:');

        self::assertStringContainsString('status_code:', $schema);
        self::assertStringContainsString('enum:', $schema);
        self::assertStringContainsString('- 200', $schema);
        self::assertStringContainsString('- 201', $schema);
        self::assertStringContainsString('S3-compatible', $schema);
        self::assertStringContainsString('deterministic', $schema);
    }

    private function block(string $document, string $start, string $end): string
    {
        $startOffset = strpos($document, $start);
        self::assertNotFalse($startOffset, $start);
        $endOffset = strpos($document, $end, $startOffset + strlen($start));
        self::assertNotFalse($endOffset, $end);

        return substr($document, $startOffset, $endOffset - $startOffset);
    }
}
