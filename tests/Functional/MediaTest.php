<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Media;
use App\Enum\MediaType;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

final class MediaTest extends FunctionalTestCase
{
    public function testUploadServeAndDeleteAnImage(): void
    {
        $user = $this->logIn();
        $crawler = $this->client->request('GET', '/dashboard/media');
        $form = $crawler->filter('form[action$="/upload"]')->form();
        $this->fileField($form)->upload($this->pngFile());
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'File uploaded');

        $media = $this->em()->getRepository(Media::class)->findOneBy([]);
        self::assertNotNull($media);
        self::assertSame(MediaType::Image, $media->getType());
        self::assertSame('image/png', $media->getMimeType());
        self::assertSame('slide', $media->getName());

        // Screens fetch the bytes without logging in.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/media/file/'.$media->getId());
        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        // Videos need HTTP Range to seek.
        $this->client->request('GET', '/api/media/file/'.$media->getId(), server: ['HTTP_RANGE' => 'bytes=0-9']);
        self::assertResponseStatusCodeSame(206);
        self::assertSame('10', $this->client->getResponse()->headers->get('Content-Length'));

        $path = $this->storagePath($media);
        self::assertFileExists($path);

        $this->logIn($user);
        $crawler = $this->client->request('GET', '/dashboard/media');
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseStatusCodeSame(303);
        self::assertFileDoesNotExist($path);
        $this->client->request('GET', '/api/media/file/'.$media->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyImagesAndVideosAreAccepted(): void
    {
        $this->logIn();
        $text = tempnam(sys_get_temp_dir(), 'oc');
        file_put_contents($text, 'not an image');

        $crawler = $this->client->request('GET', '/dashboard/media');
        $form = $crawler->filter('form[action$="/upload"]')->form();
        $this->fileField($form)->upload($text);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=alert]', 'only images and videos');
        self::assertCount(0, $this->em()->getRepository(Media::class)->findAll());
    }

    public function testWebPagesMustBeHttp(): void
    {
        $this->logIn();
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'https://intranet.example.org/news'] as $url) {
            $crawler = $this->client->request('GET', '/dashboard/media');
            $this->client->submit($crawler->selectButton('Add URL')->form(['url' => $url, 'duration' => '12']));
        }

        $media = $this->em()->getRepository(Media::class)->findAll();
        self::assertCount(1, $media);
        self::assertSame('https://intranet.example.org/news', $media[0]->getStorageKey());
        self::assertSame(12000, $media[0]->getDurationMs());
    }

    public function testTheLibraryRequiresSignIn(): void
    {
        $this->client->request('GET', '/dashboard/media');
        self::assertResponseRedirects('/login');
    }

    private function pngFile(): string
    {
        $path = sys_get_temp_dir().'/slide.png';
        $image = imagecreatetruecolor(32, 18);
        imagepng($image, $path);

        return $path;
    }

    private function storagePath(Media $media): string
    {
        return static::getContainer()->getParameter('signageforge.data_dir').'/uploads/'.$media->getStorageKey();
    }

    private function fileField(Form $form): FileFormField
    {
        $fields = $form['files'];
        self::assertIsArray($fields);
        $field = $fields[0] ?? null;
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }
}
