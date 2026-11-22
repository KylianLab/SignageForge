<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaylistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'playlist')]
#[ORM\HasLifecycleCallbacks]
class Playlist
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 80)]
    private string $name;

    /** @var Collection<int, PlaylistItem> */
    #[ORM\OneToMany(targetEntity: PlaylistItem::class, mappedBy: 'playlist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    private Collection $items;

    /** @var Collection<int, Screen> */
    #[ORM\OneToMany(targetEntity: Screen::class, mappedBy: 'playlist')]
    private Collection $screens;

    /** @var Collection<int, ScreenGroup> */
    #[ORM\OneToMany(targetEntity: ScreenGroup::class, mappedBy: 'playlist')]
    private Collection $groups;

    public function __construct(Organization $organization, string $name)
    {
        $this->id = new Ulid();
        $this->organization = $organization;
        $this->name = $name;
        $this->items = new ArrayCollection();
        $this->screens = new ArrayCollection();
        $this->groups = new ArrayCollection();
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

    /** @return Collection<int, PlaylistItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** @return Collection<int, Screen> */
    public function getScreens(): Collection
    {
        return $this->screens;
    }

    /** @return Collection<int, ScreenGroup> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    /**
     * Replace the whole ordered item list (simplest reorder model).
     *
     * @param list<array{media: Media, durationMs: int}> $items
     */
    public function replaceItems(array $items): void
    {
        $this->items->clear();
        foreach ($items as $position => $item) {
            $this->items->add(new PlaylistItem($this, $item['media'], $position, $item['durationMs']));
        }
        $this->touch();
    }
}
