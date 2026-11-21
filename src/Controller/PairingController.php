<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PairingSession;
use App\Repository\PairingSessionRepository;
use App\Service\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A display device with no token opens /pair: it gets a short code to type
 * into the dashboard, and polls until an admin claims it.
 */
final class PairingController extends AbstractController
{
    public function __construct(
        private readonly PairingSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/pair', name: 'app_pair', methods: ['GET'])]
    public function page(): Response
    {
        $dashboardUrl = $this->generateUrl('app_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $qr = (new Builder(writer: new SvgWriter(), data: $dashboardUrl, size: 120, margin: 4))->build();

        return $this->render('pairing/pair.html.twig', [
            'dashboard_url' => $dashboardUrl,
            'qr_data_uri' => $qr->getDataUri(),
        ]);
    }

    #[Route('/api/pair/start', name: 'app_pair_start', methods: ['POST'])]
    public function start(Request $request, TokenGenerator $tokens): JsonResponse
    {
        $this->sessions->purgeStale();

        $code = $tokens->pairingCode();
        for ($i = 0; $i < 5 && null !== $this->sessions->findOneBy(['code' => $code]); ++$i) {
            $code = $tokens->pairingCode();
        }

        $session = new PairingSession($code, $tokens->deviceToken(), $request->headers->get('User-Agent'));
        $this->em->persist($session);
        $this->em->flush();

        return $this->json([
            'code' => $session->getCode(),
            'deviceToken' => $session->getDeviceToken(),
            'expiresInMs' => PairingSession::TTL_SECONDS * 1000,
        ]);
    }

    #[Route('/api/pair/status', name: 'app_pair_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $deviceToken = $request->query->getString('deviceToken');
        if ('' === $deviceToken) {
            return $this->json(['error' => 'deviceToken required'], 400);
        }

        $session = $this->sessions->findOneBy(['deviceToken' => $deviceToken]);

        return $this->json(match (true) {
            null === $session => ['status' => 'unknown'],
            // The Screen reuses the same deviceToken, so the player URL is stable.
            $session->isClaimed() => ['status' => 'claimed', 'deviceToken' => $deviceToken],
            $session->isExpired() => ['status' => 'expired'],
            default => ['status' => 'pending', 'code' => $session->getCode()],
        });
    }
}
