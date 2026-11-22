<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\Scheduled;
use App\Entity\Screen;
use App\Entity\ScreenGroup;
use App\Service\ScreenNotifier;
use App\Widget\Overlays;
use App\Widget\WeatherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Clock, weather and ticker drawn over a screen's (or a group's) content. */
#[Route('/dashboard/{kind}/{id}/widgets', requirements: ['kind' => 'screens|groups', 'id' => Requirement::ULID])]
final class WidgetController extends AbstractDashboardController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScreenNotifier $notifier,
        private readonly WeatherService $weather,
    ) {
    }

    #[Route('', name: 'app_widgets', methods: ['GET'])]
    public function index(string $kind, string $id): Response
    {
        $owner = $this->owner($kind, $id);

        return $this->render('dashboard/widgets.html.twig', [
            'owner' => $owner,
            'kind' => $kind,
            'overlays' => $owner->getOverlays(),
            'positions' => Overlays::POSITIONS,
            'speeds' => array_keys(Overlays::SPEEDS),
            'weather_enabled' => $this->weather->isEnabled(),
        ]);
    }

    #[Route('', name: 'app_widgets_save', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function save(string $kind, string $id, Request $request): Response
    {
        $owner = $this->owner($kind, $id);
        $this->requireCsrf($request, 'widgets-'.$owner->getId());
        $payload = $request->getPayload();
        $previous = $owner->getOverlays();

        $data = [
            'clock' => $payload->getBoolean('clock'),
            'clockPosition' => $payload->getString('clock_position'),
            'clockShowDate' => $payload->getBoolean('clock_show_date'),
            'weather' => $payload->getBoolean('weather'),
            'weatherPosition' => $payload->getString('weather_position'),
            'weatherLabel' => $previous->weatherLabel,
            'latitude' => $previous->latitude,
            'longitude' => $previous->longitude,
            'ticker' => $payload->getBoolean('ticker'),
            'tickerText' => $payload->getString('ticker_text'),
            'tickerSpeed' => $payload->getString('ticker_speed'),
        ];

        // Look the place up only when it changed.
        $place = trim($payload->getString('weather_place'));
        if ($data['weather'] && '' !== $place && $place !== $previous->weatherLabel) {
            $found = $this->weather->findPlace($place);
            if (null === $found) {
                $this->flash('error', 'Could not find "%place%". Try "City, Country".', ['%place%' => $place]);
                $data['weather'] = false;
            } else {
                $data['weatherLabel'] = $found['label'];
                $data['latitude'] = $found['latitude'];
                $data['longitude'] = $found['longitude'];
            }
        } elseif ($data['weather'] && '' === $place) {
            $this->flash('error', 'Enter a place for the weather.');
            $data['weather'] = false;
        }

        $owner->setOverlays(Overlays::fromArray($data));
        $this->em->flush();
        $this->notifier->notifyScheduled($owner);
        $this->flash('success', 'Widgets saved.');

        return $this->seeOther('app_widgets', ['kind' => $kind, 'id' => $owner->getId()]);
    }

    private function owner(string $kind, string $id): Scheduled
    {
        return $this->owned($this->em->find('groups' === $kind ? ScreenGroup::class : Screen::class, $id));
    }
}
