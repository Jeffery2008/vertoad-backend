<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AuthOpenApiContractTest extends TestCase
{
    public function testCurrentUserContractIncludesStrictSelectableOrganizationData(): void
    {
        $document = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($document);
        self::assertStringStartsWith('3.1.', (string) ($document['openapi'] ?? ''));

        $operation = $document['paths']['/api/v1/auth/me']['get'] ?? null;
        self::assertIsArray($operation);
        self::assertSame('getCurrentUser', $operation['operationId'] ?? null);
        self::assertSame([['BearerAuth' => []]], $operation['security'] ?? null);
        self::assertStringContainsString('ordered by slug and then id', $operation['description'] ?? '');
        self::assertStringContainsString('centralized operation errors', $operation['description'] ?? '');
        self::assertArrayHasKey('401', $operation['responses'] ?? []);
        self::assertSame(
            '#/components/schemas/AuthMeOperationErrorEnvelope',
            $operation['responses']['500']['content']['application/json']['schema']['$ref'] ?? null,
        );
        self::assertSame(
            '#/components/schemas/MeData',
            $operation['responses']['200']['content']['application/json']['schema']['allOf'][1]['properties']['data']['$ref'] ?? null,
        );

        $meData = $document['components']['schemas']['MeData'] ?? null;
        self::assertIsArray($meData);
        self::assertSame(
            ['user', 'organization_id', 'membership', 'organizations'],
            $meData['required'] ?? null,
        );
        self::assertFalse($meData['additionalProperties'] ?? true);
        self::assertSame(['integer', 'null'], $meData['properties']['organization_id']['type'] ?? null);
        self::assertSame(['object', 'null'], $meData['properties']['membership']['type'] ?? null);
        self::assertStringContainsString(
            'preserved even when no active selected membership exists',
            $meData['properties']['organization_id']['description'] ?? '',
        );
        self::assertStringContainsString(
            'does not confirm that the organization exists or is accessible',
            $meData['properties']['organization_id']['description'] ?? '',
        );
        self::assertStringContainsString(
            'selected membership is not active',
            $meData['properties']['membership']['description'] ?? '',
        );
        self::assertSame('array', $meData['properties']['organizations']['type'] ?? null);
        self::assertSame(
            '#/components/schemas/CurrentUserOrganization',
            $meData['properties']['organizations']['items']['$ref'] ?? null,
        );
        self::assertStringContainsString(
            'empty when the user has no active organization memberships',
            $meData['properties']['organizations']['description'] ?? '',
        );
        self::assertStringContainsString(
            'Super-admin status alone does not expand the list',
            $meData['properties']['organizations']['description'] ?? '',
        );

        $organization = $document['components']['schemas']['CurrentUserOrganization'] ?? null;
        self::assertIsArray($organization);
        self::assertSame(['id', 'name', 'slug', 'roles', 'permissions'], $organization['required'] ?? null);
        self::assertFalse($organization['additionalProperties'] ?? true);
        self::assertSame(1, $organization['properties']['id']['minimum'] ?? null);
        self::assertSame(1, $organization['properties']['name']['minLength'] ?? null);
        self::assertSame(1, $organization['properties']['slug']['minLength'] ?? null);
        self::assertSame('\\S', $organization['properties']['name']['pattern'] ?? null);
        self::assertSame('\\S', $organization['properties']['slug']['pattern'] ?? null);
        self::assertStringContainsString(
            'Super-admin status alone does not add organizations',
            $organization['description'] ?? '',
        );
        foreach (['roles', 'permissions'] as $grant) {
            self::assertSame('array', $organization['properties'][$grant]['type'] ?? null);
            self::assertTrue($organization['properties'][$grant]['uniqueItems'] ?? false);
            self::assertSame(1, $organization['properties'][$grant]['items']['minLength'] ?? null);
            self::assertSame('\\S', $organization['properties'][$grant]['items']['pattern'] ?? null);
            self::assertStringContainsString('sorted in ascending lexical order', $organization['properties'][$grant]['description'] ?? '');
            self::assertTrue($meData['properties']['membership']['properties'][$grant]['uniqueItems'] ?? false);
            self::assertSame('\\S', $meData['properties']['membership']['properties'][$grant]['items']['pattern'] ?? null);
            self::assertStringContainsString(
                'sorted in ascending lexical order',
                $meData['properties']['membership']['properties'][$grant]['description'] ?? '',
            );
        }

        $operationError = $document['components']['schemas']['AuthMeOperationErrorEnvelope']['allOf'][1]['properties'] ?? null;
        self::assertIsArray($operationError);
        self::assertSame('null', $operationError['data']['type'] ?? null);
        self::assertSame(
            ['code', 'message', 'operation_error_id'],
            $operationError['error']['required'] ?? null,
        );
        self::assertSame('operation_error', $operationError['error']['properties']['code']['const'] ?? null);
        self::assertSame(
            '^operr_[0-9a-f]{40}$',
            $operationError['error']['properties']['operation_error_id']['pattern'] ?? null,
        );
        self::assertSame('v1', $operationError['meta']['properties']['api_version']['const'] ?? null);
    }
}
