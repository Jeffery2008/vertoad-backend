<?php

declare(strict_types=1);

namespace VertoAD\Domain\Auth;

final class Permission
{
    public const string SystemConfigRead = 'system.config.read';
    public const string SystemConfigWrite = 'system.config.write';
    public const string SystemConfigRollback = 'system.config.rollback';
    public const string AuditLogsRead = 'audit.logs.read';
    public const string AuditLogsReadRaw = 'audit.logs.read_raw';
    public const string LedgerRead = 'ledger.read';
    public const string LedgerAdjust = 'ledger.adjust';
    public const string RechargeKeysRead = 'recharge.keys.read';
    public const string RechargeKeysGenerate = 'recharge.keys.generate';
    public const string RechargeKeysRedeem = 'recharge.keys.redeem';
    public const string RechargeKeysViewPlaintext = 'recharge.keys.view_plaintext';
    public const string PublisherSitesRead = 'publisher.sites.read';
    public const string PublisherSitesManage = 'publisher.sites.manage';
    public const string PublisherSitesVerify = 'publisher.sites.verify';
    public const string PublisherSlotsRead = 'publisher.slots.read';
    public const string PublisherSlotsManage = 'publisher.slots.manage';
    public const string CronStatusRead = 'cron.status.read';
    public const string OrganizationMembersRead = 'organizations.members.read';
    public const string OrganizationMembersManage = 'organizations.members.manage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SystemConfigRead,
            self::SystemConfigWrite,
            self::SystemConfigRollback,
            self::AuditLogsRead,
            self::AuditLogsReadRaw,
            self::LedgerRead,
            self::LedgerAdjust,
            self::RechargeKeysRead,
            self::RechargeKeysGenerate,
            self::RechargeKeysRedeem,
            self::RechargeKeysViewPlaintext,
            self::PublisherSitesRead,
            self::PublisherSitesManage,
            self::PublisherSitesVerify,
            self::PublisherSlotsRead,
            self::PublisherSlotsManage,
            self::CronStatusRead,
            self::OrganizationMembersRead,
            self::OrganizationMembersManage,
        ];
    }
}
