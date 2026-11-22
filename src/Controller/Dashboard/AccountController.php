<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractDashboardController
{
    /**
     * Interface language of the signed-in user; empty follows the browser.
     *
     * @param list<string> $enabledLocales
     */
    #[Route('/dashboard/language', name: 'app_account_language', methods: ['POST'])]
    public function language(
        Request $request,
        EntityManagerInterface $em,
        #[Autowire('%kernel.enabled_locales%')] array $enabledLocales,
    ): RedirectResponse {
        $this->requireCsrf($request, 'language');
        $user = $this->getUser();
        if ($user instanceof User) {
            $locale = $request->getPayload()->getString('locale');
            $user->setLocale(\in_array($locale, $enabledLocales, true) ? $locale : null);
            $em->flush();
        }

        // Back to where the user was, on this site only.
        $back = $request->headers->get('referer', '');
        $path = (string) parse_url($back, \PHP_URL_PATH);

        return new RedirectResponse(str_starts_with($path, '/dashboard') ? $path : $this->generateUrl('app_dashboard'), RedirectResponse::HTTP_SEE_OTHER);
    }
}
