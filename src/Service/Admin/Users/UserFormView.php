<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Entity\AuthSource;
use App\Entity\User;
use App\I18n\Localizer;
use App\I18n\RailsTimeZones;
use App\Service\Admin\Memberships\PrincipalMemberships;
use App\Service\Admin\UserManager;
use App\Service\CustomFieldService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the "new user" and "edit user" pages of the administration show (users/new, users/edit with its
 * tabs, users/_form): the account as it is, or as it was submitted when it could not be saved.
 */
final class UserFormView
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserManager $users,
        private readonly CustomFieldService $customFields,
        private readonly PrincipalMemberships $memberships,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * The empty form: user[generate_password] of the URL keeps the choice made for the previous user.
     *
     * @return array<string, mixed>
     */
    public function forNew(Request $request): array
    {
        $attrs = (array) $request->query->all('user');

        return $this->form($this->users->newUser(), [
            'generate_password' => UserManager::bool($attrs['generate_password'] ?? false),
        ]);
    }

    /**
     * The form of a user that could not be created.
     *
     * @return array<string, mixed>
     */
    public function forCreate(UserSubmission $submission): array
    {
        return $this->form($submission->user, [
            'errors' => $submission->errors,
            'generate_password' => UserManager::bool($submission->attrs['generate_password'] ?? false),
            'send_information' => $submission->sendInformation,
            'posted_cf' => $this->users->customFieldParams($submission->attrs),
        ]);
    }

    /**
     * The edit page of a user, with what was submitted when it could not be saved.
     *
     * @return array<string, mixed>
     */
    public function forEdit(User $user, ?UserSubmission $submission = null): array
    {
        $extra = null === $submission ? [] : ['errors' => $submission->errors, 'posted_cf' => $this->users->customFieldParams($submission->attrs)];
        $tabs = [['name' => 'general', 'label' => 'label_general', 'template' => 'admin/users/_general.html.twig']];
        $groups = $this->users->givableGroups();
        if ([] !== $groups) {
            $tabs[] = ['name' => 'groups', 'label' => 'label_group_plural', 'template' => 'admin/users/_groups.html.twig'];
        }
        $tabs[] = ['name' => 'memberships', 'label' => 'label_project_plural', 'template' => 'admin/principal_memberships/_index.html.twig'];

        return $this->form($user, $extra + [
            'tabs' => $tabs,
            'groups' => $groups,
            'principal' => $user,
            'principal_memberships' => $this->memberships->of($user),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function form(User $user, array $extra): array
    {
        $isNew = null === $user->id;
        $projects = [];
        if (!$isNew) {
            foreach ($this->memberships->of($user) as $m) {
                $projects[] = $m->project;
            }
        }
        $fields = $this->users->userCustomFields();
        $values = $isNew ? $this->customFields->defaultValues($fields) : $this->customFields->values('Principal', $user->id);
        foreach ($extra['posted_cf'] ?? [] as $k => $v) {
            $values[$k] = $v;
        }
        $highPriority = null;
        $default = $this->em->createQuery('SELECT p FROM App\Entity\IssuePriority p WHERE p.isDefault = true AND p.project IS NULL')->setMaxResults(1)->getOneOrNullResult();
        $all = $this->em->createQuery('SELECT p FROM App\Entity\IssuePriority p WHERE p.project IS NULL ORDER BY p.position')->getResult();
        if (null === $default && [] !== $all) {
            $default = $all[intdiv(\count($all) - 1, 2)];
        }
        if (null !== $default) {
            foreach ($all as $p) {
                if ($p->position > $default->position) {
                    $highPriority = $p;
                    break;
                }
            }
        }
        $issueQueries = $this->em->createQuery("SELECT q FROM App\\Entity\\IssueQuery q WHERE q.project IS NULL ORDER BY q.name")->getResult();
        $projectQueries = $this->em->createQuery('SELECT q FROM App\Entity\ProjectQuery q ORDER BY q.name')->getResult();

        $tzOptions = [];
        $now = new \DateTimeImmutable();
        foreach (RailsTimeZones::MAP as $name => $iana) {
            $offset = (new \DateTimeZone($iana))->getOffset($now);
            $tzOptions[] = ['offset' => $offset, 'value' => $name, 'label' => sprintf('(GMT%s%02d:%02d) %s', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60), $name)];
        }
        usort($tzOptions, static fn ($a, $b) => [$a['offset'], $a['value']] <=> [$b['offset'], $b['value']]);

        return $extra + [
            'edited_user' => $user,
            'pref' => $user->getPref(),
            'auth_sources' => $this->em->getRepository(AuthSource::class)->findAll(),
            'languages' => $this->i18n->languageOptions(),
            'custom_fields' => $fields,
            'custom_values' => $values,
            'cf_options' => array_combine(array_map(static fn ($f) => $f->id, $fields), array_map(fn ($f) => $this->customFields->possibleValuesOptions($f), $fields)) ?: [],
            'mail_notification_options' => $this->users->mailNotificationOptions($user),
            'notified_project_ids' => $isNew ? [] : $this->users->notifiedProjectIds($user),
            'user_projects' => $projects,
            'high_priority' => $highPriority,
            'auto_watch_on_options' => UserManager::AUTO_WATCH_ON_OPTIONS,
            'toolbar_language_options' => $user->getPref()->get('toolbar_language_options') ?: UserManager::DEFAULT_TOOLBAR_LANGUAGE_OPTIONS,
            'time_zones' => $tzOptions,
            'issue_queries' => $issueQueries,
            'project_queries' => $projectQueries,
            'admin_menu_selected' => 'users',
            'page_title' => $isNew ? $this->i18n->l('label_user_new') : $user->login,
            'body_css' => 'controller-users action-'.($isNew ? 'new' : 'edit'),
            'errors' => $extra['errors'] ?? [],
            'generate_password' => $extra['generate_password'] ?? false,
            'send_information' => $extra['send_information'] ?? false,
            'is_new' => $isNew,
            'group_ids' => $user->getGroupIds(),
        ];
    }
}
