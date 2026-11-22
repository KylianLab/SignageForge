<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Screen;
use App\Entity\ScreenGroup;
use App\Enum\MembershipRole;
use App\Service\TenantContext;
use App\Widget\Overlays;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class WidgetTest extends FunctionalTestCase
{
    private Screen $screen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->screen = new Screen(static::getContainer()->get(TenantContext::class)->getOrganization(), 'Hall', 'widget-device-token-0001');
        $this->em()->persist($this->screen);
        $this->em()->flush();
    }

    public function testEditorsAddAClockTheWeatherAndATicker(): void
    {
        $this->createUser('owner@example.com');
        $this->logIn($this->createUser('editor@example.com', role: MembershipRole::Editor));

        $crawler = $this->client->request('GET', '/dashboard/screens/'.$this->screen->getId().'/widgets');
        $form = $crawler->selectButton('Save')->form([
            'clock_position' => 'top-left',
            'weather_place' => 'Brussels, Belgium',
            'weather_position' => 'top-right',
            'ticker_text' => "Welcome\nLibrary closes at 18:00",
            'ticker_speed' => 'slow',
        ]);
        foreach (['clock', 'weather', 'ticker'] as $box) {
            $field = $form[$box];
            self::assertInstanceOf(ChoiceFormField::class, $field);
            $field->tick();
        }
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);

        $this->client->request('GET', '/api/player/widget-device-token-0001');
        $overlays = $this->json()['overlays'];
        self::assertSame(['position' => 'top-left', 'showDate' => true], $overlays['clock']);
        self::assertSame(['position' => 'top-right', 'label' => 'Brussels, Belgium', 'temperature' => 7, 'condition' => 'rain', 'isDay' => true], $overlays['weather']);
        self::assertSame(['messages' => ['Welcome', 'Library closes at 18:00'], 'secondsPerScreen' => 25], $overlays['ticker']);
    }

    public function testUnknownPlacesAreReported(): void
    {
        $this->logIn();
        $crawler = $this->client->request('GET', '/dashboard/screens/'.$this->screen->getId().'/widgets');
        $form = $crawler->selectButton('Save')->form(['weather_place' => 'Atlantis']);
        $field = $form['weather'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->tick();
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=alert]', 'Could not find "Atlantis"');
        $this->client->request('GET', '/api/player/widget-device-token-0001');
        self::assertArrayNotHasKey('weather', $this->json()['overlays']);
    }

    public function testScreensInAGroupShowTheGroupsWidgets(): void
    {
        $group = new ScreenGroup($this->screen->getOrganization(), 'Lobby');
        $group->setOverlays(Overlays::fromArray(['ticker' => true, 'tickerText' => 'From the group']));
        $this->screen->setOverlays(Overlays::fromArray(['clock' => true]));
        $this->screen->setGroup($group);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->client->request('GET', '/api/player/widget-device-token-0001');
        $overlays = $this->json()['overlays'];
        self::assertArrayNotHasKey('clock', $overlays);
        self::assertSame(['From the group'], $overlays['ticker']['messages']);
    }

    public function testViewersCannotChangeWidgets(): void
    {
        $this->createUser('owner@example.com');
        $this->logIn($this->createUser('viewer@example.com', role: MembershipRole::Viewer));

        $crawler = $this->client->request('GET', '/dashboard/screens/'.$this->screen->getId().'/widgets');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Save'));
        $this->client->request('POST', '/dashboard/screens/'.$this->screen->getId().'/widgets', ['_token' => 'any', 'clock' => '1']);
        self::assertResponseStatusCodeSame(403);
    }
}
