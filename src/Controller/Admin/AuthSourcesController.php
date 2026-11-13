<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\AuthSource;
use App\Service\LdapService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's AuthSourcesController (LDAP authentication modes). */
final class AuthSourcesController extends AbstractController
{
    public function __construct(private readonly LdapService $ldap)
    {
    }

    #[Route('/auth_sources', name: 'auth_sources_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        $count = (int) $this->em->createQuery('SELECT COUNT(a.id) FROM App\Entity\AuthSource a')->getSingleScalarResult();
        $pager = $this->paginate($request, $count);
        $pager['per_page_options'] = [25];
        $pager['limit'] = 25;
        $pager['pages'] = max(1, (int) ceil($count / 25));
        $pager['page'] = max(1, min($pager['pages'], (int) $request->query->get('page', 1)));
        $pager['offset'] = ($pager['page'] - 1) * 25;
        $sources = $this->em->createQuery('SELECT a FROM App\Entity\AuthSource a ORDER BY a.id')
            ->setFirstResult($pager['offset'])->setMaxResults(25)->getResult();
        $userCounts = [];
        foreach ($this->em->createQuery('SELECT u.authSourceId AS id, COUNT(u.id) AS n FROM App\Entity\User u WHERE u.authSourceId IS NOT NULL GROUP BY u.authSourceId')->getArrayResult() as $row) {
            $userCounts[(int) $row['id']] = (int) $row['n'];
        }

