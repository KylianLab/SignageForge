<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\Scheduled;
use App\Entity\ScheduleRule;
use App\Entity\Screen;
use App\Entity\ScreenGroup;
use App\Repository\PlaylistRepository;
use App\Service\ScreenNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/** When a screen, or a group of screens, plays which playlist. */
#[Route('/dashboard/{kind}/{id}/schedule', requirements: ['kind' => 'screens|groups', 'id' => Requirement::ULID])]
final class ScheduleController extends AbstractDashboardController
{
    public function __construct(
        private readonly PlaylistRepository $playlists,
        private readonly EntityManagerInterface $em,
        private readonly ScreenNotifier $notifier,
    ) {
    }

    #[Route('', name: 'app_schedule', methods: ['GET'])]
    public function index(string $kind, string $id): Response
    {
        $owner = $this->owner($kind, $id);

        return $this->render('dashboard/schedule.html.twig', [
            'owner' => $owner,
            'kind' => $kind,
            'playlists' => $this->playlists->findBy(['organization' => $this->organization()], ['name' => 'ASC']),
            'day_names' => ScheduleRule::DAY_NAMES,
        ]);
    }

    #[Route('', name: 'app_schedule_add', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function add(string $kind, string $id, Request $request): Response
    {
        $owner = $this->owner($kind, $id);
        $this->requireCsrf($request, 'schedule-'.$owner->getId());
        $payload = $request->getPayload();

        $playlistId = $payload->getString('playlist');
        $playlist = Ulid::isValid($playlistId) ? $this->playlists->find($playlistId) : null;
        $days = 0;
        foreach ($payload->all('days') as $day) {
            if (is_numeric($day) && (int) $day >= 1 && (int) $day <= 7) {
                $days |= 1 << ((int) $day - 1);
            }
        }
        $allDay = $payload->getBoolean('all_day');
        $start = $allDay ? 0 : ScheduleRule::parseTime($payload->getString('start'));
        $end = $allDay ? 0 : ScheduleRule::parseTime($payload->getString('end'));

        $error = match (true) {
            null === $playlist || $playlist->getOrganization() !== $this->organization() => 'Pick a playlist.',
            0 === $days => 'Pick at least one day.',
            null === $start || null === $end => 'Enter times as HH:MM, for example 08:30.',
            !$allDay && $start === $end => 'Start and end are the same. Tick "All day" instead.',
            default => null,
        };
        if (null !== $error) {
            $this->addFlash('error', $error);

            return $this->back($kind, $owner);
        }

        $owner->addScheduleRule($playlist, $days, $start, $end);
        $this->em->flush();
        $this->notifier->notifyScheduled($owner);
        $this->addFlash('success', 'Schedule rule added.');

        return $this->back($kind, $owner);
    }

    #[Route('/{rule}/delete', name: 'app_schedule_delete', requirements: ['rule' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function delete(string $kind, string $id, #[MapEntity(id: 'rule')] ?ScheduleRule $rule, Request $request): Response
    {
        [$owner, $rule] = $this->ruleOf($kind, $id, $rule);
        $this->requireCsrf($request, 'schedule-'.$owner->getId());

        $owner->removeScheduleRule($rule);
        $this->em->flush();
        $this->notifier->notifyScheduled($owner);

        return $this->back($kind, $owner);
    }

    /** Moves a rule up: earlier rules win when several match. */
    #[Route('/{rule}/up', name: 'app_schedule_up', requirements: ['rule' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function up(string $kind, string $id, #[MapEntity(id: 'rule')] ?ScheduleRule $rule, Request $request): Response
    {
        [$owner, $rule] = $this->ruleOf($kind, $id, $rule);
        $this->requireCsrf($request, 'schedule-'.$owner->getId());

        $rules = array_values($owner->getScheduleRules()->toArray());
        $index = array_search($rule, $rules, true);
        if (\is_int($index) && $index > 0) {
            [$rules[$index - 1], $rules[$index]] = [$rules[$index], $rules[$index - 1]];
            foreach ($rules as $position => $each) {
                $each->setPosition($position);
            }
            $this->em->flush();
            $this->notifier->notifyScheduled($owner);
        }

        return $this->back($kind, $owner);
    }

    private function owner(string $kind, string $id): Scheduled
    {
        $owner = $this->em->find('groups' === $kind ? ScreenGroup::class : Screen::class, $id);

        return $this->owned($owner);
    }

    /** @return array{Scheduled, ScheduleRule} */
    private function ruleOf(string $kind, string $id, ?ScheduleRule $rule): array
    {
        $owner = $this->owner($kind, $id);
        if (null === $rule || $rule->getOwner() !== $owner) {
            throw $this->createNotFoundException();
        }

        return [$owner, $rule];
    }

    private function back(string $kind, Scheduled $owner): Response
    {
        return $this->seeOther('app_schedule', ['kind' => $kind, 'id' => $owner->getId()]);
    }
}
