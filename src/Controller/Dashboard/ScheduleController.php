<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\ScheduleRule;
use App\Entity\Screen;
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

/** When a screen plays which playlist. */
#[Route('/dashboard/screens/{id}/schedule', requirements: ['id' => Requirement::ULID])]
final class ScheduleController extends AbstractDashboardController
{
    public function __construct(
        private readonly PlaylistRepository $playlists,
        private readonly EntityManagerInterface $em,
        private readonly ScreenNotifier $notifier,
    ) {
    }

    #[Route('', name: 'app_schedule', methods: ['GET'])]
    public function index(#[MapEntity(id: 'id')] ?Screen $screen): Response
    {
        $screen = $this->owned($screen);

        return $this->render('dashboard/schedule.html.twig', [
            'screen' => $screen,
            'playlists' => $this->playlists->findBy(['organization' => $this->organization()], ['name' => 'ASC']),
            'day_names' => ScheduleRule::DAY_NAMES,
        ]);
    }

    #[Route('', name: 'app_schedule_add', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function add(#[MapEntity(id: 'id')] ?Screen $screen, Request $request): Response
    {
        $screen = $this->owned($screen);
        $this->requireCsrf($request, 'schedule-'.$screen->getId());
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

            return $this->seeOther('app_schedule', ['id' => $screen->getId()]);
        }

        $screen->addScheduleRule($playlist, $days, $start, $end);
        $this->em->flush();
        $this->notifier->notifyScreen($screen, ScreenNotifier::EVENT_PLAYLIST_CHANGED);
        $this->addFlash('success', 'Schedule rule added.');

        return $this->seeOther('app_schedule', ['id' => $screen->getId()]);
    }

    #[Route('/{rule}/delete', name: 'app_schedule_delete', requirements: ['rule' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function delete(#[MapEntity(id: 'id')] ?Screen $screen, #[MapEntity(id: 'rule')] ?ScheduleRule $rule, Request $request): Response
    {
        [$screen, $rule] = $this->ruleOf($screen, $rule);
        $this->requireCsrf($request, 'schedule-'.$screen->getId());

        $screen->removeScheduleRule($rule);
        $this->em->flush();
        $this->notifier->notifyScreen($screen, ScreenNotifier::EVENT_PLAYLIST_CHANGED);

        return $this->seeOther('app_schedule', ['id' => $screen->getId()]);
    }

    /** Moves a rule up: earlier rules win when several match. */
    #[Route('/{rule}/up', name: 'app_schedule_up', requirements: ['rule' => Requirement::ULID], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function up(#[MapEntity(id: 'id')] ?Screen $screen, #[MapEntity(id: 'rule')] ?ScheduleRule $rule, Request $request): Response
    {
        [$screen, $rule] = $this->ruleOf($screen, $rule);
        $this->requireCsrf($request, 'schedule-'.$screen->getId());

        $rules = array_values($screen->getScheduleRules()->toArray());
        $index = array_search($rule, $rules, true);
        if (\is_int($index) && $index > 0) {
            [$rules[$index - 1], $rules[$index]] = [$rules[$index], $rules[$index - 1]];
            foreach ($rules as $position => $each) {
                $each->setPosition($position);
            }
            $this->em->flush();
            $this->notifier->notifyScreen($screen, ScreenNotifier::EVENT_PLAYLIST_CHANGED);
        }

        return $this->seeOther('app_schedule', ['id' => $screen->getId()]);
    }

    /** @return array{Screen, ScheduleRule} */
    private function ruleOf(?Screen $screen, ?ScheduleRule $rule): array
    {
        $screen = $this->owned($screen);
        if (null === $rule || $rule->getScreen() !== $screen) {
            throw $this->createNotFoundException();
        }

        return [$screen, $rule];
    }
}
