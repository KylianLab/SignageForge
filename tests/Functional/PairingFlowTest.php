<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PairingSession;
use App\Entity\Screen;

final class PairingFlowTest extends FunctionalTestCase
{
    public function testDevicePairsAndReceivesItsPlayerConfig(): void
    {
        // The display asks for a code.
        $this->client->request('POST', '/api/pair/start');
        self::assertResponseIsSuccessful();
        $start = $this->json();
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $start['code']);
        $token = $start['deviceToken'];

        $this->client->request('GET', '/api/pair/status', ['deviceToken' => $token]);
        self::assertSame('pending', $this->json()['status']);

        // Not paired yet: the player says so.
        $this->client->request('GET', '/api/player/'.$token);
        self::assertSame(['paired' => false], $this->json());

        // An admin claims the code from the dashboard.
        $this->logIn();
        $crawler = $this->client->request('GET', '/dashboard');
        $form = $crawler->selectButton('Add screen')->form(['code' => strtolower($start['code']), 'name' => 'Lobby']);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Lobby');

        // The device sees the claim and gets its configuration.
        $this->client->request('GET', '/api/pair/status', ['deviceToken' => $token]);
        self::assertSame(['status' => 'claimed', 'deviceToken' => $token], $this->json());

        $this->client->request('GET', '/api/player/'.$token);
        $config = $this->json();
        self::assertTrue($config['paired']);
        self::assertSame('Lobby', $config['screen']['name']);
        self::assertNull($config['playlist']);

        // The config request is a heartbeat: the screen is now online.
        $screen = $this->em()->getRepository(Screen::class)->findOneBy(['deviceToken' => $token]);
        self::assertNotNull($screen);
        self::assertTrue($screen->isOnline());
    }

    public function testAClaimedCodeCannotBeReused(): void
    {
        $this->client->request('POST', '/api/pair/start');
        $code = $this->json()['code'];
        $this->logIn();

        foreach (['Lobby', 'Second'] as $name) {
            $crawler = $this->client->request('GET', '/dashboard');
            $this->client->submit($crawler->selectButton('Add screen')->form(['code' => $code, 'name' => $name]));
        }
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=alert]', 'already used');
        self::assertCount(1, $this->em()->getRepository(Screen::class)->findAll());
    }

    public function testExpiredAndUnknownCodesAreRejected(): void
    {
        $session = new PairingSession('ABCDEF', 'expired-device-token-0000', null, new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($session);
        $this->em()->flush();

        $this->client->request('GET', '/api/pair/status', ['deviceToken' => 'expired-device-token-0000']);
        self::assertSame('expired', $this->json()['status']);
        $this->client->request('GET', '/api/pair/status', ['deviceToken' => 'nope']);
        self::assertSame('unknown', $this->json()['status']);

        $this->logIn();
        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->selectButton('Add screen')->form(['code' => 'ABCDEF']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'expired');
    }

    public function testRemovingAScreenUnpairsTheDevice(): void
    {
        $this->client->request('POST', '/api/pair/start');
        ['code' => $code, 'deviceToken' => $token] = $this->json();
        $this->logIn();
        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->selectButton('Add screen')->form(['code' => $code]));

        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseStatusCodeSame(303);

        $this->client->request('GET', '/api/player/'.$token);
        self::assertSame(['paired' => false], $this->json());
    }

    public function testPairingAndPlayerPagesArePublic(): void
    {
        $this->client->request('GET', '/pair');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller=pair]');

        $this->client->request('GET', '/player/some-device-token-1234');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller=player]');
    }
}
