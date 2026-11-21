<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PairingSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A device shows a short code; an admin claims it from the dashboard, which
 * turns it into a Screen that reuses the same deviceToken.
 */
#[ORM\Entity(repositoryClass: PairingSessionRepository::class)]
#[ORM\Table(name: 'pairing_session')]
class PairingSession
{
    public const TTL_SECONDS = 15 * 60;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    /** Short, human-typable code shown on the screen. */
    #[ORM\Column(length: 12, unique: true)]
    private string $code;

    #[ORM\Column(length: 64, unique: true)]
    private string $deviceToken;

    #[ORM\Column]
    private bool $claimed = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Screen $screen = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $code, string $deviceToken, ?string $userAgent, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->id = new Ulid();
        $this->code = $code;
        $this->deviceToken = $deviceToken;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->createdAt = $now;
        $this->expiresAt = $now->modify('+'.self::TTL_SECONDS.' seconds');
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDeviceToken(): string
    {
        return $this->deviceToken;
    }

    public function isClaimed(): bool
    {
        return $this->claimed;
    }

    public function getScreen(): ?Screen
    {
        return $this->screen;
    }

    public function claim(Screen $screen): void
    {
        $this->claimed = true;
        $this->screen = $screen;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt < ($now ?? new \DateTimeImmutable());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
