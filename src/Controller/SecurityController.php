<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\AuthProviders;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(AuthenticationUtils $authUtils, AuthProviders $providers): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'providers' => $providers,
            'error' => $authUtils->getLastAuthenticationError(),
            'last_username' => $authUtils->getLastUsername(),
        ]);
    }

    /** Handled by LocalLoginAuthenticator; reached only when that method is off. */
    #[Route('/login/local', name: 'app_login_local', methods: ['POST'])]
    public function loginLocal(): never
    {
        throw new NotFoundHttpException('Email and password sign-in is disabled.');
    }

    /** Handled by LdapLoginAuthenticator; reached only when LDAP is off. */
    #[Route('/login/ldap', name: 'app_login_ldap', methods: ['POST'])]
    public function loginLdap(): never
    {
        throw new NotFoundHttpException('LDAP sign-in is disabled.');
    }

    #[Route('/login/microsoft', name: 'app_login_microsoft', methods: ['GET'])]
    public function loginMicrosoft(ClientRegistry $clients, AuthProviders $providers): RedirectResponse
    {
        if (!$providers->microsoft()) {
            throw new NotFoundHttpException('Microsoft sign-in is disabled.');
        }

        return $clients->getClient('azure')->redirect(['openid', 'profile', 'email'], []);
    }

    /** Handled by MicrosoftAuthenticator. */
    #[Route('/login/microsoft/check', name: 'app_login_microsoft_check', methods: ['GET'])]
    public function loginMicrosoftCheck(): never
    {
        throw new NotFoundHttpException('Microsoft sign-in is disabled.');
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key of the firewall.');
    }
}
