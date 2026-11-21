<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\Media;
use App\Enum\MediaType;
use App\Repository\MediaRepository;
use App\Service\MediaStorage;
use App\Service\ScreenNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/dashboard/media')]
final class MediaController extends AbstractDashboardController
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly ScreenNotifier $notifier,
    ) {
    }

    #[Route('', name: 'app_media', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/media.html.twig', [
            'media' => $this->media->findBy(['organization' => $this->organization()], ['createdAt' => 'DESC']),
            'max_upload' => self::maxUploadSize(),
        ]);
    }

    #[Route('/upload', name: 'app_media_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $this->requireCsrf($request, 'media-upload');

        $files = $request->files->all('files');
        if ([] === $files) {
            // PHP drops the whole body when it exceeds post_max_size.
            $this->addFlash('error', \sprintf('No file received. Files must be smaller than %s.', self::maxUploadSize()));

            return $this->seeOther('app_media');
        }

        $added = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            if (!$file->isValid()) {
                $this->addFlash('error', \sprintf('%s: %s', $file->getClientOriginalName(), $file->getErrorMessage()));
                continue;
            }

            $mimeType = $file->getMimeType();
            $type = MediaType::fromMimeType($mimeType);
            if (null === $type) {
                $this->addFlash('error', \sprintf('%s: only images and videos are supported.', $file->getClientOriginalName()));
                continue;
            }

            $size = $file->getSize();
            $name = pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME) ?: 'Untitled';
            $media = new Media($this->organization(), $name, $type, $this->storage->store($file));
            $media->setMimeType($mimeType);
            $media->setSizeBytes(false === $size ? null : $size);
            $this->em->persist($media);
            ++$added;
        }
        $this->em->flush();

        if ($added > 0) {
            $this->addFlash('success', 1 === $added ? 'File uploaded.' : \sprintf('%d files uploaded.', $added));
        }

        return $this->seeOther('app_media');
    }

    #[Route('/url', name: 'app_media_url', methods: ['POST'])]
    public function addUrl(Request $request, ValidatorInterface $validator): Response
    {
        $this->requireCsrf($request, 'media-url');

        $url = trim($request->getPayload()->getString('url'));
        $name = trim($request->getPayload()->getString('name')) ?: $url;
        $seconds = $request->getPayload()->getInt('duration', 8);

        $violations = $validator->validate($url, [
            new Assert\NotBlank(),
            new Assert\Length(max: 2048),
            new Assert\Url(protocols: ['http', 'https'], requireTld: false),
        ]);
        if (\count($violations) > 0) {
            $this->addFlash('error', 'Enter a valid http(s) address.');

            return $this->seeOther('app_media');
        }

        $media = new Media($this->organization(), $name, MediaType::Url, $url);
        $media->setDurationMs(max(1, min(3600, $seconds)) * 1000);
        $this->em->persist($media);
        $this->em->flush();
        $this->addFlash('success', 'Web page added.');

        return $this->seeOther('app_media');
    }

    #[Route('/{id}/delete', name: 'app_media_delete', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    public function delete(#[MapEntity(id: 'id')] ?Media $media, Request $request): Response
    {
        $media = $this->owned($media);
        $this->requireCsrf($request, 'media-'.$media->getId());

        // Screens playing a playlist that contains this media must reload.
        $deviceTokens = $this->em->createQuery(
            'SELECT DISTINCT s.deviceToken FROM App\Entity\Screen s
             JOIN s.playlist p JOIN p.items i WHERE i.media = :media'
        )->setParameter('media', $media->getId(), 'ulid')->getSingleColumnResult();

        $storageKey = $media->getType()->isFile() ? $media->getStorageKey() : null;
        $this->em->remove($media);
        $this->em->flush();
        if (null !== $storageKey) {
            $this->storage->delete($storageKey);
        }
        foreach ($deviceTokens as $deviceToken) {
            $this->notifier->notifyDevice($deviceToken, ScreenNotifier::EVENT_PLAYLIST_CHANGED);
        }

        $this->addFlash('success', \sprintf('"%s" was deleted.', $media->getName()));

        return $this->seeOther('app_media');
    }

    /** The effective upload limit, the smaller of the two PHP settings. */
    public static function maxUploadSize(): string
    {
        $bytes = min(UploadedFile::getMaxFilesize(), self::iniBytes((string) \ini_get('post_max_size')));
        if ($bytes <= 0 || \PHP_INT_MAX === $bytes) {
            return 'no limit';
        }

        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : round($bytes / 1024 ** 2).' MB';
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value || '0' === $value) {
            return \PHP_INT_MAX;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
