<?php

declare(strict_types=1);

namespace App\Service;

final class TokenGenerator
{
    /** No ambiguous characters such as 0/O or 1/I. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Long, URL-safe secret kept by each player. */
    public function deviceToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    /** Short, human-typable pairing code. */
    public function pairingCode(int $length = 6): string
    {
        $code = '';
        $max = \strlen(self::CODE_ALPHABET) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
