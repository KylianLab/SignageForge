<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\AuthSource;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

/** Microsoft 365 single sign-on (Microsoft Entra ID, OpenID Connect). */
final class MicrosoftAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly AuthProviders $providers,
        private readonly ExternalUserProvisioner $provisioner,
        private readonly LoginSuccessRedirect $redirect,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->providers->microsoft()
            && 'app_login_microsoft_check' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clients->getClient('azure');
        $accessToken = $this->fetchAccessToken($client);

        $owner = $client->fetchUserFromToken($accessToken);
        if (!$owner instanceof AzureResourceOwner) {
            throw new CustomUserMessageAuthenticationException('Unexpected Microsoft profile.');
        }

        $email = $owner->getEmail() ?? $owner->getUpn() ?? $owner->claim('preferred_username');
        if (!\is_string($email) || !str_contains($email, '@')) {
            throw new CustomUserMessageAuthenticationException('Your Microsoft account has no email address.');
        }
        $name = $owner->claim('name');
        $objectId = $owner->getId();

        return new SelfValidatingPassport(new UserBadge($email, fn () => $this->provisioner->provision(
            $email,
            \is_string($name) ? $name : null,
            AuthSource::Microsoft,
            \is_string($objectId) ? $objectId : null,
        )));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return $this->redirect->for($request, $firewallName);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urls->generate('app_login'));
    }
}
