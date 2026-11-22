<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\MembershipRole;
use App\Update\UpdateStatus;

final class SystemTest extends FunctionalTestCase
{
    public function testAdminsSeeVersionUpdatesAndHealth(): void
    {
        $this->logIn();

        $crawler = $this->client->request('GET', '/dashboard/system');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Version 0.1.0');
        self::assertSelectorTextContains('main', 'Version 9.9.9 is available');
        // One-click updates are off by default: the page gives the commands.
        self::assertSelectorTextContains('main', 'signageforge:self-update');
        self::assertCount(0, $crawler->selectButton('Install 9.9.9'));
        self::assertSelectorTextContains('main', 'Database schema');
    }

    public function testOthersCannotSeeIt(): void
    {
        $this->createUser('owner@example.com');
        $this->logIn($this->createUser('editor@example.com', role: MembershipRole::Editor));

        $this->client->request('GET', '/dashboard/system');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/dashboard/system/update', ['_token' => 'any']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheUpdateStatusIsReadable(): void
    {
        static::getContainer()->get(UpdateStatus::class)->start('9.9.9', 'Downloading');
        $this->logIn();

        $this->client->request('GET', '/dashboard/system/update/status');
        self::assertResponseIsSuccessful();
        $status = $this->json();
        self::assertSame('running', $status['state']);
        self::assertSame(['Downloading'], $status['log']);

        static::getContainer()->get(UpdateStatus::class)->finish(true, 'Done');
    }
}
