<?php

declare(strict_types=1);

namespace App\Security;

final readonly class LdapProfile
{
    public function __construct(
        public string $dn,
        public string $email,
        public string $name,
    ) {
    }
}
