<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use Doctrine\DBAL\Connection;

final readonly class DatabaseServingInventoryRepository implements ServingInventoryRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function isVerifiedActiveSlot(int $siteId, int $slotId): bool
    {
        $row = $this->connection->createQueryBuilder()
            ->select('slots.id')
            ->from('ad_slots', 'slots')
            ->innerJoin('slots', 'sites', 'sites', 'sites.id = slots.site_id')
            ->where('sites.id = :site_id')
            ->andWhere('slots.id = :slot_id')
            ->andWhere('sites.status = :site_status')
            ->andWhere('slots.status = :slot_status')
            ->setParameter('site_id', $siteId)
            ->setParameter('slot_id', $slotId)
            ->setParameter('site_status', 'verified')
            ->setParameter('slot_status', 'active')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row !== false;
    }

    public function publisherOrganizationIdForSlot(int $siteId, int $slotId): ?int
    {
        $row = $this->connection->createQueryBuilder()
            ->select('sites.organization_id')
            ->from('ad_slots', 'slots')
            ->innerJoin('slots', 'sites', 'sites', 'sites.id = slots.site_id')
            ->where('sites.id = :site_id')
            ->andWhere('slots.id = :slot_id')
            ->andWhere('sites.status = :site_status')
            ->andWhere('slots.status = :slot_status')
            ->setParameter('site_id', $siteId)
            ->setParameter('slot_id', $slotId)
            ->setParameter('site_status', 'verified')
            ->setParameter('slot_status', 'active')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row === false ? null : (int) $row['organization_id'];
    }
}
