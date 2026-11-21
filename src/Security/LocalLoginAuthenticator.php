<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/** Email + password stored in Signage Forge (the self-hosting admin fallback). */
final class LocalLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthProviders $providers,
        private readonly TenantContext $tenant,
        private readonly EntityManagerInterface $em,
        private readonly LoginSuccessRedirect $redirect,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->providers->localPassword()
            && $request->isMethod('POST')
            && 'app_login_local' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $email = mb_strtolower(trim($request->getPayload()->getString('email')));
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->getPayload()->getString('password')),
            [new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();
        if ($user instanceof User) {
            $this->tenant->ensureMembership($user);
            $this->em->flush();
        }

        return $this->redirect->for($request, $firewallName);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('app_login');
    }

    public function isInteractive(): bool
    {
        return true;
    }
}
