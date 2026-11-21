<?php

declare(strict_types=1);

namespace App\Security;

interface LdapDirectoryInterface
{
    /** Returns the user's profile when the credentials are valid, null otherwise. */
    public function authenticate(string $username, string $password): ?LdapProfile;
}
