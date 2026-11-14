<?php

declare(strict_types=1);

namespace App\Service\Admin\AuthSources;

use App\Entity\AuthSource;
use App\I18n\Localizer;
use App\Service\LdapService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The form of an LDAP authentication mode (auth_sources#new, #create, #edit, #update): the safe attributes
 * of auth_source[...], the validations of AuthSource / AuthSourceLdap, and what the form shows.
 */
final class AuthSourceForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
    ) {
    }

    /** The authentication mode of the empty form: the default LDAP port, and what the URL already gives (type, auth_source[...]) */
    public function blank(Request $request): AuthSource
    {
        $source = $this->build($request->query->get('type'));
        $source->port = 389;
        $this->assign($source, $request->query->all('auth_source'));

        return $source;
    }

    public function create(Request $request): AuthSourceSubmission
    {
        $source = $this->build($request->request->get('type'));
        $this->assign($source, $request->request->all('auth_source'));
        $errors = $this->validate($source);
        if ([] === $errors) {
            $this->em->persist($source);
            $this->em->flush();
        }

        return new AuthSourceSubmission($source, $errors);
    }

    /** When the submitted values are refused the mode keeps them, to be shown again: discard() it once that is done */
    public function update(AuthSource $source, Request $request): AuthSourceSubmission
    {
        $this->assign($source, $request->request->all('auth_source'));
        $errors = $this->validate($source);
        if ([] === $errors) {
            $this->em->flush();
        }

        return new AuthSourceSubmission($source, $errors);
    }

    /** Forgets the refused values of an existing authentication mode, so that nothing saves them later */
    public function discard(AuthSource $source): void
    {
        $this->em->detach($source);
    }

    /**
     * Variables of the form page.
     *
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(AuthSource $source, array $errors = []): array
    {
        $new = null === $source->id;

        return [
            'auth_source' => $source,
            'ldap_mode' => LdapService::ldapMode($source),
            'ldap_modes' => LdapService::LDAP_MODES,
            'errors' => $errors,
            'admin_menu_selected' => 'ldap_authentication',
            'page_title' => $new ? $this->i18n->l('label_auth_source_new') : $source->name,
            'body_css' => 'controller-auth_sources action-'.($new ? 'new' : 'edit'),
        ];
    }

    private function build(mixed $type): AuthSource
    {
        if (null !== $type && '' !== $type && 'AuthSourceLdap' !== $type) {
            throw new NotFoundHttpException();
        }
        $source = new AuthSource();
        $source->type = 'AuthSourceLdap';

        return $source;
    }

    /**
     * safe_attributes of AuthSource / AuthSourceLdap
     *
     * @param array<string, mixed> $a
     */
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
        $blank = $this->i18n->l('activerecord.errors.messages.blank');
        $f = fn (string $k) => $this->i18n->l('field_'.$k);
        if ('' === $s->name) {
            $errors[] = $f('name').' '.$blank;
        } elseif (mb_strlen($s->name) > 60) {
            $errors[] = $f('name').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 60]);
        } else {
            $dup = $this->em->createQuery('SELECT COUNT(a.id) FROM App\Entity\AuthSource a WHERE a.name = :n AND a.id <> :id')
                ->setParameters(['n' => $s->name, 'id' => $s->id ?? 0])->getSingleScalarResult();
            if ($dup > 0) {
                $errors[] = $f('name').' '.$this->i18n->l('activerecord.errors.messages.taken');
            }
        }
        if ('' === (string) $s->host) {
            $errors[] = $f('host').' '.$blank;
        } elseif (mb_strlen((string) $s->host) > 60) {
            $errors[] = $f('host').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 60]);
        }
        if (null === $s->port) {
            $errors[] = $f('port').' '.$blank;
        } elseif ($s->port < 0) {
            $errors[] = $f('port').' '.$this->i18n->l('activerecord.errors.messages.not_a_number');
        }
        if ('' === (string) $s->attrLogin) {
            $errors[] = $f('attr_login').' '.$blank;
        }
        foreach (['account' => $s->account, 'base_dn' => $s->baseDn] as $k => $v) {
            if (mb_strlen((string) $v) > 255) {
                $errors[] = $f($k).' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
            }
        }
        foreach (['attr_login' => $s->attrLogin, 'attr_firstname' => $s->attrFirstname, 'attr_lastname' => $s->attrLastname, 'attr_mail' => $s->attrMail] as $k => $v) {
            if (mb_strlen((string) $v) > 30) {
                $errors[] = $f($k).' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 30]);
            }
        }
        if (null !== $s->timeout && $s->timeout < 0) {
            $errors[] = $f('timeout').' '.$this->i18n->l('activerecord.errors.messages.not_a_number');
        }
        if (!LdapService::isValidFilter($s->filter)) {
            $errors[] = $this->i18n->l('field_auth_source_ldap_filter').' '.$this->i18n->l('activerecord.errors.messages.invalid');
        }

        return $errors;
    }
}
