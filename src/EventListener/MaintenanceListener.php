<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Maintenance\MaintenanceMode;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/** Answers 503 during maintenance, except for what screens need to keep playing. */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final class MaintenanceListener
{
    // Screens keep playing; the admin page keeps following the update.
    private const ALWAYS_SERVED = ['/api/media/file/', '/assets/', '/sw.js', '/dashboard/system/update/status'];

    public function __construct(
        private readonly MaintenanceMode $maintenance,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->maintenance->isEnabled()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::ALWAYS_SERVED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $response = new Response($this->twig->render('maintenance/index.html.twig'), Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Retry-After', '60');
        $response->headers->set('Cache-Control', 'no-store');
        $event->setResponse($response);
    }
}
