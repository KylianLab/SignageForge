<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\MembershipRole;

/**
 * Who may change whom. Admins manage viewers, editors and other admins;
 * only owners manage owners; nobody changes their own role or removes
 * themselves. As a result an organization always keeps at least one owner.
 */
final class MemberPolicy
{
    public function canManage(User $actor, User $target): bool
    {
        $actorRole = $actor->getRole();
        $targetRole = $target->getRole();

        return $actor !== $target
            && null !== $actorRole
            && $actorRole->isAtLeast(MembershipRole::Admin)
            && (MembershipRole::Owner !== $targetRole || MembershipRole::Owner === $actorRole);
    }

    /** @return list<MembershipRole> roles $actor may give */
    public function assignableRoles(User $actor): array
    {
        $actorRole = $actor->getRole();
        if (null === $actorRole || !$actorRole->isAtLeast(MembershipRole::Admin)) {
            return [];
        }

        return array_values(array_filter(
            MembershipRole::cases(),
            static fn (MembershipRole $role): bool => $actorRole->isAtLeast($role),
        ));
    }

    public function canAssign(User $actor, User $target, MembershipRole $role): bool
    {
        return $this->canManage($actor, $target) && \in_array($role, $this->assignableRoles($actor), true);
    }
}
