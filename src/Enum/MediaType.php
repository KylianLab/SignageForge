<?php

declare(strict_types=1);

namespace App\Enum;

enum MediaType: string
{
    case Image = 'IMAGE';
    case Video = 'VIDEO';
    case Url = 'URL';

    public static function fromMimeType(?string $mimeType): ?self
    {
        return match (true) {
            null === $mimeType => null,
            str_starts_with($mimeType, 'image/') => self::Image,
            str_starts_with($mimeType, 'video/') => self::Video,
            default => null,
        };
    }

    public function isFile(): bool
    {
        return self::Url !== $this;
    }
}
