<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ScreenRepository;
use App\Service\PlayerConfigBuilder;
use App\Service\RealtimeMode;
use App\Service\ScreenNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** What a screen runs, fullscreen in a kiosk browser. */
final class PlayerController extends AbstractController
{
    /** Don't write lastSeenAt more often than this (seconds). */
    private const HEARTBEAT_WRITE_INTERVAL = 30;

    public function __construct(
        private readonly ScreenRepository $screens,
        private readonly ScreenNotifier $notifier,
        #[Autowire('%signageforge.poll_interval%')] private readonly int $pollInterval,
    ) {
    }

    #[Route('/player/{token}', name: 'app_player', requirements: ['token' => '[A-Za-z0-9_-]{16,64}'], methods: ['GET'])]
    public function page(string $token): Response
    {
        $mercure = RealtimeMode::Mercure === $this->notifier->getMode();

        return $this->render('player/player.html.twig', [
            'token' => $token,
            'mercure_topic' => $mercure ? ScreenNotifier::topicFor($token) : null,
            'poll_interval_ms' => max(5, $this->pollInterval) * 1000,
        ]);
    }

    /**
     * The player's configuration. Also a heartbeat: it stamps lastSeenAt.
     * Answers 304 when nothing changed since the player's last copy (ETag),
     * which keeps polling cheap.
     */
    #[Route('/api/player/{token}', name: 'app_player_config', requirements: ['token' => '[A-Za-z0-9_-]{16,64}'], methods: ['GET'])]
    public function config(string $token, Request $request, PlayerConfigBuilder $builder, EntityManagerInterface $em): JsonResponse
    {
        $screen = $this->screens->findOneBy(['deviceToken' => $token]);

        if (null !== $screen) {
            $now = new \DateTimeImmutable();
            $last = $screen->getLastSeenAt();
            if (null === $last || $now->getTimestamp() - $last->getTimestamp() >= self::HEARTBEAT_WRITE_INTERVAL) {
                $screen->markSeen($now);
                $userAgent = $request->headers->get('User-Agent');
                if (null !== $userAgent) {
                    $screen->setUserAgent($userAgent);
                }
                $em->flush();
            }
        }

        $response = new JsonResponse($builder->build($screen));
        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->headers->set('Cache-Control', 'no-cache, private');
        $response->isNotModified($request);

        return $response;
    }
}
