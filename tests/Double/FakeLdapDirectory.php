<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Security\LdapDirectoryInterface;
use App\Security\LdapProfile;

/** Stands in for the LDAP server in tests: only jdoe / secret-password exists. */
final class FakeLdapDirectory implements LdapDirectoryInterface
{
    public function authenticate(string $username, string $password): ?LdapProfile
    {
        if ('jdoe' !== $username || 'secret-password' !== $password) {
            return null;
        }

        return new LdapProfile('uid=jdoe,ou=people,dc=example,dc=com', 'jdoe@example.com', 'John Doe');
    }
}
