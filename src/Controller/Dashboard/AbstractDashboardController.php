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

#[IsGranted('ROLE_VIEWER')]
abstract class AbstractDashboardController extends AbstractController
{
    protected TenantContext $tenant;

    #[Required]
    public function setTenantContext(TenantContext $tenant): void
    {
        $this->tenant = $tenant;
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