        return $this->render('admin/auth_sources/index.html.twig', [
            'auth_sources' => $sources,
            'user_counts' => $userCounts,
            'pager' => $pager,
            'admin_menu_selected' => 'ldap_authentication',
            'page_title' => $this->l('label_auth_source_plural'),
            'body_css' => 'controller-auth_sources action-index',
        ]);
    }

    #[Route('/auth_sources/new', name: 'auth_source_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $source = $this->build($request->query->get('type'));
        $source->port = 389;
        $this->assign($source, $request->query->all('auth_source'));

        return $this->renderForm($source);
    }

    #[Route('/auth_sources', name: 'auth_source_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $source = $this->build($request->request->get('type'));
        $this->assign($source, $request->request->all('auth_source'));
        $errors = $this->validate($source);
        if ([] !== $errors) {
            return $this->renderForm($source, $errors);
        }
        $this->em->persist($source);
        $this->em->flush();
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('auth_sources_index');
    }

    #[Route('/auth_sources/{id}/edit', name: 'auth_source_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->renderForm($this->find($id));
    }

    #[Route('/auth_sources/{id}', name: 'auth_source_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT', 'POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $source = $this->find($id);
        $this->assign($source, $request->request->all('auth_source'));
        $errors = $this->validate($source);
        if ([] !== $errors) {
            $response = $this->renderForm($source, $errors);
            $this->em->detach($source);

            return $response;
        }
        $this->em->flush();
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('auth_sources_index');
    }

    #[Route('/auth_sources/{id}/test_connection', name: 'auth_source_test_connection', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function testConnection(int $id): Response
    {
        $this->requireAdmin();
        $source = $this->find($id);
        try {
            $this->ldap->testConnection($source);
            $this->flashNotice('notice_successful_connection');
        } catch (\Throwable $e) {
            $this->flashError($this->l('error_unable_to_connect', ['value' => $e->getMessage()]));
        }

        return $this->redirectToRoute('auth_sources_index');
    }

    #[Route('/auth_sources/{id}', name: 'auth_source_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $source = $this->find($id);
        $used = (int) $this->em->createQuery('SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.authSourceId = :id')->setParameter('id', $source->id)->getSingleScalarResult();
        if (0 === $used) {
            $this->em->remove($source);
            $this->em->flush();
            $this->flashNotice('notice_successful_delete');
        } else {
            $this->flashError($this->l('error_can_not_delete_auth_source'));
        }

        return $this->redirectToRoute('auth_sources_index');
    }

    #[Route('/auth_sources/autocomplete_for_new_user', name: 'auth_source_autocomplete_for_new_user', methods: ['GET'])]
    public function autocompleteForNewUser(Request $request): JsonResponse
    {
        $this->requireAdmin();
        $json = [];
        foreach ($this->ldap->search((string) $request->query->get('term', '')) as $r) {
            $json[] = [
                'value' => $r['login'],
                'label' => \sprintf('%s (%s %s)', $r['login'], $r['firstname'], $r['lastname']),
                'login' => (string) $r['login'],
                'firstname' => (string) $r['firstname'],
                'lastname' => (string) $r['lastname'],
                'mail' => (string) $r['mail'],
                'auth_source_id' => (string) $r['auth_source_id'],
            ];
        }

        return new JsonResponse($json);
    }

    private function build(mixed $type): AuthSource
    {
        if (null !== $type && '' !== $type && 'AuthSourceLdap' !== $type) {
            throw $this->createNotFoundException();
        }
        $source = new AuthSource();
        $source->type = 'AuthSourceLdap';

        return $source;
    }

    private function find(int $id): AuthSource
    {
        return $this->em->find(AuthSource::class, $id) ?? throw $this->createNotFoundException();
    }

    /** safe_attributes of AuthSource / AuthSourceLdap */
    private function assign(AuthSource $s, array $a): void
    {
        $str = static fn (string $k) => \array_key_exists($k, $a) ? trim((string) $a[$k]) : null;
        foreach (['name' => 'name', 'host' => 'host', 'account' => 'account', 'base_dn' => 'baseDn', 'filter' => 'filter'] as $k => $prop) {
            if (null !== ($v = $str($k))) {
                $s->{$prop} = $v;
            }
        }
        foreach (['attr_login' => 'attrLogin', 'attr_firstname' => 'attrFirstname', 'attr_lastname' => 'attrLastname', 'attr_mail' => 'attrMail'] as $k => $prop) {
            if (null !== ($v = $str($k))) {
                $s->{$prop} = $v;
            }
        }
        if (\array_key_exists('account_password', $a)) {
            $s->accountPassword = (string) $a['account_password'];
        }
        if (null !== ($v = $str('port'))) {
            $s->port = '' === $v ? null : (ctype_digit($v) ? (int) $v : -1);
        }
        if (null !== ($v = $str('timeout'))) {
            $s->timeout = '' === $v ? null : (ctype_digit($v) ? (int) $v : -1);
        }
        if (\array_key_exists('onthefly_register', $a)) {
            $s->ontheflyRegister = \in_array($a['onthefly_register'], ['1', 1, true, 'true'], true);
        }
        if (\array_key_exists('ldap_mode', $a)) {
            LdapService::setLdapMode($s, (string) $a['ldap_mode']);
        } else {
            if (\array_key_exists('tls', $a)) {
                $s->tls = \in_array($a['tls'], ['1', 1, true, 'true'], true);
            }
            if (\array_key_exists('verify_peer', $a)) {
                $s->verifyPeer = \in_array($a['verify_peer'], ['1', 1, true, 'true'], true);
            }
        }
    }

    /** @return list<string> */
    private function validate(AuthSource $s): array
    {
        $errors = [];
        $blank = $this->l('activerecord.errors.messages.blank');
        $f = fn (string $k) => $this->l('field_'.$k);
        if ('' === $s->name) {
            $errors[] = $f('name').' '.$blank;
        } elseif (mb_strlen($s->name) > 60) {
            $errors[] = $f('name').' '.$this->l('activerecord.errors.messages.too_long', ['count' => 60]);
        } else {
            $dup = $this->em->createQuery('SELECT COUNT(a.id) FROM App\Entity\AuthSource a WHERE a.name = :n AND a.id <> :id')
                ->setParameters(['n' => $s->name, 'id' => $s->id ?? 0])->getSingleScalarResult();
            if ($dup > 0) {
                $errors[] = $f('name').' '.$this->l('activerecord.errors.messages.taken');
            }
        }
        if ('' === (string) $s->host) {
            $errors[] = $f('host').' '.$blank;
        } elseif (mb_strlen((string) $s->host) > 60) {
            $errors[] = $f('host').' '.$this->l('activerecord.errors.messages.too_long', ['count' => 60]);
        }
        if (null === $s->port) {
            $errors[] = $f('port').' '.$blank;
        } elseif ($s->port < 0) {
            $errors[] = $f('port').' '.$this->l('activerecord.errors.messages.not_a_number');
        }
        if ('' === (string) $s->attrLogin) {
            $errors[] = $f('attr_login').' '.$blank;
        }
        foreach (['account' => $s->account, 'base_dn' => $s->baseDn] as $k => $v) {
            if (mb_strlen((string) $v) > 255) {
                $errors[] = $f($k).' '.$this->l('activerecord.errors.messages.too_long', ['count' => 255]);
            }
        }
        foreach (['attr_login' => $s->attrLogin, 'attr_firstname' => $s->attrFirstname, 'attr_lastname' => $s->attrLastname, 'attr_mail' => $s->attrMail] as $k => $v) {
            if (mb_strlen((string) $v) > 30) {
                $errors[] = $f($k).' '.$this->l('activerecord.errors.messages.too_long', ['count' => 30]);
            }
        }
        if (null !== $s->timeout && $s->timeout < 0) {
            $errors[] = $f('timeout').' '.$this->l('activerecord.errors.messages.not_a_number');
        }
        if (!LdapService::isValidFilter($s->filter)) {
            $errors[] = $this->l('field_auth_source_ldap_filter').' '.$this->l('activerecord.errors.messages.invalid');
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private function renderForm(AuthSource $source, array $errors = []): Response
    {
        $new = null === $source->id;
        $response = $this->render('admin/auth_sources/'.($new ? 'new' : 'edit').'.html.twig', [
            'auth_source' => $source,
            'ldap_mode' => LdapService::ldapMode($source),
            'ldap_modes' => LdapService::LDAP_MODES,
            'errors' => $errors,
            'admin_menu_selected' => 'ldap_authentication',
            'page_title' => $new ? $this->l('label_auth_source_new') : $source->name,
            'body_css' => 'controller-auth_sources action-'.($new ? 'new' : 'edit'),
        ], new Response(null, [] === $errors ? 200 : 422));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
