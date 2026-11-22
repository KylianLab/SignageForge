<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Orientation;
use App\Repository\ScreenRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/** A paired display device; it runs /player/{deviceToken}. */
#[ORM\Entity(repositoryClass: ScreenRepository::class)]
#[ORM\Table(name: 'screen')]
#[ORM\HasLifecycleCallbacks]
class Screen implements Scheduled
{
    use TimestampableTrait;
    use ScheduleTrait;

    /** A screen is shown as online if it checked in within this many seconds. */
    public const ONLINE_THRESHOLD_SECONDS = 150;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    /** Long-lived secret the device keeps; it is the player URL. */
    #[ORM\Column(length: 64, unique: true)]
    private string $deviceToken;

    #[ORM\Column(length: 16, enumType: Orientation::class)]
    private Orientation $orientation = Orientation::Landscape;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\ManyToOne(inversedBy: 'screens')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Playlist $playlist = null;

    /** When set, the screen plays what its group plays. */
    #[ORM\ManyToOne(inversedBy: 'screens')]
    #[ORM\JoinColumn(name: 'group_id', nullable: true, onDelete: 'SET NULL')]
    private ?ScreenGroup $group = null;

    /** @var Collection<int, ScheduleRule> */
    #[ORM\OneToMany(targetEntity: ScheduleRule::class, mappedBy: 'screen', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    private Collection $scheduleRules;

    public function __construct(Organization $organization, string $name, string $deviceToken)
    {
        $this->id = new Ulid();
        $this->organization = $organization;
        $this->name = $name;
        $this->deviceToken = $deviceToken;
        $this->scheduleRules = new ArrayCollection();
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
        $this->name = $name;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): void
    {
        $this->location = $location;
    }

    public function getDeviceToken(): string
    {
        return $this->deviceToken;
    }

    public function getOrientation(): Orientation
    {
        return $this->orientation;
    }

    public function setOrientation(Orientation $orientation): void
    {
        $this->orientation = $orientation;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function markSeen(\DateTimeImmutable $at): void
    {
        $this->lastSeenAt = $at;
    }

    public function isOnline(?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->lastSeenAt) {
            return false;
        }
        $now ??= new \DateTimeImmutable();

        return $now->getTimestamp() - $this->lastSeenAt->getTimestamp() <= self::ONLINE_THRESHOLD_SECONDS;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): void
    {
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
    }

    public function getPlaylist(): ?Playlist
    {
        return $this->playlist;
    }

    public function setPlaylist(?Playlist $playlist): void
    {
        $this->playlist = $playlist;
    }

    public function getGroup(): ?ScreenGroup
    {
        return $this->group;
    }

    public function setGroup(?ScreenGroup $group): void
    {
        $this->group = $group;
    }

    /**
     * The playlist to play now. A screen in a group follows the group;
     * otherwise its own schedule, then its own default playlist.
     */
    public function playlistAt(\DateTimeImmutable $localTime): ?Playlist
    {
        return null !== $this->group
            ? $this->group->playlistAt($localTime)
            : $this->scheduledPlaylistAt($localTime);
    }
}
