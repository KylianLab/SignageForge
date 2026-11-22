<?php

declare(strict_types=1);

namespace App\Controller;

use App\Setup\DatabaseSettings;
use App\Setup\EnvFile;
use App\Setup\Installer;
use App\Setup\SetupToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * First-run setup in the browser: setup token, database, site, first admin.
 * Only reachable until setup is done (see InstallRedirectListener). Answers
 * are kept in the session between steps.
 */
#[Route('/install')]
final class InstallController extends AbstractController
{
    private const SESSION_KEY = 'signageforge_setup';

    public function __construct(
        private readonly SetupToken $token,
        private readonly EnvFile $envFile,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'app_install', methods: ['GET', 'POST'])]
    public function token(Request $request): Response
    {
        $this->token->ensure();
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);
            if ($this->token->isValid($request->getPayload()->getString('token'))) {
                $this->save($request->getSession(), ['token_ok' => true]);

                return $this->redirectToRoute('app_install_database', status: Response::HTTP_SEE_OTHER);
            }
            $error = $this->translator->trans('This is not the setup token.');
        }

        return $this->render('install/token.html.twig', [
            'step' => 1,
            'error' => $error,
            'token_file' => $this->token->file(),
            'env_writable' => $this->envFile->isWritable(),
            'env_file' => $this->envFile->path(),
        ]);
    }

    #[Route('/database', name: 'app_install_database', methods: ['GET', 'POST'])]
    public function database(Request $request): Response
    {
        if (null !== $redirect = $this->requireToken($request)) {
            return $redirect;
        }
        $data = $this->load($request->getSession());
        $settings = new DatabaseSettings(...array_intersect_key($data['database'] ?? [], array_flip(['engine', 'host', 'port', 'name', 'user', 'password', 'serverVersion'])));
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);
            $payload = $request->getPayload();
            $engine = $payload->getString('engine');
            $settings = new DatabaseSettings(
                engine: \in_array($engine, DatabaseSettings::ENGINES, true) ? $engine : 'sqlite',
                host: trim($payload->getString('host')) ?: '127.0.0.1',
                port: trim($payload->getString('port')),
                name: trim($payload->getString('name')) ?: 'signageforge',
                user: trim($payload->getString('user')),
                password: $payload->getString('password'),
                serverVersion: trim($payload->getString('server_version')),
            );

            $error = DatabaseSettings::test($settings->url(), $this->projectDir);
            if (null === $error) {
                $this->save($request->getSession(), ['database' => (array) $settings]);

                return $this->redirectToRoute('app_install_site', status: Response::HTTP_SEE_OTHER);
            }
            $error = $this->translator->trans('Could not connect: %error%', ['%error%' => $error]);
        }

        return $this->render('install/database.html.twig', ['step' => 2, 'db' => $settings, 'error' => $error]);
    }

    #[Route('/site', name: 'app_install_site', methods: ['GET', 'POST'])]
    public function site(Request $request, ValidatorInterface $validator): Response
    {
        if (null !== $redirect = $this->requireToken($request, needs: 'database')) {
            return $redirect;
        }
        $data = $this->load($request->getSession());
        $site = ($data['site'] ?? []) + [
            'public_url' => $request->getSchemeAndHttpHost().$request->getBasePath(),
            'realtime' => 'polling',
            'mercure_url' => '',
        ];
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);
            $payload = $request->getPayload();
            $site = [
                'public_url' => rtrim(trim($payload->getString('public_url')), '/'),
                'realtime' => 'mercure' === $payload->getString('realtime') ? 'mercure' : 'polling',
                'mercure_url' => trim($payload->getString('mercure_url')),
            ];
            if ('mercure' === $site['realtime'] && '' === $site['mercure_url']) {
                $site['mercure_url'] = $site['public_url'].'/.well-known/mercure';
            }

            $urlRule = [new Assert\NotBlank(), new Assert\Url(protocols: ['http', 'https'], requireTld: false)];
            if (\count($validator->validate($site['public_url'], $urlRule)) > 0
                || ('mercure' === $site['realtime'] && \count($validator->validate($site['mercure_url'], $urlRule)) > 0)) {
                $error = $this->translator->trans('Enter full addresses starting with http:// or https://.');
            } else {
                $this->save($request->getSession(), ['site' => $site]);

                return $this->redirectToRoute('app_install_admin', status: Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('install/site.html.twig', ['step' => 3, 'site' => $site, 'error' => $error]);
    }

    #[Route('/admin', name: 'app_install_admin', methods: ['GET', 'POST'])]
    public function admin(Request $request, ValidatorInterface $validator, Installer $installer): Response
    {
        if (null !== $redirect = $this->requireToken($request, needs: 'site')) {
            return $redirect;
        }
        $data = $this->load($request->getSession());
        $email = '';
        $name = '';
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);
            $payload = $request->getPayload();
            $email = mb_strtolower(trim($payload->getString('email')));
            $name = trim($payload->getString('name'));
            $password = $payload->getString('password');

            $error = match (true) {
                \count($validator->validate($email, [new Assert\NotBlank(), new Assert\Email()])) > 0 => 'Enter a valid email address.',
                mb_strlen($password) < 8 => 'The password must have at least 8 characters.',
                $password !== $payload->getString('password_repeat') => 'The two passwords differ.',
                default => null,
            };

            if (null !== $error) {
                $error = $this->translator->trans($error);
            } else {
                $database = new DatabaseSettings(...array_intersect_key($data['database'] ?? [], array_flip(['engine', 'host', 'port', 'name', 'user', 'password', 'serverVersion'])));
                $site = $data['site'];
                $settings = [
                    'DATABASE_URL' => $database->url(),
                    'DEFAULT_URI' => $site['public_url'],
                    'SIGNAGEFORGE_REALTIME' => $site['realtime'],
                ];
                if ('mercure' === $site['realtime']) {
                    $settings['MERCURE_URL'] = $site['mercure_url'];
                    $settings['MERCURE_PUBLIC_URL'] = $site['mercure_url'];
                }

                try {
                    $done = $installer->apply($settings, $email, $name, $password);
                    $request->getSession()->remove(self::SESSION_KEY);

                    return $this->render('install/done.html.twig', ['step' => 5, 'done' => $done, 'email' => $email]);
                } catch (\Throwable $e) {
                    $error = $this->translator->trans('The installation failed:')."
".$e->getMessage();
                }
            }
        }

        return $this->render('install/admin.html.twig', [
            'step' => 4,
            'email' => $email,
            'name' => $name,
            'error' => $error,
            'env_writable' => $this->envFile->isWritable(),
            'env_file' => $this->envFile->path(),
        ]);
    }

    private function requireToken(Request $request, ?string $needs = null): ?Response
    {
        $data = $this->load($request->getSession());
        if (true !== ($data['token_ok'] ?? false)) {
            return $this->redirectToRoute('app_install');
        }
        if (null !== $needs && !isset($data[$needs])) {
            return $this->redirectToRoute('database' === $needs ? 'app_install_database' : 'app_install_site');
        }

        return null;
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('install', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token. Reload the page.');
        }
    }

    /** @return array<string, mixed> */
    private function load(SessionInterface $session): array
    {
        $data = $session->get(self::SESSION_KEY, []);

        return \is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $values */
    private function save(SessionInterface $session, array $values): void
    {
        $session->set(self::SESSION_KEY, $values + $this->load($session));
    }
}
