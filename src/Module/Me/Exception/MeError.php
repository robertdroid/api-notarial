<?php

declare(strict_types=1);

namespace App\Module\Me\Exception;

enum MeError: string
{
    case UserNotFound = 'user_not_found';
    case TenantMismatch = 'tenant_mismatch';
    case UserInactive = 'user_inactive';
    case NotaryInactive = 'notary_inactive';

    public function message(): string
    {
        return match ($this) {
            self::UserNotFound => 'User not found',
            self::TenantMismatch => 'Forbidden',
            self::UserInactive => 'User is inactive',
            self::NotaryInactive => 'Notary is inactive',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::UserNotFound => 404,
            self::TenantMismatch, self::UserInactive, self::NotaryInactive => 403,
        };
    }
}
