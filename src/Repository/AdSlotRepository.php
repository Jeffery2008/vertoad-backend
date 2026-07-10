<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\AdSlotSize;

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

    /**
     * @return list<AdSlot>
     */
    public function listForSite(int $siteId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'site_id', 'name', 'slot_key', 'width', 'height', 'size_preset', 'is_responsive', 'responsive_rules_json', 'status')
            ->from('ad_slots')
            ->where('site_id = :site_id')
            ->orderBy('id', 'ASC')
            ->setParameter('site_id', $siteId)
            ->fetchAllAssociative();

        return array_map(fn (array $row): AdSlot => $this->mapRow($row), $rows);
    }

    public function findForSiteInOrganization(int $siteId, int $slotId, int $organizationId): ?AdSlot
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'slots.id AS id',
                'slots.site_id AS site_id',
                'slots.name AS name',
                'slots.slot_key AS slot_key',
                'slots.width AS width',
                'slots.height AS height',
                'slots.size_preset AS size_preset',
                'slots.is_responsive AS is_responsive',
                'slots.responsive_rules_json AS responsive_rules_json',
                'slots.status AS status',
            )
            ->from('ad_slots', 'slots')
            ->innerJoin('slots', 'sites', 'sites', 'sites.id = slots.site_id')
            ->where('slots.id = :slot_id')
            ->andWhere('slots.site_id = :site_id')
            ->andWhere('sites.organization_id = :organization_id')
            ->setParameter('slot_id', $slotId)
            ->setParameter('site_id', $siteId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        return $row === false ? null : $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): AdSlot
    {
        $rulesJson = $row['responsive_rules_json'];
        $rules = $rulesJson === null || $rulesJson === ''
            ? []
            : json_decode((string) $rulesJson, true, flags: JSON_THROW_ON_ERROR);

        return new AdSlot(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            name: (string) $row['name'],
            slotKey: (string) $row['slot_key'],
            size: new AdSlotSize((int) $row['width'], (int) $row['height']),
            responsive: (int) $row['is_responsive'] === 1,
            responsiveRules: is_array($rules) ? $rules : [],
            presetKey: $row['size_preset'] === null ? null : (string) $row['size_preset'],
            status: (string) $row['status'],
        );
    }
}
