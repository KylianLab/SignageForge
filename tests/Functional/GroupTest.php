<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Playlist;
use App\Entity\Screen;
use App\Entity\ScreenGroup;
use App\Enum\MembershipRole;
use App\Service\TenantContext;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class GroupTest extends FunctionalTestCase
{
    use ClockSensitiveTrait;

    private Playlist $own;
    private Playlist $lobby;
    private Playlist $evening;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = static::getContainer()->get(TenantContext::class)->getOrganization();
        $this->own = new Playlist($organization, 'Own');
        $this->lobby = new Playlist($organization, 'Lobby');
        $this->evening = new Playlist($organization, 'Evening');
        foreach ([$this->own, $this->lobby, $this->evening] as $playlist) {
            $this->em()->persist($playlist);
        }
        foreach (['Left', 'Right'] as $name) {
            $screen = new Screen($organization, $name, strtolower($name).'-device-token-00001');
            $screen->setPlaylist($this->own);
            $this->em()->persist($screen);
        }
        $this->em()->flush();
    }

    public function testScreensInAGroupPlayTheGroupsContent(): void
    {
        $this->logIn(); // first account: owner

        // Create the group and put both screens in it.
        $crawler = $this->client->request('GET', '/dashboard/groups');
        $this->client->submit($crawler->selectButton('Create')->form(['name' => 'Lobby screens']));
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Add to the group')->form();
        $checkboxes = $form['screens'];
        self::assertIsArray($checkboxes);
        foreach ($checkboxes as $checkbox) {
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }
        $this->client->submit($form);

        // The group plays "Lobby", and "Evening" from 18:00.
        $crawler = $this->client->request('GET', $this->groupUrl());
        $this->client->submit($crawler->filter('form[action$="/playlist"]')->form(['playlist' => (string) $this->lobby->getId()]));
        $crawler = $this->client->request('GET', $this->groupUrl().'/schedule');
        $this->client->submit($crawler->selectButton('Add rule')->form([
            'playlist' => (string) $this->evening->getId(),
            'days' => ['1', '2', '3', '4', '5', '6', '7'],
            'start' => '18:00',
            'end' => '23:00',
        ]));

        self::mockTime('2026-11-23 10:00');
        self::assertSame('Lobby', $this->playing('left-device-token-00001'));
        self::assertSame('Lobby', $this->playing('right-device-token-00001'));
        self::mockTime('2026-11-23 19:00');
        self::assertSame('Evening', $this->playing('right-device-token-00001'));

        // The screens page says where content comes from.
        $this->client->request('GET', '/dashboard');
        self::assertSelectorTextContains('main', 'Plays the group Lobby screens');

        // A screen that leaves the group plays its own playlist again.
        $crawler = $this->client->request('GET', $this->groupUrl());
        $this->client->submit($crawler->filter('form[action*="/remove"]')->first()->form());
        self::assertSame('Own', $this->playing('left-device-token-00001'));
        self::assertSame('Evening', $this->playing('right-device-token-00001'));

        // Deleting the group frees the others too.
        $crawler = $this->client->request('GET', $this->groupUrl());
        $this->client->submit($crawler->selectButton('Delete group')->form());
        self::assertResponseRedirects('/dashboard/groups', 303);
        self::assertSame('Own', $this->playing('right-device-token-00001'));
    }

    public function testEditorsChooseWhatGroupsPlayButNotWhichScreensAreIn(): void
    {
        $this->createUser('owner@example.com');
        $group = new ScreenGroup(static::getContainer()->get(TenantContext::class)->getOrganization(), 'Hall');
        $this->em()->persist($group);
        $this->em()->flush();
        $this->logIn($this->createUser('editor@example.com', role: MembershipRole::Editor));

        $crawler = $this->client->request('GET', '/dashboard/groups/'.$group->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Delete group'));
        $this->client->submit($crawler->filter('form[action$="/playlist"]')->form(['playlist' => (string) $this->lobby->getId()]));
        self::assertResponseStatusCodeSame(303);

        $token = $crawler->filter('form[action$="/playlist"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/dashboard/groups/'.$group->getId().'/screens', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/dashboard/groups', ['_token' => 'any', 'name' => 'Nope']);
        self::assertResponseStatusCodeSame(403);
    }

    private function groupUrl(): string
    {
        $group = $this->em()->getRepository(ScreenGroup::class)->findOneBy(['name' => 'Lobby screens']);
        self::assertNotNull($group);

        return '/dashboard/groups/'.$group->getId();
    }

    /** @phpstan-impure */
    private function playing(string $deviceToken): ?string
    {
        $this->client->request('GET', '/api/player/'.$deviceToken);
        $playlist = $this->json()['playlist'] ?? null;

        return \is_array($playlist) ? $playlist['name'] : null;
    }
}
