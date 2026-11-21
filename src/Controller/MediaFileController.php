<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Media;
use App\Service\MediaStorage;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Serves uploaded media to screens (public: screens have no login; ids are
 * unguessable ULIDs). BinaryFileResponse handles HTTP Range, so videos can seek.
 * The path is matched by public/sw.js, which caches it for offline playback.
 */
final class MediaFileController
{
    #[Route('/api/media/file/{id}', name: 'app_media_file', requirements: ['id' => Requirement::ULID], methods: ['GET'])]
    public function __invoke(#[MapEntity(id: 'id')] ?Media $media, MediaStorage $storage): BinaryFileResponse
    {
        if (null === $media || !$media->getType()->isFile() || !$storage->exists($media->getStorageKey())) {
            throw new NotFoundHttpException('Media not found.');
        }

        $response = new BinaryFileResponse(
            $storage->path($media->getStorageKey()),
            headers: ['Content-Type' => $media->getMimeType() ?? 'application/octet-stream'],
            public: true,
            autoEtag: true,
        );
        $response->setMaxAge(31536000);
        $response->setImmutable();
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'media');
        // Uploaded files are untrusted: never let one run scripts on our origin
        // (an SVG opened directly, for instance).
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
