<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Picks the interface language: the one a signed-in user chose, else the
 * browser's preferred one among those available (screens, sign-in page,
 * installer), else English. Runs after the firewall, so the user is known.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class LocaleListener
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        private readonly Security $security,
        private readonly LocaleAwareInterface $translator,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
        #[Autowire('%kernel.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $user = $this->security->getUser();

        $locale = $user instanceof User && \in_array($user->getLocale(), $this->enabledLocales, true)
            ? $user->getLocale()
            : ($request->getPreferredLanguage($this->enabledLocales) ?? $this->defaultLocale);

        $request->setLocale($locale);
        $this->translator->setLocale($locale);
    }
}
