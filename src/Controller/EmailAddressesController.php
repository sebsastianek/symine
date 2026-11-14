<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Account\EmailAddressBook;
use App\Service\Account\EmailAddressFinder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's EmailAddressesController (additional e-mail addresses of a user, modal on My account). */
final class EmailAddressesController extends AbstractController
{
    public function __construct(
        private readonly EmailAddressFinder $finder,
        private readonly EmailAddressBook $book,
    ) {
    }

    #[Route('/users/{user_id}/email_addresses', name: 'my_email_addresses', requirements: ['user_id' => '\d+'], methods: ['GET'])]
    public function index(Request $request, int $user_id): Response
    {
        $view = $this->book->view($this->finder->user($user_id));
        if ($request->isXmlHttpRequest()) {
            return $this->render('email_addresses/index.js.twig', $view, new Response('', 200, ['Content-Type' => 'text/javascript; charset=utf-8']));
        }

        return $this->render('email_addresses/index.html.twig', $view + ['body_css' => 'controller-email_addresses action-index']);
    }

    #[Route('/users/{user_id}/email_addresses', name: 'my_email_address_create', requirements: ['user_id' => '\d+'], methods: ['POST'])]
    public function create(Request $request, int $user_id): Response
    {
        $user = $this->finder->user($user_id);
        $address = trim((string) ($request->request->all('email_address')['address'] ?? ''));
        $result = $this->book->add($user, $address);
        if (!$request->isXmlHttpRequest() && $result['saved']) {
            return $this->redirectToRoute('my_email_addresses', ['user_id' => $user->id]);
        }
        $view = $this->book->view($user, $result['saved'] ? null : $address, $result['errors']);
        if ($request->isXmlHttpRequest()) {
            return $this->render('email_addresses/index.js.twig', $view, new Response('', 200, ['Content-Type' => 'text/javascript; charset=utf-8']));
        }

        return $this->render('email_addresses/index.html.twig', $view + ['body_css' => 'controller-email_addresses action-index'], new Response('', [] === $result['errors'] ? 200 : 422));
    }

    #[Route('/users/{user_id}/email_addresses/{id}', name: 'my_email_address_update', requirements: ['user_id' => '\d+', 'id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $user_id, int $id): Response
    {
        $user = $this->finder->user($user_id);
        $this->book->setNotify($user, $this->finder->address($user, $id), $request->request->get('notify', $request->query->get('notify')));
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('my_email_addresses', ['user_id' => $user->id]);
        }

        return $this->render('email_addresses/index.js.twig', $this->book->view($user), new Response('', 200, ['Content-Type' => 'text/javascript; charset=utf-8']));
    }

    #[Route('/users/{user_id}/email_addresses/{id}', name: 'my_email_address_destroy', requirements: ['user_id' => '\d+', 'id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $user_id, int $id): Response
    {
        $user = $this->finder->user($user_id);
        $this->book->remove($user, $this->finder->address($user, $id));
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('my_email_addresses', ['user_id' => $user->id]);
        }

        return $this->render('email_addresses/index.js.twig', $this->book->view($user), new Response('', 200, ['Content-Type' => 'text/javascript; charset=utf-8']));
    }
}
