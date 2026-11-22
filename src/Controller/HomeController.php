<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * People land on the dashboard (or its sign-in page); screens go straight
     * to /pair, so there is nothing to choose between here.
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('app_dashboard');
    }
}
