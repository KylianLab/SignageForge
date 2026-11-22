<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class HomeTest extends FunctionalTestCase
{
    public function testTheRootGoesToTheDashboard(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/dashboard');

        // Not signed in: on to the sign-in page, which points screens to /pair.
        $this->client->followRedirect();
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'open /pair on that screen');
    }
}
