<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Screen;
use App\Enum\MembershipRole;
use App\Enum\Orientation;
use App\Service\TenantContext;

final class ScreenSettingsTest extends FunctionalTestCase
{
    private Screen $screen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->screen = new Screen(static::getContainer()->get(TenantContext::class)->getOrganization(), 'New screen', 'settings-device-token-01');
        $this->em()->persist($this->screen);
        $this->em()->flush();
    }

    public function testAdminsRenameMoveAndRotateAScreen(): void
    {
        $this->logIn();

        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->click($crawler->selectLink('New screen')->link());
        self::assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Save')->form([
            'name' => 'Lobby, left',
            'location' => 'Main entrance',
            'orientation' => 'PORTRAIT',
        ]));
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'Screen saved');

        // The player turns its content a quarter clockwise.
        $this->client->request('GET', '/api/player/settings-device-token-01');
        $screen = $this->json()['screen'];
        self::assertSame(['name' => 'Lobby, left', 'orientation' => 'PORTRAIT', 'rotation' => 90], $screen);

        $this->client->request('GET', '/dashboard');
        self::assertSelectorTextContains('main', 'Main entrance · 90°');
    }

    public function testEveryOrientationMapsToARotation(): void
    {
        self::assertSame([0, 90, 180, 270], array_map(static fn (Orientation $o): int => $o->rotation(), Orientation::cases()));
    }

    public function testOthersOnlyLook(): void
    {
        $this->createUser('owner@example.com');
        $this->logIn($this->createUser('editor@example.com', role: MembershipRole::Editor));

        $crawler = $this->client->request('GET', '/dashboard/screens/'.$this->screen->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Save'));
        self::assertSelectorExists('#screen-name[disabled]');

        $this->client->request('POST', '/dashboard/screens/'.$this->screen->getId(), ['_token' => 'any', 'name' => 'Hacked']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testANameIsRequired(): void
    {
        $this->logIn();
        $crawler = $this->client->request('GET', '/dashboard/screens/'.$this->screen->getId());
        $this->client->submit($crawler->selectButton('Save')->form(['name' => '  ']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=alert]', 'Give the screen a name');
    }
}
