<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Setup\InstallState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Sends every visitor to the installer until setup is done, and closes it afterwards. */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final class InstallRedirectListener
{
    private const ALWAYS_SERVED = ['/assets/', '/sw.js', '/_wdt', '/_profiler', '/_error'];

    public function __construct(
        private readonly InstallState $state,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::ALWAYS_SERVED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $isInstaller = '/install' === $path || str_starts_with($path, '/install/');
        $installed = $this->state->isInstalled();

        if ($installed && $isInstaller) {
            throw new NotFoundHttpException('Signage Forge is already set up.');
        }
        if (!$installed && !$isInstaller) {
            $event->setResponse(new RedirectResponse($this->urls->generate('app_install')));
        }
    }
}
