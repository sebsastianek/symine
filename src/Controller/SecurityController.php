<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Account\LoginFailure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'signin', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authUtils, LoginFailure $loginFailure): Response
    {
        if ($this->user()->isLogged()) {
            return $this->redirectBackOr($request, $this->generateUrl('home'));
        }
        $failure = $loginFailure->last();
        if (null !== $failure['flash_html']) {
            $this->addFlash('error_html', $failure['flash_html']);
        }

        return $this->render('account/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error' => $failure['error'],
            'back_url' => $request->query->get('back_url'),
            'body_css' => 'controller-account action-login',
        ]);
    }

    #[Route('/logout', name: 'signout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key on the firewall.');
    }
}
