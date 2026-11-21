<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class HomeTest extends FunctionalTestCase
{
    public function testHomePageLinksToTheDashboardAndPairing(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Self-hosted digital signage');
        self::assertSelectorExists('a[href="/dashboard"]');
        self::assertSelectorExists('a[href="/pair"]');
    }
}
