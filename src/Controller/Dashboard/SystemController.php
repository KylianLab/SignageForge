<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Maintenance\MaintenanceMode;
use App\Service\RealtimeMode;
use App\Service\ScreenNotifier;
use App\Update\UpdateChecker;
use App\Update\UpdateStatus;
use App\Update\WebUpdater;
use App\Version;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

use function Symfony\Component\Translation\t;

/** Version, updates and health of this installation, for admins. */
#[Route('/dashboard/system')]
#[IsGranted('ROLE_ADMIN')]
final class SystemController extends AbstractDashboardController
{
    public function __construct(
        private readonly UpdateChecker $checker,
        private readonly WebUpdater $webUpdater,
        private readonly UpdateStatus $status,
    ) {
    }

    #[Route('', name: 'app_system', methods: ['GET'])]
    public function index(
        Connection $connection,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')] DependencyFactory $migrations,
        MaintenanceMode $maintenance,
        ScreenNotifier $notifier,
        #[Autowire('%signageforge.data_dir%')] string $dataDir,
    ): Response {
        $release = $this->checker->latest();

        return $this->render('dashboard/system.html.twig', [
            'version' => Version::VERSION,
            'checks_enabled' => $this->checker->isEnabled(),
            'repository' => $this->checker->repository(),
            'release' => $release,
            'update_available' => null !== $release && $release->isNewerThan(Version::VERSION),
            'check_error' => $this->checker->lastError(),
            'web_updates' => $this->webUpdater->isEnabled(),
            'web_update_blocker' => $this->webUpdater->blocker(),
            'status' => $this->status->read(),
            'health' => $this->health($connection, $migrations, $maintenance, $notifier, $dataDir),
        ]);
    }

    #[Route('/check', name: 'app_system_check', methods: ['POST'])]
    public function check(Request $request): Response
    {
        $this->requireCsrf($request, 'system');
        $this->checker->latest(refresh: true);

        return $this->seeOther('app_system');
    }

    #[Route('/update', name: 'app_system_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $this->requireCsrf($request, 'system');
        $release = $this->checker->latest();

        if (null === $release || !$release->isNewerThan(Version::VERSION) || !$release->hasPackage()) {
            $this->flash('error', 'There is no update to install.');
        } else {
            try {
                $this->webUpdater->start($release->version);
                $this->flash('success', 'Updating to %version%. Follow the progress below.', ['%version%' => $release->version]);
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage());
            }
        }

        return $this->seeOther('app_system');
    }

    /** Polled by the page while an update runs (also served during maintenance). */
    #[Route('/update/status', name: 'app_system_update_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json($this->status->read());
    }

    /** @return list<array{label: TranslatableMessage, value: string|TranslatableMessage, ok: bool}> */
    private function health(Connection $connection, DependencyFactory $migrations, MaintenanceMode $maintenance, ScreenNotifier $notifier, string $dataDir): array
    {
        $platform = (new \ReflectionClass($connection->getDatabasePlatform()))->getShortName();
        try {
            $pending = \count($migrations->getMigrationStatusCalculator()->getNewMigrations());
        } catch (\Throwable) {
            $pending = -1;
        }
        $free = @disk_free_space($dataDir);
        $writable = is_writable($dataDir);

        return [
            ['label' => t('PHP'), 'value' => \PHP_VERSION, 'ok' => true],
            ['label' => t('Database'), 'value' => str_replace('Platform', '', $platform), 'ok' => true],
            ['label' => t('Database schema'), 'value' => match (true) {
                0 === $pending => t('up to date'),
                $pending > 0 => t('%count% migration(s) pending: run signageforge:upgrade', ['%count%' => $pending]),
                default => t('unknown'),
            }, 'ok' => 0 === $pending],
            ['label' => t('Media folder'), 'value' => $writable ? t('writable') : t('not writable by the web server'), 'ok' => $writable],
            ['label' => t('Free disk space'), 'value' => false === $free ? t('unknown') : round($free / 1024 ** 3, 1).' GB', 'ok' => false === $free || $free > 1024 ** 3],
            ['label' => t('Real-time updates'), 'value' => RealtimeMode::Mercure === $notifier->getMode() ? t('Mercure hub') : t('polling'), 'ok' => true],
            ['label' => t('Maintenance page'), 'value' => $maintenance->isEnabled() ? t('on') : t('off'), 'ok' => !$maintenance->isEnabled()],
            ['label' => t('Upload limit'), 'value' => MediaController::maxUploadSize(), 'ok' => true],
        ];
    }
}
