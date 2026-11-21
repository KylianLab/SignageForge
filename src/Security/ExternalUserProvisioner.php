<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\AuthSource;
use App\Repository\UserRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps a local mirror of users who sign in through LDAP or Microsoft 365. No
 * password is stored for them. An existing account with the same email is
 * reused, so an admin can pre-create users before they first sign in.
 */
final class ExternalUserProvisioner
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenant,
    ) {
    }

    public function provision(string $email, ?string $name, AuthSource $source, ?string $externalId): User
    {
        $email = mb_strtolower(trim($email));
        $user = $this->users->findOneBy(['email' => $email]);
        if (null === $user) {
            $user = new User($email, $source);
            $this->em->persist($user);
        }
        if (null !== $name && '' !== $name) {
            $user->setName($name);
        }
        if (null === $user->getPassword()) {
            $user->setAuthSource($source);
        }
        $user->setExternalId($externalId);
        $this->tenant->ensureMembership($user);
        $this->em->flush();

        return $user;
    }
}
