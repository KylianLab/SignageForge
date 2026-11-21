<?php

declare(strict_types=1);

namespace App\Enum;

enum MembershipRole: string
{
    case Owner = 'OWNER';
    case Admin = 'ADMIN';
    case Editor = 'EDITOR';
    case Viewer = 'VIEWER';
}
