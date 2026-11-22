<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\Organization;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_VIEWER')]
abstract class AbstractDashboardController extends AbstractController
{
    protected TenantContext $tenant;
    protected TranslatorInterface $translator;

    #[Required]
    public function setTenantContext(TenantContext $tenant): void
    {
        $this->tenant = $tenant;
    }

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * A message for the next page, in the user's language.
     *
     * @param array<string, string|int> $parameters
     */
    protected function flash(string $type, string $message, array $parameters = []): void
    {
        $this->addFlash($type, $this->translator->trans($message, $parameters));
    }

    protected function organization(): Organization
    {
        return $this->tenant->getOrganization();
    }

    /**
     * 404 unless the entity belongs to the current organization.
     *
     * @template T of object
     *
     * @param T|null $entity
     *
     * @return T
     */
    protected function owned(?object $entity): object
    {
        if (null === $entity
            || !method_exists($entity, 'getOrganization')
            || $entity->getOrganization() !== $this->organization()) {
            throw new NotFoundHttpException();
        }

        return $entity;
    }

    /**
     * Redirect after a form post with 303 See Other (what Turbo expects).
     *
     * @param array<string, mixed> $parameters
     */
    protected function seeOther(string $route, array $parameters = []): RedirectResponse
    {
        return $this->redirectToRoute($route, $parameters, Response::HTTP_SEE_OTHER);
    }

    protected function requireCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token. Reload the page and try again.');
        }
    }
}
