<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Membership;
use App\Entity\User;
use App\Enum\AuthSource;
use App\Enum\MembershipRole;

final class SecurityTest extends FunctionalTestCase
{
    public function testTheDashboardRequiresSignIn(): void
    {
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testLocalSignIn(): void
    {
        $this->createUser('ada@example.com', 'correct-horse');

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['email' => 'ADA@example.com', 'password' => 'correct-horse']));
        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('aside', 'ada@example.com');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->createUser('ada@example.com', 'correct-horse');

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['email' => 'ada@example.com', 'password' => 'wrong']));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Sign-in failed');
    }

    public function testLdapSignInCreatesTheUser(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in with LDAP')->form(['username' => 'jdoe', 'password' => 'secret-password']));
        self::assertResponseRedirects('/dashboard');

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jdoe@example.com']);
        self::assertNotNull($user);
        self::assertSame(AuthSource::Ldap, $user->getAuthSource());
        self::assertSame('John Doe', $user->getName());
        self::assertNull($user->getPassword());
        // First member of the organization: owner.
        $membership = $user->getMemberships()->first();
        self::assertInstanceOf(Membership::class, $membership);
        self::assertSame(MembershipRole::Owner, $membership->getRole());
    }

    public function testLdapSignInWithAWrongPassword(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in with LDAP')->form(['username' => 'jdoe', 'password' => 'nope']));
        self::assertResponseRedirects('/login');
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testLdapUsersCannotSignInWithTheLocalForm(): void
    {
        $user = new User('jdoe@example.com', AuthSource::Ldap);
        $this->em()->persist($user);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['email' => 'jdoe@example.com', 'password' => '']));
        self::assertResponseRedirects('/login');
    }

    public function testMicrosoftIsHiddenWhenNotConfigured(): void
    {
        $this->client->request('GET', '/login');
        self::assertSelectorTextNotContains('main', 'Microsoft');
        $this->client->request('GET', '/login/microsoft');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSignOut(): void
    {
        $this->logIn();
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }
}
