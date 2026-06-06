<?php

declare(strict_types=1);

namespace VertoAD\Service;

final class PermissionMatcher
{
    /**
     * @param iterable<string> $grants
     */
    public function allows(iterable $grants, string $requiredPermission): bool
    {
        foreach ($grants as $grant) {
            if ($grant === '*' || $grant === $requiredPermission) {
                return true;
            }

            if (str_ends_with($grant, '.*')) {
                $prefix = substr($grant, 0, -1);
                if (str_starts_with($requiredPermission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
