<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Media;
use App\Entity\Playlist;
use App\Entity\Screen;
use App\Enum\MediaType;
use App\Service\TenantContext;

final class PlaylistTest extends FunctionalTestCase
{
    public function testBuildAPlaylistAndPlayItOnAScreen(): void
    {
        $organization = $this->tenant()->getOrganization();
        $image = new Media($organization, 'Poster', MediaType::Image, 'poster.png');
        $image->setMimeType('image/png');
        $page = new Media($organization, 'News', MediaType::Url, 'https://example.org/news');
        $page->setDurationMs(5000);
        $screen = new Screen($organization, 'Lobby', 'lobby-device-token-0001');
        foreach ([$image, $page, $screen] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->logIn();
        $crawler = $this->client->request('GET', '/dashboard/playlists');
        $this->client->submit($crawler->selectButton('Create')->form(['name' => 'Hall']));
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        $playlist = $this->em()->getRepository(Playlist::class)->findOneBy(['name' => 'Hall']);
        self::assertNotNull($playlist);

        // What the editor posts: the whole ordered list (page first, poster 3s).
        $token = $crawler->filter('form[data-controller=playlist-editor] input[name=_token]')->attr('value');
        $this->client->request('POST', '/dashboard/playlists/'.$playlist->getId(), [
            '_token' => $token,
            'name' => 'Hall',
            'items' => [
                ['media' => (string) $page->getId(), 'duration' => ''],
                ['media' => (string) $image->getId(), 'duration' => '3'],
                ['media' => 'not-a-media-of-ours', 'duration' => '3'],
            ],
        ]);
        self::assertResponseStatusCodeSame(303);

        // Assign it to the screen.
        $crawler = $this->client->request('GET', '/dashboard');
        $form = $crawler->filter('form[action$="/playlist"]')->form(['playlist' => (string) $playlist->getId()]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);

        $this->client->request('GET', '/api/player/lobby-device-token-0001');
        $config = $this->json();
        self::assertSame('Hall', $config['playlist']['name']);
        self::assertSame(
            [['URL', 'https://example.org/news', 5000], ['IMAGE', '/api/media/file/'.$image->getId(), 3000]],
            array_map(static fn (array $i) => [$i['type'], $i['url'], $i['durationMs']], $config['playlist']['items']),
        );

        // Polling is cheap: same content, same ETag, 304.
        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertNotNull($etag);
        $this->client->request('GET', '/api/player/lobby-device-token-0001', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }

    public function testDeletingAPlaylistClearsItFromScreens(): void
    {
        $organization = $this->tenant()->getOrganization();
        $playlist = new Playlist($organization, 'Old');
        $screen = new Screen($organization, 'Lobby', 'lobby-device-token-0002');
        $screen->setPlaylist($playlist);
        $this->em()->persist($playlist);
        $this->em()->persist($screen);
        $this->em()->flush();

        $this->logIn();
        $crawler = $this->client->request('GET', '/dashboard/playlists/'.$playlist->getId());
        $this->client->submit($crawler->selectButton('Delete playlist')->form());
        self::assertResponseRedirects('/dashboard/playlists', 303);

        $this->client->request('GET', '/api/player/lobby-device-token-0002');
        self::assertNull($this->json()['playlist']);
    }

    public function testFormsWithoutAValidCsrfTokenAreRefused(): void
    {
        $this->logIn();
        $this->client->request('POST', '/dashboard/playlists', ['name' => 'Sneaky', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em()->getRepository(Playlist::class)->findAll());
    }

    public function testUnknownIdsAreNotFound(): void
    {
        $this->logIn();
        $this->client->request('GET', '/dashboard/playlists/01ARZ3NDEKTSV4RRFFQ69G5FAV');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/dashboard/playlists/not-a-ulid');
        self::assertResponseStatusCodeSame(404);
    }

    private function tenant(): TenantContext
    {
        return static::getContainer()->get(TenantContext::class);
    }
}
