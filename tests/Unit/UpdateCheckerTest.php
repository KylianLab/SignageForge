<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Double\FakeInternet;
use App\Update\UpdateChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UpdateCheckerTest extends TestCase
{
    public function testReadsTheLatestReleaseAndItsPackage(): void
    {
        $checker = new UpdateChecker(new MockHttpClient(new FakeInternet()), new ArrayAdapter(), new NullLogger(), 'example/signageforge');

        $release = $checker->latest();

        self::assertNotNull($release);
        self::assertSame('9.9.9', $release->version);
        self::assertTrue($release->isNewerThan('0.1.0'));
        self::assertFalse($release->isNewerThan('9.9.9'));
        self::assertTrue($release->hasPackage());
        self::assertSame('https://example.test/signageforge-9.9.9.zip', $release->packageUrl);
    }

    public function testNoReleaseYet(): void
    {
        $checker = new UpdateChecker(new MockHttpClient(new MockResponse('', ['http_code' => 404])), new ArrayAdapter(), new NullLogger(), 'example/signageforge');

        self::assertNull($checker->latest());
        self::assertNull($checker->lastError());
    }

    public function testNetworkErrorsAreReportedNotThrown(): void
    {
        $checker = new UpdateChecker(new MockHttpClient(new MockResponse('', ['error' => 'host unreachable'])), new ArrayAdapter(), new NullLogger(), 'example/signageforge');

        self::assertNull($checker->latest());
        self::assertNotNull($checker->lastError());
    }

    public function testChecksCanBeTurnedOff(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('Must not call GitHub.'));
        $checker = new UpdateChecker($client, new ArrayAdapter(), new NullLogger(), '');

        self::assertFalse($checker->isEnabled());
        self::assertNull($checker->latest());
    }
}
