<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use VertoAD\Domain\Publisher\AdSlot;

final class AdSlotRepository implements AdSlotRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function store(AdSlot $slot): AdSlot
    {
        $responsiveRulesJson = $slot->responsiveRules === []
            ? null
            : json_encode($slot->responsiveRules, JSON_THROW_ON_ERROR);

        if ($slot->id === null) {
            $this->connection->insert('ad_slots', [
                'site_id' => $slot->siteId,
                'name' => $slot->name,
                'slot_key' => $slot->slotKey,
                'width' => $slot->size->width,
                'height' => $slot->size->height,
                'size_preset' => $slot->presetKey,
                'is_responsive' => $slot->responsive ? 1 : 0,
                'responsive_rules_json' => $responsiveRulesJson,
                'status' => $slot->status,
            ]);

            return $slot->withId((int) $this->connection->lastInsertId());
        }

        $this->connection->update('ad_slots', [
            'name' => $slot->name,
            'slot_key' => $slot->slotKey,
            'width' => $slot->size->width,
            'height' => $slot->size->height,
            'size_preset' => $slot->presetKey,
            'is_responsive' => $slot->responsive ? 1 : 0,
            'responsive_rules_json' => $responsiveRulesJson,
            'status' => $slot->status,
        ], ['id' => $slot->id]);

        return $slot;
    }
}
