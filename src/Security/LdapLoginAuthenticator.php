<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\AuthSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/** Username + password checked against LDAP / Active Directory. */
final class LdapLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthProviders $providers,
        private readonly LdapDirectoryInterface $directory,
        private readonly ExternalUserProvisioner $provisioner,
        private readonly LoginSuccessRedirect $redirect,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->providers->ldap()
            && $request->isMethod('POST')
            && 'app_login_ldap' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $username = trim($request->getPayload()->getString('username'));
        $password = $request->getPayload()->getString('password');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        // Check the CSRF token before talking to the directory at all.
        if (!$this->csrf->isTokenValid(new CsrfToken('authenticate', $request->getPayload()->getString('_csrf_token')))) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }

        $profile = $this->directory->authenticate($username, $password);
        if (null === $profile) {
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }

        return new SelfValidatingPassport(
            new UserBadge($profile->email, fn () => $this->provisioner->provision(
                $profile->email,
                $profile->name,
                AuthSource::Ldap,
                $profile->dn,
            )),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
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
