<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\Screen;
use App\Entity\ScreenGroup;
use App\Repository\PlaylistRepository;
use App\Repository\ScreenGroupRepository;
use App\Repository\ScreenRepository;
use App\Service\ScreenNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * Groups of screens that show the same content. Admins create groups and
 * choose their screens (like pairing); editors choose what groups play.
 */
#[Route('/dashboard/groups')]
final class GroupController extends AbstractDashboardController
{
    public function __construct(
        private readonly ScreenGroupRepository $groups,
        private readonly ScreenRepository $screens,
        private readonly PlaylistRepository $playlists,
        private readonly EntityManagerInterface $em,
        private readonly ScreenNotifier $notifier,
    ) {
    }

    #[Route('', name: 'app_groups', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/groups/index.html.twig', [
            'groups' => $this->groups->findBy(['organization' => $this->organization()], ['name' => 'ASC']),
        ]);
    }

    #[Route('', name: 'app_groups_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): Response
    {
        $this->requireCsrf($request, 'group-create');
        $name = mb_substr(trim($request->getPayload()->getString('name')), 0, 80);
        if ('' === $name) {
            $this->flash('error', 'Give the group a name.');

            return $this->seeOther('app_groups');
        }

        $group = new ScreenGroup($this->organization(), $name);
        $this->em->persist($group);
        $this->em->flush();

        return $this->seeOther('app_groups_edit', ['id' => $group->getId()]);
    }

    #[Route('/{id}', name: 'app_groups_edit', requirements: ['id' => Requirement::ULID], methods: ['GET'])]
    public function edit(#[MapEntity(id: 'id')] ?ScreenGroup $group): Response
    {
        $group = $this->owned($group);
        $others = array_values(array_filter(
            $this->screens->findBy(['organization' => $this->organization()], ['name' => 'ASC']),
            static fn (Screen $screen): bool => $screen->getGroup() !== $group,
        ));

        return $this->render('dashboard/groups/edit.html.twig', [
            'group' => $group,
            'other_screens' => $others,
            'playlists' => $this->playlists->findBy(['organization' => $this->organization()], ['name' => 'ASC']),
        ]);
    }

    #[Route('/{id}/rename', name: 'app_groups_rename', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rename(#[MapEntity(id: 'id')] ?ScreenGroup $group, Request $request): Response
    {
        $group = $this->owned($group);
        $this->requireCsrf($request, 'group-'.$group->getId());
        $name = mb_substr(trim($request->getPayload()->getString('name')), 0, 80);
        if ('' !== $name) {
            $group->setName($name);
            $this->em->flush();
        }

        return $this->seeOther('app_groups_edit', ['id' => $group->getId()]);
    }

    #[Route('/{id}/playlist', name: 'app_groups_playlist', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function playlist(#[MapEntity(id: 'id')] ?ScreenGroup $group, Request $request): Response
    {
        $group = $this->owned($group);
        $this->requireCsrf($request, 'group-'.$group->getId());
        $playlistId = $request->getPayload()->getString('playlist');

        $group->setPlaylist(Ulid::isValid($playlistId) ? $this->owned($this->playlists->find($playlistId)) : null);
        $this->em->flush();
        $this->notifier->notifyScheduled($group);

        return $this->seeOther('app_groups_edit', ['id' => $group->getId()]);
    }

    /** Puts screens in this group (taking them out of any other group). */
    #[Route('/{id}/screens', name: 'app_groups_add_screens', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addScreens(#[MapEntity(id: 'id')] ?ScreenGroup $group, Request $request): Response
    {
        $group = $this->owned($group);
        $this->requireCsrf($request, 'group-'.$group->getId());

        $added = 0;
        foreach ($request->getPayload()->all('screens') as $screenId) {
            $screen = \is_string($screenId) && Ulid::isValid($screenId) ? $this->screens->find($screenId) : null;
            if ($screen instanceof Screen && $screen->getOrganization() === $this->organization()) {
                $screen->setGroup($group);
                ++$added;
            }
        }
        $this->em->flush();
        $this->notifier->notifyScheduled($group);
        if ($added > 0) {
            $this->flash('success', '%count% screen added to the group.|%count% screens added to the group.', ['%count%' => $added]);
        }

        return $this->seeOther('app_groups_edit', ['id' => $group->getId()]);
    }

    #[Route('/{id}/screens/{screen}/remove', name: 'app_groups_remove_screen', requirements: ['id' => Requirement::ULID, 'screen' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function removeScreen(#[MapEntity(id: 'id')] ?ScreenGroup $group, #[MapEntity(id: 'screen')] ?Screen $screen, Request $request): Response
    {
        $group = $this->owned($group);
        $screen = $this->owned($screen);
        $this->requireCsrf($request, 'group-'.$group->getId());

        if ($screen->getGroup() === $group) {
            $screen->setGroup(null);
            $this->em->flush();
            $this->notifier->notifyScreen($screen, ScreenNotifier::EVENT_PLAYLIST_CHANGED);
        }

        return $this->seeOther('app_groups_edit', ['id' => $group->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_groups_delete', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(#[MapEntity(id: 'id')] ?ScreenGroup $group, Request $request): Response
    {
        $group = $this->owned($group);
        $this->requireCsrf($request, 'group-'.$group->getId());

        $screens = $group->getScreens()->toArray();
        foreach ($screens as $screen) {
            $screen->setGroup(null);
        }
        $this->em->remove($group);
        $this->em->flush();
        foreach ($screens as $screen) {
            $this->notifier->notifyScreen($screen, ScreenNotifier::EVENT_PLAYLIST_CHANGED);
        }
        $this->flash('success', '"%name%" was deleted. Its screens play their own playlist again.', ['%name%' => $group->getName()]);

        return $this->seeOther('app_groups');
    }
}
