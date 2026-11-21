<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/** Sends a freshly signed-in user back where they were going, or to the dashboard. */
final class LoginSuccessRedirect
{
    use TargetPathTrait;

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function for(Request $request, string $firewallName): RedirectResponse
    {
        $target = $request->hasSession() ? $this->getTargetPath($request->getSession(), $firewallName) : null;

        return new RedirectResponse($target ?: $this->urls->generate('app_dashboard'));
    }
}
