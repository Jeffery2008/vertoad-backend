<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCampaignStatusCheck extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE campaigns
    ADD CONSTRAINT chk_campaigns_status CHECK (status IN ('draft', 'active', 'paused', 'archived'))
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE campaigns
    DROP CHECK chk_campaigns_status
SQL);
    }
}
