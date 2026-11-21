<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MediaType;
use App\Repository\MediaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
#[ORM\HasLifecycleCallbacks]
class Media
{
    use TimestampableTrait;

    public const DEFAULT_DURATION_MS = 8000;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 16, enumType: MediaType::class)]
    private MediaType $type;

    /** File name on disk for IMAGE/VIDEO, the external address for URL. */
    #[ORM\Column(length: 2048)]
    private string $storageKey;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $sizeBytes = null;

    /** How long an image or web page stays on screen; videos use their own length. */
    #[ORM\Column]
    private int $durationMs = self::DEFAULT_DURATION_MS;

    public function __construct(Organization $organization, string $name, MediaType $type, string $storageKey)
    {
        $this->id = new Ulid();
        $this->organization = $organization;
        $this->name = mb_substr($name, 0, 120);
        $this->type = $type;
        $this->storageKey = $storageKey;
        $this->initTimestamps();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = mb_substr($name, 0, 120);
    }

    public function getType(): MediaType
    {
        return $this->type;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): void
    {
        $this->mimeType = $mimeType;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(?int $sizeBytes): void
    {
        $this->sizeBytes = $sizeBytes;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function setDurationMs(int $durationMs): void
    {
        $this->durationMs = $durationMs;
    }
}
