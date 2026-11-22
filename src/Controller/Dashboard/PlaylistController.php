<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\Media;
use App\Entity\Playlist;
use App\Repository\MediaRepository;
use App\Repository\PlaylistRepository;
use App\Service\ScreenNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/dashboard/playlists')]
final class PlaylistController extends AbstractDashboardController
{
    public function __construct(
        private readonly PlaylistRepository $playlists,
        private readonly MediaRepository $media,
        private readonly EntityManagerInterface $em,
        private readonly ScreenNotifier $notifier,
    ) {
    }

    #[Route('', name: 'app_playlists', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/playlists/index.html.twig', [
            'playlists' => $this->playlists->findBy(['organization' => $this->organization()], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('', name: 'app_playlists_create', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function create(Request $request): Response
    {
        $this->requireCsrf($request, 'playlist-create');

        $name = mb_substr(trim($request->getPayload()->getString('name')), 0, 80);
        if ('' === $name) {
            $this->flash('error', 'Give the playlist a name.');

            return $this->seeOther('app_playlists');
        }

        $playlist = new Playlist($this->organization(), $name);
        $this->em->persist($playlist);
        $this->em->flush();

        return $this->seeOther('app_playlists_edit', ['id' => $playlist->getId()]);
    }

    #[Route('/{id}', name: 'app_playlists_edit', requirements: ['id' => Requirement::ULID], methods: ['GET'])]
    public function edit(#[MapEntity(id: 'id')] ?Playlist $playlist): Response
    {
        $playlist = $this->owned($playlist);

        return $this->render('dashboard/playlists/edit.html.twig', [
            'playlist' => $playlist,
            'library' => $this->media->findBy(['organization' => $this->organization()], ['createdAt' => 'DESC']),
        ]);
    }

    /** Saves the name and the whole ordered item list at once. */
    #[Route('/{id}', name: 'app_playlists_update', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function update(#[MapEntity(id: 'id')] ?Playlist $playlist, Request $request): Response
    {
        $playlist = $this->owned($playlist);
        $this->requireCsrf($request, 'playlist-'.$playlist->getId());

        $name = mb_substr(trim($request->getPayload()->getString('name')), 0, 80);
        if ('' !== $name) {
            $playlist->setName($name);
        }

        /** @var array<array-key, mixed> $rawItems */
        $rawItems = $request->getPayload()->all('items');
        $mediaById = [];
        foreach ($this->media->findBy(['organization' => $this->organization()]) as $media) {
            $mediaById[(string) $media->getId()] = $media;
        }

        $items = [];
        foreach ($rawItems as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $media = $mediaById[(string) ($raw['media'] ?? '')] ?? null;
            if (!$media instanceof Media) {
                continue;
            }
            $seconds = is_numeric($raw['duration'] ?? null) ? (int) $raw['duration'] : 0;
            $items[] = ['media' => $media, 'durationMs' => max(0, min(3600, $seconds)) * 1000];
        }

        $playlist->replaceItems($items);
        $this->em->flush();
        $this->notifier->notifyPlaylist($playlist);

        $this->flash('success', 'Playlist saved.');

        return $this->seeOther('app_playlists_edit', ['id' => $playlist->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_playlists_delete', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function delete(#[MapEntity(id: 'id')] ?Playlist $playlist, Request $request): Response
    {
        $playlist = $this->owned($playlist);
        $this->requireCsrf($request, 'playlist-'.$playlist->getId());

        // It may play on screens directly, through groups or schedules:
        // let every screen check again.
        $deviceTokens = $this->notifier->deviceTokensOf($this->organization());
        foreach ($playlist->getScreens() as $screen) {
            $screen->setPlaylist(null);
        }
        foreach ($playlist->getGroups() as $group) {
            $group->setPlaylist(null);
        }
        $this->em->remove($playlist);
        $this->em->flush();
        $this->notifier->notifyDevices($deviceTokens);

        $this->flash('success', '"%name%" was deleted.', ['%name%' => $playlist->getName()]);

        return $this->seeOther('app_playlists');
    }
}
