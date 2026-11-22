<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\AuthSource;
use App\Enum\MembershipRole;
use App\Repository\UserRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'signageforge:user:create', description: 'Create a user who signs in with email and password (or reset an existing one\'s password)')]
final class CreateUserCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TenantContext $tenant,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email address used to sign in')] string $email,
        #[Option('Password (asked for when omitted)')] ?string $password = null,
        #[Option('Display name')] ?string $name = null,
        #[Option('Give the user admin rights (same as --role=admin)')] bool $admin = false,
        #[Option('Role: viewer, editor or admin (default: editor)')] ?string $role = null,
    ): int {
        $email = mb_strtolower(trim($email));
        if (\count($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()])) > 0) {
            $io->error(\sprintf('"%s" is not a valid email address.', $email));

            return Command::INVALID;
        }

        // Lets the web installer pass the password without putting it on the
        // command line, where other users of the server could see it.
        $password ??= ($_SERVER['SIGNAGEFORGE_NEW_PASSWORD'] ?? getenv('SIGNAGEFORGE_NEW_PASSWORD')) ?: null;
        if (null === $password) {
            $password = $io->askHidden('Password (at least 8 characters)', static function (?string $value): string {
                if (null === $value || mb_strlen($value) < 8) {
                    throw new \RuntimeException('The password must have at least 8 characters.');
                }

                return $value;
            });
        }
        if (!\is_string($password) || mb_strlen($password) < 8) {
            $io->error('The password must have at least 8 characters.');

            return Command::INVALID;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        $created = null === $user;
        $user ??= new User($email, AuthSource::Local);
        if (null !== $name) {
            $user->setName($name);
        }
        $user->setPasswordHash($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);

        $wanted = $admin ? MembershipRole::Admin : MembershipRole::tryFrom(strtoupper($role ?? 'editor'));
        if (null === $wanted || MembershipRole::Owner === $wanted) {
            $io->error('--role must be viewer, editor or admin.');

            return Command::INVALID;
        }
        $membership = $this->tenant->ensureMembership($user, $wanted);
        if (null !== $role || $admin) {
            if (MembershipRole::Owner !== $membership->getRole()) {
                $membership->setRole($wanted);
            }
        }
        $this->em->flush();

        $io->success(\sprintf(
            '%s %s (%s).',
            $created ? 'Created' : 'Updated the password of',
            $email,
            strtolower($membership->getRole()->name),
        ));

        return Command::SUCCESS;
    }
}
