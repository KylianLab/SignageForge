<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Setup\SetupToken;
use Doctrine\ORM\Tools\SchemaTool;

final class InstallerTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink((string) static::getContainer()->getParameter('signageforge.installed_flag'));
        @unlink((string) static::getContainer()->getParameter('signageforge.setup_token_file'));
        @unlink((string) static::getContainer()->getParameter('signageforge.env_local'));
    }

    protected function tearDown(): void
    {
        @unlink((string) static::getContainer()->getParameter('signageforge.env_local'));
        parent::tearDown();
    }

    public function testAFreshSiteSendsEveryoneToTheInstaller(): void
    {
        foreach (['/', '/dashboard', '/login', '/pair'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/install');
        }
    }

    public function testTheSetupTokenIsRequired(): void
    {
        $crawler = $this->client->request('GET', '/install');
        self::assertResponseIsSuccessful();
        self::assertFileExists((string) static::getContainer()->getParameter('signageforge.setup_token_file'));

        $this->client->submit($crawler->selectButton('Continue')->form(['token' => 'guess']));
        self::assertSelectorTextContains('[role=alert]', 'not the setup token');

        $this->client->request('GET', '/install/admin');
        self::assertResponseRedirects('/install');
    }

    public function testTheWholeSetup(): void
    {
        // The installer creates the schema itself through the migrations.
        $em = $this->em();
        (new SchemaTool($em))->dropDatabase();

        $crawler = $this->client->request('GET', '/install');
        $this->client->submit($crawler->selectButton('Continue')->form(['token' => $this->token()]));
        self::assertResponseRedirects('/install/database', 303);

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Test the connection and continue')->form(['engine' => 'sqlite']));
        self::assertResponseRedirects('/install/site', 303);

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Continue')->form([
            'public_url' => 'https://signage.example.org/',
            'realtime' => 'polling',
        ]));
        self::assertResponseRedirects('/install/admin', 303);

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Install Signage Forge')->form([
            'email' => 'Boss@Example.org',
            'name' => 'Boss',
            'password' => 'long-enough-1',
            'password_repeat' => 'long-enough-1',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'ready');

        $env = (string) file_get_contents((string) static::getContainer()->getParameter('signageforge.env_local'));
        self::assertStringContainsString('DATABASE_URL=sqlite:///%kernel.project_dir%/data/signageforge.db', $env);
        self::assertStringContainsString('DEFAULT_URI=https://signage.example.org', $env);
        self::assertStringContainsString('SIGNAGEFORGE_REALTIME=polling', $env);
        self::assertMatchesRegularExpression('/^APP_SECRET=[0-9a-f]{32}$/m', $env);

        $em->clear();
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'boss@example.org']);
        self::assertNotNull($admin);
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        self::assertFileDoesNotExist((string) static::getContainer()->getParameter('signageforge.setup_token_file'));

        // Closed for good, and the site works normally.
        $this->client->request('GET', '/install');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
    }

    public function testMismatchedPasswordsAreRefused(): void
    {
        $crawler = $this->client->request('GET', '/install');
        $this->client->submit($crawler->selectButton('Continue')->form(['token' => $this->token()]));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Test the connection and continue')->form(['engine' => 'sqlite']));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Continue')->form(['public_url' => 'http://localhost']));
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Install Signage Forge')->form([
            'email' => 'boss@example.org',
            'password' => 'long-enough-1',
            'password_repeat' => 'long-enough-2',
        ]));
        self::assertSelectorTextContains('[role=alert]', 'passwords differ');
        self::assertFileDoesNotExist((string) static::getContainer()->getParameter('signageforge.env_local'));
    }

    public function testAnInstalledSiteHidesTheInstaller(): void
    {
        $this->createUser();

        $this->client->request('GET', '/install');
        self::assertResponseStatusCodeSame(404);
    }

    private function token(): string
    {
        static::getContainer()->get(SetupToken::class)->ensure();

        return trim((string) file_get_contents((string) static::getContainer()->getParameter('signageforge.setup_token_file')));
    }
}
