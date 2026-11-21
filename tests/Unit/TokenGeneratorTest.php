<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\TokenGenerator;
use PHPUnit\Framework\TestCase;

final class TokenGeneratorTest extends TestCase
{
    public function testPairingCodesAreEasyToType(): void
    {
        $code = (new TokenGenerator())->pairingCode();

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $code);
    }

    public function testDeviceTokensAreUrlSafeAndUnique(): void
    {
        $generator = new TokenGenerator();
        $tokens = array_map(static fn () => $generator->deviceToken(), range(1, 50));

        self::assertCount(50, array_unique($tokens));
        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $token);
        }
    }
}
