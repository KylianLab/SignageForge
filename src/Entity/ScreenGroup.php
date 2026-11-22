<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ScreenGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Screens that show the same thing (the lobby screens, every floor...). The
 * group has a default playlist and a schedule; its screens follow them.
 */
#[ORM\Entity(repositoryClass: ScreenGroupRepository::class)]
#[ORM\Table(name: 'screen_group')]
#[ORM\HasLifecycleCallbacks]
class ScreenGroup implements Scheduled
{
    use TimestampableTrait;
    use ScheduleTrait;
    use OverlaysTrait;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\ManyToOne(inversedBy: 'groups')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Playlist $playlist = null;

    /** @var Collection<int, Screen> */
    #[ORM\OneToMany(targetEntity: Screen::class, mappedBy: 'group')]
    #[ORM\OrderBy(['name' => \SortDirection::Ascending])]
    private Collection $screens;

    /** @var Collection<int, ScheduleRule> */
    #[ORM\OneToMany(targetEntity: ScheduleRule::class, mappedBy: 'group', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    private Collection $scheduleRules;

    public function __construct(Organization $organization, string $name)
    {
        $this->id = new Ulid();
        $this->organization = $organization;
        $this->name = $name;
        $this->screens = new ArrayCollection();
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

    public function getPlaylist(): ?Playlist
    {
        return $this->playlist;
    }

    public function setPlaylist(?Playlist $playlist): void
    {
        $this->playlist = $playlist;
    }

    /** @return Collection<int, Screen> */
    public function getScreens(): Collection
    {
        return $this->screens;
    }

    public function playlistAt(\DateTimeImmutable $localTime): ?Playlist
    {
        return $this->scheduledPlaylistAt($localTime);
    }
}
