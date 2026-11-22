<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Enum\AuthSource;
use App\Enum\MembershipRole;
use App\Repository\UserRepository;
use App\Security\AuthProviders;
use App\Security\MemberPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/dashboard/users')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractDashboardController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly MemberPolicy $policy,
    ) {
    }

    #[Route('', name: 'app_users', methods: ['GET'])]
    public function index(AuthProviders $providers): Response
    {
        $me = $this->currentUser();
        $members = array_values(array_filter(
            $this->users->findBy([], ['email' => 'ASC']),
            fn (User $user): bool => $this->belongsHere($user),
        ));

        return $this->render('dashboard/users.html.twig', [
            'members' => $members,
            'manageable' => array_map(fn (User $user): bool => $this->policy->canManage($me, $user), $members),
            'assignable' => $this->policy->assignableRoles($me),
            'local_accounts' => $providers->localPassword(),
        ]);
    }

    #[Route('', name: 'app_users_create', methods: ['POST'])]
    public function create(Request $request, ValidatorInterface $validator, UserPasswordHasherInterface $hasher): Response
    {
        $this->requireCsrf($request, 'user-create');
        $payload = $request->getPayload();
        $email = mb_strtolower(trim($payload->getString('email')));
        $password = $payload->getString('password');
        $role = MembershipRole::tryFrom($payload->getString('role'));

        $error = match (true) {
            \count($validator->validate($email, [new Assert\NotBlank(), new Assert\Email()])) > 0 => 'Enter a valid email address.',
            null !== $this->users->findOneBy(['email' => $email]) => 'An account with this email already exists.',
            mb_strlen($password) < 8 => 'The password must have at least 8 characters.',
            null === $role || !\in_array($role, $this->policy->assignableRoles($this->currentUser()), true) => 'You cannot give this role.',
            default => null,
        };
        if (null === $role || null !== $error) {
            $this->flash('error', $error);

            return $this->seeOther('app_users');
        }

        $user = new User($email, AuthSource::Local);
        $name = trim($payload->getString('name'));
        $user->setName('' !== $name ? mb_substr($name, 0, 120) : null);
        $user->setPasswordHash($hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->tenant->ensureMembership($user, $role);
        $this->em->flush();

        $this->flash('success', '%email% can now sign in. Give them their password in person.', ['%email%' => $email]);

        return $this->seeOther('app_users');
    }

    #[Route('/{id}/role', name: 'app_users_role', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    public function changeRole(#[MapEntity(id: 'id')] ?User $user, Request $request): Response
    {
        $user = $this->member($user);
        $this->requireCsrf($request, 'user-'.$user->getId());
        $role = MembershipRole::tryFrom($request->getPayload()->getString('role'));

        if (null === $role || !$this->policy->canAssign($this->currentUser(), $user, $role)) {
            throw $this->createAccessDeniedException('You cannot give this role to this person.');
        }

        $membership = $this->tenant->ensureMembership($user);
        $membership->setRole($role);
        $this->em->flush();
        $this->flash('success', '%email% is now %role%.', ['%email%' => $user->getEmail(), '%role%' => $this->translator->trans($role->label())]);

        return $this->seeOther('app_users');
    }

    #[Route('/{id}/delete', name: 'app_users_delete', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    public function delete(#[MapEntity(id: 'id')] ?User $user, Request $request): Response
    {
        $user = $this->member($user);
        $this->requireCsrf($request, 'user-'.$user->getId());

        if (!$this->policy->canManage($this->currentUser(), $user)) {
            throw $this->createAccessDeniedException('You cannot remove this person.');
        }

        $this->em->remove($user);
        $this->em->flush();
        $this->flash('success', '%email% was removed. With LDAP or Microsoft 365 they can still sign in again, as a new member.', ['%email%' => $user->getEmail()]);

        return $this->seeOther('app_users');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function belongsHere(User $user): bool
    {
        foreach ($user->getMemberships() as $membership) {
            if ($membership->getOrganization() === $this->organization()) {
                return true;
            }
        }

        return false;
    }

    private function member(?User $user): User
    {
        if (null === $user || !$this->belongsHere($user)) {
            throw $this->createNotFoundException();
        }

        return $user;
    }
}
