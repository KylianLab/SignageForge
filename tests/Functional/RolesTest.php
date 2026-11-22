<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Playlist;
use App\Entity\User;
use App\Enum\MembershipRole;

final class RolesTest extends FunctionalTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.com'); // first member: owner
    }

    public function testViewersOnlyLook(): void
    {
        $this->logIn($this->createUser('viewer@example.com', role: MembershipRole::Viewer));

        $crawler = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Add screen'));
        self::assertSelectorNotExists('a[href="/dashboard/users"]');

        $crawler = $this->client->request('GET', '/dashboard/media');
        self::assertSelectorNotExists('form[action$="/upload"]');

        foreach (['/dashboard/screens/claim', '/dashboard/media/url', '/dashboard/playlists'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'any']);
            self::assertResponseStatusCodeSame(403, $path);
        }
        $this->client->request('GET', '/dashboard/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEditorsManageContentButNotScreensOrUsers(): void
    {
        $this->logIn($this->createUser('editor@example.com', role: MembershipRole::Editor));

        $crawler = $this->client->request('GET', '/dashboard/playlists');
        $this->client->submit($crawler->selectButton('Create')->form(['name' => 'Lobby']));
        self::assertResponseStatusCodeSame(303);
        self::assertNotNull($this->em()->getRepository(Playlist::class)->findOneBy(['name' => 'Lobby']));

        $crawler = $this->client->request('GET', '/dashboard');
        self::assertCount(0, $crawler->selectButton('Add screen'));
        $this->client->request('POST', '/dashboard/screens/claim', ['_token' => 'any', 'code' => 'ABCDEF']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/dashboard/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminsManageUsers(): void
    {
        $editor = $this->createUser('editor@example.com', role: MembershipRole::Editor);
        $this->logIn($this->createUser('admin@example.com', role: MembershipRole::Admin));

        // Add a local account.
        $crawler = $this->client->request('GET', '/dashboard/users');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Add a local account')->form([
            'email' => 'New@Example.com', 'password' => 'long-enough', 'role' => 'VIEWER',
        ]));
        self::assertResponseStatusCodeSame(303);
        $new = $this->em()->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        self::assertSame(MembershipRole::Viewer, $new?->getRole());

        // Promote the editor to admin.
        $crawler = $this->client->request('GET', '/dashboard/users');
        $this->client->submit($crawler->filter('form[action="/dashboard/users/'.$editor->getId().'/role"]')->form(['role' => 'ADMIN']));
        self::assertResponseStatusCodeSame(303);
        $promoted = $this->em()->getRepository(User::class)->findOneBy(['email' => 'editor@example.com']);
        self::assertSame(MembershipRole::Admin, $promoted?->getRole());

        // An admin cannot touch the owner, nor make owners, nor change themselves.
        self::assertSelectorNotExists('form[action="/dashboard/users/'.$this->owner->getId().'/role"]');
        $token = $crawler->filter('form[action="/dashboard/users/'.$editor->getId().'/role"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/dashboard/users/'.$editor->getId().'/role', ['_token' => $token, 'role' => 'OWNER']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/dashboard/users/'.$this->owner->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnersCanHandOverOwnership(): void
    {
        $admin = $this->createUser('admin@example.com', role: MembershipRole::Admin);
        $this->logIn($this->owner);

        $crawler = $this->client->request('GET', '/dashboard/users');
        self::assertSelectorNotExists('form[action="/dashboard/users/'.$this->owner->getId().'/role"]');
        $this->client->submit($crawler->filter('form[action="/dashboard/users/'.$admin->getId().'/role"]')->form(['role' => 'OWNER']));
        self::assertResponseStatusCodeSame(303);

        $this->em()->clear();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        self::assertSame(MembershipRole::Owner, $admin?->getRole());
    }

    public function testPeopleSigningInForTheFirstTimeAreViewers(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in with LDAP')->form(['username' => 'jdoe', 'password' => 'secret-password']));

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jdoe@example.com']);
        self::assertSame(MembershipRole::Viewer, $user?->getRole());
    }
}
