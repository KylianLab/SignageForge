<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Playlist;
use App\Entity\Screen;
use App\Enum\MembershipRole;
use App\Service\TenantContext;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ScheduleTest extends FunctionalTestCase
{
    use ClockSensitiveTrait;

    public function testAScheduledPlaylistReplacesTheDefaultAtItsTimes(): void
    {
        $organization = static::getContainer()->get(TenantContext::class)->getOrganization();
        $default = new Playlist($organization, 'Default');
        $lunch = new Playlist($organization, 'Lunch menu');
        $screen = new Screen($organization, 'Cafeteria', 'cafeteria-device-token-01');
        $screen->setPlaylist($default);
        foreach ([$default, $lunch, $screen] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->createUser('owner@example.com'); // the first account is the owner
        $this->logIn($this->createUser('editor@example.com', role: MembershipRole::Editor));

        // Weekdays 11:30-14:00: the lunch menu.
        $crawler = $this->client->request('GET', '/dashboard/screens/'.$screen->getId().'/schedule');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Add rule')->form([
            'playlist' => (string) $lunch->getId(),
            'start' => '11:30',
            'end' => '14:00',
        ]));
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Weekdays · 11:30–14:00');

        self::mockTime('2026-11-23 12:15'); // a Monday
        self::assertSame('Lunch menu', $this->playingNow());

        self::mockTime('2026-11-23 15:00');
        self::assertSame('Default', $this->playingNow());

        self::mockTime('2026-11-22 12:15'); // a Sunday
        self::assertSame('Default', $this->playingNow());

        // Viewers see the schedule but cannot change it.
        $this->logIn($this->createUser('viewer@example.com', role: MembershipRole::Viewer));
        $crawler = $this->client->request('GET', '/dashboard/screens/'.$screen->getId().'/schedule');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Add rule'));
    }

    public function testInvalidRulesAreRefused(): void
    {
        $organization = static::getContainer()->get(TenantContext::class)->getOrganization();
        $playlist = new Playlist($organization, 'Any');
        $screen = new Screen($organization, 'Hall', 'hall-device-token-000001');
        $this->em()->persist($playlist);
        $this->em()->persist($screen);
        $this->em()->flush();
        $this->logIn();

        $crawler = $this->client->request('GET', '/dashboard/screens/'.$screen->getId().'/schedule');
        $form = $crawler->selectButton('Add rule')->form(['start' => '10:00', 'end' => '10:00']);
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'same');
        self::assertCount(0, $this->em()->getRepository(Screen::class)->find($screen->getId())?->getScheduleRules() ?? []);
    }

    /** @phpstan-impure */
    private function playingNow(): ?string
    {
        $this->client->request('GET', '/api/player/cafeteria-device-token-01');
        $playlist = $this->json()['playlist'] ?? null;

        return \is_array($playlist) ? $playlist['name'] : null;
    }
}
