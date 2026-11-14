<?php

declare(strict_types=1);

namespace App\Service\Scm\Form;

use App\Entity\Project;
use App\Entity\Repository;
use App\I18n\Localizer;
use App\Import\AbstractImporter;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The form of a repository in the settings of a project (repositories/new, repositories/edit).
 *
 * Fields: repository_scm (the SCM of a new repository), repository[identifier|url|root_url|login|password|
 * path_encoding|log_encoding|is_default|report_last_commit].
 */
final class RepositoryForm
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly Localizer $i18n,
    ) {
    }

    /** Where the form leads back to: the Repositories tab of the project settings */
    public static function settingsUrl(Project $project): string
    {
        return '/projects/'.$project->identifier.'/settings/repositories';
    }

    /** new.js.erb: the SCM select reloads the form */
    public static function reloadScript(string $html): string
    {
        // ActionView::Helpers::JavaScriptHelper#escape_javascript
        $escaped = strtr($html, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\\"', "'" => "\\'", "\u{2028}" => '&#x2028;', "\u{2029}" => '&#x2029;']);

        return "$('#content').html('".$escaped."');";
    }

    /** The repository of the "new" form: the main repository of the project when it has none yet */
    public function blank(Request $request, Project $project): Repository
    {
        $repository = $this->build($request, $project);
        $repository->isDefault = null === $this->repos->defaultRepository($project);

        return $repository;
    }

    /**
     * build_new_repository_from_params: a repository of the SCM asked (the first enabled one by default), with
     * the attributes given in the URL
     */
    public function build(Request $request, Project $project): Repository
    {
        $param = (string) ($request->request->get('repository_scm') ?? $request->query->get('repository_scm', ''));
        $enabled = $this->repos->enabledScms();
        $scm = '' !== $param ? (\in_array($param, $enabled, true) ? $param : null) : ($enabled[0] ?? null);
        $scm = ScmConfiguration::normalizeName($scm);
        if (null === $scm) {
            throw new NotFoundHttpException();
        }
        $repository = $this->repos->build($scm);
        $repository->project = $project;
        $this->assign($repository, (array) $request->query->all('repository'), true);

        return $repository;
    }

    /**
     * Applies the submitted attributes and saves the repository when it is valid.
     *
     * @return list<string> the errors (empty when saved)
     */
    public function submit(Repository $repository, Request $request): array
    {
        $isNew = null === $repository->id;
        $this->assign($repository, (array) $request->request->all('repository'), $isNew);
        $errors = $this->repos->validate($repository, $isNew);
        if ([] === $errors) {
            $this->repos->save($repository);
        }

        return $errors;
    }

    /**
     * What the form shows.
     *
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(Repository $repository, array $errors = []): array
    {
        $config = $this->repos->configuration();
        $scm = $this->repos->scm($repository) ?? 'Git';
        $enabled = $this->repos->enabledScms();
        $options = [];
        foreach ($config->all() as $name) {
            if (\in_array($name, $enabled, true) || $name === $scm) {
                $options[$name] = $config->scmName($name);
            }
        }

        return [
            'project' => $repository->project, 'repository' => $repository, 'errors' => $errors,
            'scm' => $scm, 'scm_options' => $options,
            'scm_available' => $this->repos->scmAvailable($scm),
            'scm_path_info' => $config->pathInfo($scm) ?? $this->scmNote($scm),
            'identifier_frozen' => null !== $repository->id && null !== $repository->identifier && '' !== $repository->identifier,
            'report_last_commit' => $this->repos->reportLastCommit($repository),
            'encodings' => AbstractImporter::ENCODINGS,
            'main_menu_selected' => 'settings', 'body_css' => 'controller-repositories action-'.(null === $repository->id ? 'new' : 'edit'),
            'page_title' => $this->i18n->l('label_repository'),
        ];
    }

    /**
     * safe_attributes: identifier (unless frozen), login, password, path_encoding, log_encoding, is_default,
     * url (new only), root_url (CVS, new only), report_last_commit (Git).
     *
     * @param array<string, mixed> $attrs
     */
    private function assign(Repository $repository, array $attrs, bool $isNew): void
    {
        if (\array_key_exists('identifier', $attrs) && ($isNew || null === $repository->identifier || '' === $repository->identifier)) {
            $repository->identifier = trim((string) $attrs['identifier']);
        }
        if ($isNew && \array_key_exists('url', $attrs)) {
            $repository->url = trim((string) $attrs['url']);
        }
        if ($isNew && \array_key_exists('root_url', $attrs) && 'Repository::Cvs' === $repository->type) {
            $repository->rootUrl = trim((string) $attrs['root_url']);
        }
        if (\array_key_exists('login', $attrs)) {
            $repository->login = (string) $attrs['login'];
        }
        if (\array_key_exists('password', $attrs)) {
            $repository->password = (string) $attrs['password'];
        }
        if (\array_key_exists('is_default', $attrs)) {
            $repository->isDefault = \in_array((string) $attrs['is_default'], ['1', 'true'], true);
        }
        if (\array_key_exists('path_encoding', $attrs)) {
            $repository->pathEncoding = '' === (string) $attrs['path_encoding'] ? null : (string) $attrs['path_encoding'];
        }
        if (\array_key_exists('log_encoding', $attrs)) {
            $repository->logEncoding = '' === (string) $attrs['log_encoding'] ? null : (string) $attrs['log_encoding'];
        }
        if (\array_key_exists('report_last_commit', $attrs) && $this->repos->isGit($repository)) {
            $this->repos->mergeExtraInfo($repository, ['extra_report_last_commit' => \in_array((string) $attrs['report_last_commit'], ['1', 'true'], true) ? '1' : '0']);
        }
    }

    /** l("text_#{scm_name}_repository_note", :default => '') */
    private function scmNote(string $scm): string
    {
        $key = 'text_'.strtolower($this->repos->configuration()->scmName($scm)).'_repository_note';
        $text = $this->i18n->l($key);

        return $text === $key ? '' : $text;
    }
}
