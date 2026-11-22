<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\Screen;
use App\Repository\PairingSessionRepository;
use App\Repository\PlaylistRepository;
use App\Repository\ScreenRepository;
use App\Service\ScreenNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Ulid;

#[Route('/dashboard')]
final class ScreenController extends AbstractDashboardController
{
    public function __construct(
        private readonly ScreenRepository $screens,
        private readonly PlaylistRepository $playlists,
        private readonly EntityManagerInterface $em,
        private readonly ScreenNotifier $notifier,
    ) {
    }

    #[Route('', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/screens.html.twig', $this->listData());
    }

    /** The screen list alone, reloaded periodically to refresh online status. */
    #[Route('/screens/list', name: 'app_screens_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('dashboard/_screen_list.html.twig', $this->listData());
    }

    #[Route('/screens/claim', name: 'app_screens_claim', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function claim(Request $request, PairingSessionRepository $sessions): Response
    {
        $this->requireCsrf($request, 'screen-claim');

        $code = strtoupper(trim($request->getPayload()->getString('code')));
        $name = trim($request->getPayload()->getString('name')) ?: 'New screen';
        $name = mb_substr($name, 0, 80);

        $session = '' === $code ? null : $sessions->findOneBy(['code' => $code]);
        $error = match (true) {
            null === $session => 'Unknown code. Check the code shown on the screen.',
            $session->isClaimed() => 'This code was already used.',
            $session->isExpired() => 'This code has expired. Reload /pair on the screen to get a new one.',
            default => null,
        };
        if (null === $session || null !== $error) {
            $this->addFlash('error', $error);

            return $this->seeOther('app_dashboard');
        }

        $screen = new Screen($this->organization(), $name, $session->getDeviceToken());
        $screen->setUserAgent($session->getUserAgent());
        $this->em->persist($screen);
        $session->claim($screen);
        $this->em->flush();

        $this->addFlash('success', \sprintf('"%s" is paired. It will start playing in a few seconds.', $name));

        return $this->seeOther('app_dashboard');
    }

    #[Route('/screens/{id}/playlist', name: 'app_screens_playlist', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function assignPlaylist(#[MapEntity(id: 'id')] ?Screen $screen, Request $request): Response
    {
        $screen = $this->owned($screen);
        $this->requireCsrf($request, 'screen-'.$screen->getId());

        $playlistId = $request->getPayload()->getString('playlist');
        $playlist = Ulid::isValid($playlistId) ? $this->owned($this->playlists->find($playlistId)) : null;

        $screen->setPlaylist($playlist);
        $this->em->flush();
        $this->notifier->notifyScreen($screen, ScreenNotifier::EVENT_PLAYLIST_CHANGED);

        return $this->seeOther('app_dashboard');
    }

    #[Route('/screens/{id}/delete', name: 'app_screens_delete', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(#[MapEntity(id: 'id')] ?Screen $screen, Request $request): Response
    {
        $screen = $this->owned($screen);
        $this->requireCsrf($request, 'screen-'.$screen->getId());

        $deviceToken = $screen->getDeviceToken();
        $this->em->remove($screen);
        $this->em->flush();
        // The device falls back to its "not paired" state.
        $this->notifier->notifyDevice($deviceToken, ScreenNotifier::EVENT_RELOAD);

        $this->addFlash('success', \sprintf('"%s" was removed.', $screen->getName()));

        return $this->seeOther('app_dashboard');
    }

    /** @return array{screens: list<Screen>, playlists: list<\App\Entity\Playlist>} */
    private function listData(): array
    {
        $organization = $this->organization();

        return [
            'screens' => $this->screens->findBy(['organization' => $organization], ['createdAt' => 'ASC']),
            'playlists' => $this->playlists->findBy(['organization' => $organization], ['name' => 'ASC']),
        ];
    }
}
