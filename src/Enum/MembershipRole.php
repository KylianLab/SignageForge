<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a member may do. Each role includes the ones below it:
 *  - VIEWER  sees screens, media and playlists
 *  - EDITOR  also manages media and playlists, and assigns playlists to screens
 *  - ADMIN   also pairs and removes screens, and manages users
 *  - OWNER   also manages admins and owners; there is always at least one
 */
enum MembershipRole: string
{
    case Owner = 'OWNER';
    case Admin = 'ADMIN';
    case Editor = 'EDITOR';
    case Viewer = 'VIEWER';

    /** @return list<string> security roles granted, including implied ones */
    public function securityRoles(): array
    {
        return match ($this) {
            self::Owner => ['ROLE_OWNER', 'ROLE_ADMIN', 'ROLE_EDITOR', 'ROLE_VIEWER'],
            self::Admin => ['ROLE_ADMIN', 'ROLE_EDITOR', 'ROLE_VIEWER'],
            self::Editor => ['ROLE_EDITOR', 'ROLE_VIEWER'],
            self::Viewer => ['ROLE_VIEWER'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Editor => 'Editor',
            self::Viewer => 'Viewer',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Editor => 2,
            self::Viewer => 1,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
