<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\MembershipRole;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        (new Filesystem())->remove((string) static::getContainer()->getParameter('signageforge.data_dir'));
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $email = 'admin@example.com', string $password = 'correct-horse', MembershipRole $role = MembershipRole::Editor): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $tenant = static::getContainer()->get(TenantContext::class);

        $user = new User($email);
        $user->setPasswordHash($hasher->hashPassword($user, $password));
        $this->em()->persist($user);
        $tenant->ensureMembership($user, $role);
        $this->em()->flush();

        return $user;
    }

    protected function logIn(?User $user = null): User
    {
        $user ??= $this->createUser();
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($data));

        return $data;
    }
}
