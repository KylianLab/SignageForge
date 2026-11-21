<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

/**
 * LDAP / Active Directory authentication:
 *  1. bind as a service account (LDAP_BIND_DN / LDAP_BIND_PASSWORD),
 *  2. find the user by LDAP_LOGIN_ATTR ("uid", or "sAMAccountName" for AD),
 *  3. bind again as that user's DN to check the password.
 */
final class LdapDirectory implements LdapDirectoryInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(LDAP_URL)%')] private readonly string $url,
        #[Autowire('%env(LDAP_BASE_DN)%')] private readonly string $baseDn,
        #[Autowire('%env(LDAP_BIND_DN)%')] private readonly string $bindDn,
        #[Autowire('%env(LDAP_BIND_PASSWORD)%')] private readonly string $bindPassword,
        #[Autowire('%env(LDAP_LOGIN_ATTR)%')] private readonly string $loginAttribute,
        #[Autowire('%env(LDAP_EMAIL_ATTR)%')] private readonly string $emailAttribute,
        #[Autowire('%env(LDAP_NAME_ATTR)%')] private readonly string $nameAttribute,
        private readonly ?LdapInterface $ldap = null,
    ) {
    }

    public function authenticate(string $username, string $password): ?LdapProfile
    {
        // An empty password would turn into an anonymous bind and "succeed".
        if ('' === $this->url || '' === trim($username) || '' === $password) {
            return null;
        }

        $ldap = $this->ldap ?? Ldap::create('ext_ldap', ['connection_string' => $this->url]);

        try {
            $ldap->bind($this->bindDn ?: null, $this->bindPassword ?: null);

            $filter = \sprintf('(%s=%s)', $this->loginAttribute, $ldap->escape($username, '', LdapInterface::ESCAPE_FILTER));
            $entries = $ldap->query($this->baseDn, $filter, [
                'filter' => [$this->emailAttribute, $this->nameAttribute],
                'maxItems' => 1,
            ])->execute()->toArray();

            $entry = $entries[0] ?? null;
            if (!$entry instanceof Entry) {
                return null;
            }

            try {
                $ldap->bind($entry->getDn(), $password);
            } catch (ConnectionException) {
                return null; // wrong password
            }
        } catch (\Throwable $e) {
            $this->logger->error('LDAP authentication error: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }

        $email = $this->first($entry, $this->emailAttribute) ?? $username.'@ldap.local';

        return new LdapProfile(
            dn: $entry->getDn(),
            email: $email,
            name: $this->first($entry, $this->nameAttribute) ?? $username,
        );
    }

    private function first(Entry $entry, string $attribute): ?string
    {
        $values = $entry->getAttribute($attribute, false);
        $value = $values[0] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
