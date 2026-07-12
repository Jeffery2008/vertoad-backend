<?php

declare(strict_types=1);

namespace VertoAD\Install;

interface InstallerInterface
{
    /** @return array{installation_id: string, admin_user_id: int, organization_id: int, oauth_client_id: string} */
    public function install(
        InstallInput $input,
        bool $localInstallation,
        ?string $clientIp,
        ?string $userAgent,
        string $requestId,
    ): array;
}
