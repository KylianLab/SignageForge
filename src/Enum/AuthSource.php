<?php

declare(strict_types=1);

namespace App\Enum;

/** Where a user's identity comes from. */
enum AuthSource: string
{
    case Local = 'local';
    case Ldap = 'ldap';
    case Microsoft = 'microsoft';
}
