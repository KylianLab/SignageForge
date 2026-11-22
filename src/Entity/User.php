<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthSource;
use App\Enum\MembershipRole;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    /** Null for LDAP / Microsoft users: their password lives elsewhere. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(length: 16, enumType: AuthSource::class)]
    private AuthSource $authSource;

    /** Identifier in the external directory (LDAP DN, Entra object id). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    /** @var Collection<int, Membership> */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private Collection $memberships;

    public function __construct(string $email, AuthSource $authSource = AuthSource::Local)
    {
        $this->id = new Ulid();
        $this->email = mb_strtolower(trim($email));
        $this->authSource = $authSource;
        $this->memberships = new ArrayCollection();
        $this->initTimestamps();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getDisplayName(): string
    {
        return $this->name ?: $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getAuthSource(): AuthSource
    {
        return $this->authSource;
    }

    public function setAuthSource(AuthSource $authSource): void
    {
        $this->authSource = $authSource;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId;
    }

    /** @return Collection<int, Membership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(Membership $membership): void
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
        }
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->memberships as $membership) {
            $roles = [...$roles, ...$membership->getRole()->securityRoles()];
        }

        return array_values(array_unique($roles));
    }

    /** The user's role in their organization (single-organization for now). */
    public function getRole(): ?MembershipRole
    {
        $membership = $this->memberships->first();

        return false === $membership ? null : $membership->getRole();
    }

    public function eraseCredentials(): void
    {
    }
}
