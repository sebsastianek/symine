<?php

declare(strict_types=1);

namespace App\Controller;

use App\MailHandler\MailHandlerWebService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of MailHandlerController: incoming e-mails web service used by extra/mail_handler/rdm-mailhandler.rb
 * (POST /mail_handler with key, email and options), enabled by Setting.mail_handler_api_enabled and
 * protected by Setting.mail_handler_api_key. GET /mail_handler shows a test submission form.
 * The route is exempt from CSRF checks and from login_required.
 */
final class MailHandlerController extends BaseController
{
    public function __construct(private readonly MailHandlerWebService $webService)
    {
    }

    #[Route('/mail_handler', name: 'mail_handler', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if (!$this->webService->granted($request)) {
            return new Response(MailHandlerWebService::DENIED, 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($request->isMethod('GET')) {
            return $this->render('mail_handler/new.html.twig', ['key' => $this->webService->key($request)]);
        }

        return new Response('', $this->webService->receive($request) ? 201 : 422);
    }
}
