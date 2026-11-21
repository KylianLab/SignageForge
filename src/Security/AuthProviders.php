<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which sign-in methods this deployment offers, from environment variables.
 * The login page renders only these, and each authenticator refuses to run
 * when its method is off.
 */
final class AuthProviders
{
    public function __construct(
        #[Autowire('%env(bool:AUTH_LOCAL_PASSWORD)%')] private readonly bool $localPassword,
        #[Autowire('%env(ENTRA_CLIENT_ID)%')] private readonly string $entraClientId,
        #[Autowire('%env(ENTRA_CLIENT_SECRET)%')] private readonly string $entraClientSecret,
        #[Autowire('%env(LDAP_URL)%')] private readonly string $ldapUrl,
    ) {
    }

    public function localPassword(): bool
    {
        return $this->localPassword;
    }

    public function microsoft(): bool
    {
        return '' !== $this->entraClientId && '' !== $this->entraClientSecret;
    }

    public function ldap(): bool
    {
        return '' !== $this->ldapUrl;
    }

    public function any(): bool
    {
        return $this->localPassword() || $this->microsoft() || $this->ldap();
    }
}
