<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EmailAddress;
use App\Entity\User;
use App\Service\AccountService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's EmailAddressesController (additional e-mail addresses of a user, modal on My account). */
final class EmailAddressesController extends AbstractController
{
    public function __construct(private readonly AccountService $accounts)
    {
    }

    #[Route('/users/{user_id}/email_addresses', name: 'my_email_addresses', requirements: ['user_id' => '\d+'], methods: ['GET'])]
    public function index(Request $request, int $user_id): Response
    {
        $user = $this->findUser($user_id);

        return $this->renderIndex($request, $user, null, []);
    }

    #[Route('/users/{user_id}/email_addresses', name: 'my_email_address_create', requirements: ['user_id' => '\d+'], methods: ['POST'])]
    public function create(Request $request, int $user_id): Response
    {
        $user = $this->findUser($user_id);
        $address = trim((string) ($request->request->all('email_address')['address'] ?? ''));
        $errors = [];
        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM email_addresses WHERE user_id = ?', [$user->id]);
        $saved = false;
        if ($count <= $this->settings->int('max_additional_emails')) {
            $errors = $this->accounts->emailErrors($address, null, $this->l('field_address'));
            if ([] === $errors) {
                $a = new EmailAddress();
                $a->user = $user;
                $a->address = $address;
                $a->isDefault = false;
                $user->emailAddresses->add($a);
                $this->em->persist($a);
                $this->em->flush();
                $saved = true;
            }
        }
        if (!$request->isXmlHttpRequest() && $saved) {
            return $this->redirectToRoute('my_email_addresses', ['user_id' => $user->id]);
        }

        return $this->renderIndex($request, $user, $saved ? null : $address, $errors);
    }

    #[Route('/users/{user_id}/email_addresses/{id}', name: 'my_email_address_update', requirements: ['user_id' => '\d+', 'id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $user_id, int $id): Response
    {
        $user = $this->findUser($user_id);
        $address = $this->findAddress($user, $id);
        $notify = $request->request->get('notify', $request->query->get('notify'));
        if (null !== $notify && '' !== $notify) {
            $address->notify = \in_array((string) $notify, ['1', 'true'], true);
            $this->em->flush();
        }
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('my_email_addresses', ['user_id' => $user->id]);
        }

        return $this->renderIndex($request, $user, null, []);
    }

    #[Route('/users/{user_id}/email_addresses/{id}', name: 'my_email_address_destroy', requirements: ['user_id' => '\d+', 'id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $user_id, int $id): Response
    {
        $user = $this->findUser($user_id);
        $address = $this->findAddress($user, $id);
        $user->emailAddresses->removeElement($address);
        $this->em->remove($address);
        $this->em->flush();
        // EmailAddress#destroy_tokens
        $this->accounts->deleteLoginTokens($user);
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('my_email_addresses', ['user_id' => $user->id]);
        }

        return $this->renderIndex($request, $user, null, []);
    }

    private function findUser(int $id): User
    {
        $this->requireLogin();
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User || !$user->isLogged()) {
            throw $this->createNotFoundException();
        }
        if ($user->id !== $this->user()->id) {
            $this->requireAdmin();
        }

        return $user;
    }

    private function findAddress(User $user, int $id): EmailAddress
    {
        $a = $this->em->getRepository(EmailAddress::class)->findOneBy(['id' => $id, 'user' => $user, 'isDefault' => false]);
        if (null === $a) {
            throw $this->createNotFoundException();
        }

        return $a;
    }

    private function renderIndex(Request $request, User $user, ?string $address, array $errors): Response
    {
        $addresses = $this->em->getRepository(EmailAddress::class)->findBy(['user' => $user, 'isDefault' => false], ['id' => 'ASC']);
        $vars = ['user' => $user, 'addresses' => $addresses, 'address' => $address, 'errors' => $errors];
        if ($request->isXmlHttpRequest()) {
            $html = $this->renderView('email_addresses/_index.html.twig', $vars);
            $esc = static fn (string $s) => strtr($s, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\"', "'" => "\\'"]);
            $js = "\$('#ajax-modal').html('".$esc($html)."');\nshowModal('ajax-modal', '600px', '".$esc($this->l('label_email_address_plural'))."');\n\$('#email_address_address').focus();\n";

            return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('email_addresses/index.html.twig', $vars + ['body_css' => 'controller-email_addresses action-index'], new Response('', [] === $errors ? 200 : 422));
    }
}
