<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCreativeReviewTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE creative_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    ai_provider VARCHAR(80) NULL,
    ai_model VARCHAR(120) NULL,
    ai_risk_score DECIMAL(5,4) NULL,
    ai_risk_labels JSON NOT NULL,
    ai_reasons JSON NOT NULL,
    ai_raw_result JSON NULL,
    requested_by_user_id BIGINT UNSIGNED NOT NULL,
    final_decision VARCHAR(32) NULL,
    final_decision_reason VARCHAR(1000) NULL,
    final_decided_by_user_id BIGINT UNSIGNED NULL,
    final_decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_creative_reviews_asset (asset_id),
    KEY idx_creative_reviews_organization_status (organization_id, status),
    CONSTRAINT fk_creative_reviews_asset FOREIGN KEY (asset_id) REFERENCES creative_assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_reviews_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_reviews_requester FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_creative_reviews_final_user FOREIGN KEY (final_decided_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_creative_reviews_status CHECK (status IN ('draft', 'pending_ai', 'ai_reviewing', 'needs_human', 'approved', 'rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE review_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    review_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    decision VARCHAR(32) NOT NULL,
    reason VARCHAR(1000) NULL,
    from_status VARCHAR(32) NOT NULL,
    to_status VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_review_decisions_review_created (review_id, created_at),
    KEY idx_review_decisions_asset (asset_id),
    CONSTRAINT fk_review_decisions_review FOREIGN KEY (review_id) REFERENCES creative_reviews (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_decisions_asset FOREIGN KEY (asset_id) REFERENCES creative_assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_decisions_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_decisions_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE review_eligibility_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    review_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    eligibility VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_review_eligibility_events_asset_created (asset_id, created_at),
    KEY idx_review_eligibility_events_organization_created (organization_id, created_at),
    CONSTRAINT fk_review_eligibility_events_review FOREIGN KEY (review_id) REFERENCES creative_reviews (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_eligibility_events_asset FOREIGN KEY (asset_id) REFERENCES creative_assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_eligibility_events_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT chk_review_eligibility_events_eligibility CHECK (eligibility IN ('eligible', 'ineligible'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS review_eligibility_events');
        $this->execute('DROP TABLE IF EXISTS review_decisions');
        $this->execute('DROP TABLE IF EXISTS creative_reviews');
    }
}
