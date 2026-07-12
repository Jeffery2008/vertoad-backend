<?php

declare(strict_types=1);

namespace VertoAD\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Redis\RedisReadinessPolicy;

final class RedisReadinessPolicyTest extends TestCase
{
    public function testAcceptsProductionReadyRedisEvidence(): void
    {
        self::assertSame([], (new RedisReadinessPolicy())->assess(
            str_repeat('a', RedisReadinessPolicy::MINIMUM_PASSWORD_LENGTH),
            RedisReadinessPolicy::MINIMUM_VERSION,
            ['FLUSHALL' => false, 'FLUSHDB' => false, 'CONFIG' => false],
        ));
    }

    public function testReportsEveryPasswordVersionAndAclFailure(): void
    {
        self::assertSame([
            'redis_password_too_short',
            'redis_version_too_old',
            'redis_dangerous_command_allowed:FLUSHALL',
            'redis_acl_unverifiable:FLUSHDB',
            'redis_acl_unverifiable:CONFIG',
        ], (new RedisReadinessPolicy())->assess(
            'short',
            '8.7.9',
            ['FLUSHALL' => true, 'FLUSHDB' => null],
        ));
    }

    public function testRejectsAnUnparseableVersionButAcceptsNewerPrereleaseSyntax(): void
    {
        $policy = new RedisReadinessPolicy();

        self::assertSame([
            'redis_version_unverifiable',
        ], $policy->assess(
            str_repeat('b', 32),
            'not-a-version',
            ['FLUSHALL' => false, 'FLUSHDB' => false, 'CONFIG' => false],
        ));
        self::assertSame([], $policy->assess(
            str_repeat('c', 32),
            ' 8.8.1-rc.1 ',
            ['FLUSHALL' => false, 'FLUSHDB' => false, 'CONFIG' => false],
        ));
    }

    public function testInterpretsAclDryRunResponsesWithoutExecutingCommands(): void
    {
        $policy = new RedisReadinessPolicy();

        self::assertTrue($policy->interpretAclDryRun('FLUSHALL', 'OK', null));
        self::assertNull($policy->interpretAclDryRun('FLUSHALL', 'unexpected', null));
        self::assertFalse($policy->interpretAclDryRun(
            'FLUSHALL',
            null,
            "This user has no permissions to run the 'flushall' command",
        ));
        self::assertFalse($policy->interpretAclDryRun(
            'CONFIG',
            null,
            "This user has no permissions to run the 'config|get' command",
        ));
        self::assertFalse($policy->interpretAclDryRun(
            'FLUSHALL',
            "User default has no permissions to run the 'flushall' command",
            null,
        ));
        self::assertFalse($policy->interpretAclDryRun(
            'FLUSHDB',
            "User default has no permissions to run the 'flushdb' command",
            null,
        ));
        self::assertFalse($policy->interpretAclDryRun(
            'CONFIG',
            "User default has no permissions to run the 'config|get' command",
            null,
        ));
        self::assertNull($policy->interpretAclDryRun(
            'FLUSHDB',
            null,
            "NOPERM this user has no permissions to run the 'acl|dryrun' command",
        ));
        self::assertNull($policy->interpretAclDryRun('FLUSHDB', null, 'connection closed'));
        self::assertNull($policy->interpretAclDryRun(
            'FLUSHDB',
            null,
            "This user has no permissions to run the 'get' command",
        ));
    }
}
