<?php

declare(strict_types=1);

namespace App\Module\User\Exception;

use App\Module\User\DTO\UserFieldRules;

enum UserError: string
{
    case UserNotFound = 'user_not_found';
    case UsernameTaken = 'username_taken';
    case CannotDeactivateSelf = 'cannot_deactivate_self';
    case InvalidUsername = 'invalid_username';

    public function message(): string
    {
        return match ($this) {
            self::UserNotFound => 'User not found',
            self::UsernameTaken => 'Username already exists',
            self::CannotDeactivateSelf => 'Cannot deactivate your own account',
            self::InvalidUsername => UserFieldRules::USERNAME_FORMAT_MESSAGE,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidUsername => 400,
            self::CannotDeactivateSelf => 403,
            self::UserNotFound => 404,
            self::UsernameTaken => 409,
        };
    }
}
