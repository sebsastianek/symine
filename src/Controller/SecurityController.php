<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'signin', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authUtils): Response
    {
        if ($this->user()->isLogged()) {
            return $this->redirectBackOr($request, $this->generateUrl('home'));
        }
        $error = $authUtils->getLastAuthenticationError();
        $message = null;
        if (null !== $error) {
            $key = $error->getMessageKey();
            $message = match (true) {
                str_starts_with($key, 'notice_') => $this->l($key),
                str_contains($key, 'Too many') => $this->l('notice_account_invalid_credentials').' (rate limit)',
                default => $this->l('notice_account_invalid_credentials'),
            };
        }

        return $this->render('account/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error' => $message,
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
