<?php

declare(strict_types=1);

namespace App\Service\Account\My;

use App\Api\ApiRenderer;
use App\Entity\IssuePriority;
use App\Entity\User;
use App\Event\ContentEvent;
use App\Security\ApiRequestMatcher;
use App\Service\Account\MySidebar;
use App\Service\AccountService;
use App\Service\Admin\SecurityNotifier;
use App\Service\CustomFieldService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form of My account (MyController#account): a user changes their own name, e-mail address, language,
 * notifications, custom fields and preferences, from the page or through the REST API.
 *
 * Fields: user[firstname|lastname|mail|language|mail_notification|notified_project_ids|custom_field_values],
 * user[custom_fields] (API list) and pref[...] (MyPreferences).
 */
final class MyAccountForm
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly CustomFieldService $customFields,
        private readonly MyPreferences $preferences,
        private readonly MySidebar $sidebar,
        private readonly SecurityNotifier $securityNotifier,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Assigns what was submitted and saves the user when it is valid.
     *
     * @return list<string> the errors; empty when the account was saved
     */
    public function submit(User $user, Request $request): array
    {
        [$params, $prefParams] = self::params($request);
        $fields = $this->accounts->editableUserCustomFields($user);
        $storedCfValues = $this->customFields->values('Principal', $user->id);

        $oldMail = (string) $user->getMail();
        foreach (['firstname', 'lastname'] as $attr) {
            if (\array_key_exists($attr, $params)) {
                $user->{$attr} = trim((string) $params[$attr]);
            }
        }
        if (\array_key_exists('mail', $params)) {
            $user->setMail(trim((string) $params['mail']));
        }
        if (\array_key_exists('language', $params)) {
            $user->language = (string) $params['language'];
        }
        if (\array_key_exists('mail_notification', $params)) {
            $user->mailNotification = (string) $params['mail_notification'];
        }
        $submitted = self::submittedCustomValues($params);
        $cfValues = array_replace($storedCfValues, $submitted);
        $mailChanged = $oldMail !== (string) $user->getMail();

        $errors = $this->accounts->userErrors($user, false, $mailChanged);
        $errors = array_merge($errors, $this->accounts->customFieldErrors(array_values(array_filter($fields, static fn ($f) => \array_key_exists($f->id, $submitted) || $f->isRequired)), $cfValues, $storedCfValues));
        if ([] !== $errors) {
            return $errors;
        }

        $this->preferences->assign($user, $prefParams);
        $user->updatedOn = new \DateTimeImmutable();
        $pref = $user->getPref();
        if (null === $pref->id) {
            $this->em->persist($pref);
        }
        $pref->others = \is_array($pref->others) ? [...$pref->others] : $pref->others;
        $this->em->flush();
        if ([] !== $submitted) {
            $this->customFields->saveValues('Principal', (int) $user->id, $fields, $submitted);
        }
        if (\array_key_exists('notified_project_ids', $params) || \array_key_exists('mail_notification', $params)) {
            $ids = \array_key_exists('notified_project_ids', $params) ? (array) $params['notified_project_ids'] : $this->accounts->notifiedProjectIds($user);
            $this->accounts->updateNotifiedProjectIds($user, $ids);
        }
        $this->em->flush();
        if ($mailChanged) {
            // EmailAddress#destroy_tokens
            $this->accounts->deleteLoginTokens($user);
            // EmailAddress#deliver_security_notification_update: the previous address is notified too
            if ('' !== $oldMail && '' !== (string) $user->getMail()) {
                $this->securityNotifier->emailAddressChanged($user, $oldMail, (string) $user->getMail());
            }
        }
        $this->dispatcher->dispatch(new ContentEvent('user', 'updated', $user, $user));

        return [];
    }

    /**
     * @param list<string> $errors the errors of submit(): the custom field values are then shown as submitted
     *
     * @return array<string, mixed> what the page shows
     */
    public function view(User $user, Request $request, array $errors = []): array
    {
        $cfValues = $this->customFields->values('Principal', $user->id);
        if (!$request->isMethod('GET')) {
            $cfValues = array_replace($cfValues, self::submittedCustomValues(self::params($request)[0]));
        }
        $pref = $user->getPref();

        return [
            'user' => $user,
            'pref' => $pref,
            'errors' => $errors,
            'custom_fields' => $this->accounts->editableUserCustomFields($user),
            'custom_values' => $cfValues,
            'languages' => $this->accounts->languageOptions(),
            'mail_notification_options' => $this->accounts->mailNotificationOptions($user),
            'notified_project_ids' => $this->accounts->notifiedProjectIds($user),
            'member_projects' => array_map(static fn ($m) => $m->project, $this->accounts->memberships($user)),
            'high_priority' => $this->highPriority(),
            'issue_queries' => $this->defaultQueryChoices($user, "SELECT id, name, visibility, user_id FROM queries WHERE type = 'IssueQuery' AND project_id IS NULL ORDER BY name, id"),
            'project_queries' => $this->defaultQueryChoices($user, "SELECT id, name, visibility, user_id FROM queries WHERE type = 'ProjectQuery' ORDER BY name, id"),
            'auto_watch_on' => (array) $pref->get('auto_watch_on', []),
            'auto_watch_on_options' => AccountService::AUTO_WATCH_ON_OPTIONS,
            'textarea_font_options' => AccountService::TEXTAREA_FONT_OPTIONS,
            'toolbar_language_options' => (string) ($pref->get('toolbar_language_options') ?: implode(',', AccountService::DEFAULT_TOOLBAR_LANGUAGE_OPTIONS)),
            'change_password_allowed' => $this->accounts->changePasswordAllowed($user),
        ] + $this->sidebar->vars($user);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the user and pref parameters, from the form
     *                                                                 or from the API payload
     */
    private static function params(Request $request): array
    {
        if (ApiRequestMatcher::isApiRequest($request)) {
            $payload = ApiRenderer::payload($request);

            return [(array) ($payload['user'] ?? []), (array) ($payload['pref'] ?? [])];
        }

        return [$request->request->all('user'), $request->request->all('pref')];
    }

    /**
     * custom fields: hash {id => value} or API list [{id:, value:}]
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    private static function submittedCustomValues(array $params): array
    {
        $submitted = (array) ($params['custom_field_values'] ?? []);
        foreach ((array) ($params['custom_fields'] ?? []) as $cf) {
            if (\is_array($cf) && isset($cf['id'])) {
                $submitted[(int) $cf['id']] = $cf['value'] ?? null;
            }
        }

        return $submitted;
    }

    /**
     * The queries a user may choose as default query: the public ones and their own.
     *
     * @return array{public: list<array<string, mixed>>, mine: list<array<string, mixed>>}
     */
    private function defaultQueryChoices(User $user, string $sql): array
    {
        $queries = ['public' => [], 'mine' => []];
        foreach ($this->em->getConnection()->fetchAllAssociative($sql) as $q) {
            if (2 === (int) $q['visibility']) {
                $queries['public'][] = $q;
            } elseif ((int) $q['user_id'] === $user->id) {
                $queries['mine'][] = $q;
            }
        }

        return $queries;
    }

    /** IssuePriority.default_or_middle and the next higher priority */
    private function highPriority(): ?IssuePriority
    {
        $priorities = $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'ASC']);
        if ([] === $priorities) {
            return null;
        }
        $default = null;
        foreach ($priorities as $p) {
            if ($p->isDefault) {
                $default = $p;
            }
        }
        $default ??= $priorities[intdiv(\count($priorities) - 1, 2)];
        foreach ($priorities as $p) {
            if ($p->position > $default->position) {
                return $p;
            }
        }

        return null;
    }
}
