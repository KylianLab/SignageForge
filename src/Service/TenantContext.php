<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * MVP tenancy: one organization, created automatically on first use. When
 * multi-tenancy lands, this becomes "the organization of the signed-in user".
 */
final class TenantContext
{
    private ?Organization $organization = null;

    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getOrganization(): Organization
    {
        if (null !== $this->organization && $this->em->contains($this->organization)) {
            return $this->organization;
        }

        $organization = $this->organizations->findOneBy([], ['createdAt' => 'ASC']);
        if (null === $organization) {
            $organization = new Organization('My Organization', 'default');
            $this->em->persist($organization);
            $this->em->flush();
        }

        return $this->organization = $organization;
    }

    /**
     * Make sure the user belongs to the organization. The very first member
     * becomes OWNER; later members get $role (EDITOR by default). Idempotent.
     * Does not flush.
     */
    public function ensureMembership(User $user, MembershipRole $role = MembershipRole::Editor): Membership
    {
        $organization = $this->getOrganization();
        foreach ($user->getMemberships() as $membership) {
            if ($membership->getOrganization() === $organization) {
                return $membership;
            }
        }

        $memberCount = $this->em->getRepository(Membership::class)->count(['organization' => $organization]);
        $membership = new Membership($user, $organization, 0 === $memberCount ? MembershipRole::Owner : $role);
        $this->em->persist($membership);

        return $membership;
    }
}
