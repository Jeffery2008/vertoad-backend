<?php

declare(strict_types=1);

namespace VertoAD\Tests\Review;

use PHPUnit\Framework\TestCase;

final class ReviewMigrationTest extends TestCase
{
    public function testReviewMigrationCreatesStateAndAuditTables(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260608053000_create_creative_review_tables.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'create table creative_reviews',
            'asset_id bigint unsigned not null',
            'status varchar(32) not null',
            'ai_risk_score decimal(5,4) null',
            'ai_risk_labels json not null',
            'final_decision varchar(32) null',
            'create table review_decisions',
            'from_status varchar(32) not null',
            'to_status varchar(32) not null',
            'create table review_eligibility_events',
            'eligibility varchar(32) not null',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }
}
