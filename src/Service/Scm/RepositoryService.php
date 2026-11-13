<?php

declare(strict_types=1);

namespace App\Service\Scm;

use App\Entity\Change;
use App\Entity\Changeset;
use App\Entity\Issue;
use App\Entity\IssueStatus;
use App\Entity\Project;
use App\Entity\Repository;
use App\Entity\TimeEntry;
use App\Entity\TimeEntryActivity;
use App\Entity\User;
use App\I18n\Localizer;
use App\Migration\RedmineMigrator;
use App\Security\CurrentUser;
use App\Service\IssueService;
use App\Service\Scm\Model\AbstractModel;
use App\Service\Scm\Model\BazaarModel;
use App\Service\Scm\Model\CvsModel;
use App\Service\Scm\Model\FilesystemModel;
use App\Service\Scm\Model\GitModel;
use App\Service\Scm\Model\MercurialModel;
use App\Service\Scm\Model\SubversionModel;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Port of app/models/repository.rb and changeset.rb: adapter access, repository validation,
 * changesets fetching (delegated to the type specific models in Scm\Model), issue references
 * scanning, committers mapping and statistics.
 */
class RepositoryService
{
    public const int IDENTIFIER_MAX_LENGTH = 255;
    public const array RESERVED_IDENTIFIERS = ['browse', 'show', 'entry', 'raw', 'changes', 'annotate', 'diff', 'statistics', 'graph', 'revisions', 'revision'];
    public const string TIMELOG_RE = '(?:(?:\d+)(?:h|hours?)(?:(?:\d+)(?:m|min)?)?|(?:\d+)(?:h|hours?|m|min)|\d+:\d+|\d+(?:[.,]\d+)?h?)';

    /** @var array<int, AbstractAdapter> */
    private array $adapters = [];
    /** @var array<string, AbstractModel> */
    private array $models = [];
    /** @var array<string, User|null> */
    private array $committerUsers = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly CurrentUser $currentUser,
        private readonly IssueService $issues,
        private readonly ScmConfiguration $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function configuration(): ScmConfiguration
    {
        return $this->config;
    }

    /** SCM name of the repository ("Git", "Subversion", ...; null when the type is unknown) */
    public function scm(Repository $repository): ?string
    {
        return ScmConfiguration::nameOfType($repository->type);
    }

    /** Repository#scm: the adapter (root_url is saved when retrieved from the SCM, like Redmine) */
    public function adapter(Repository $repository): AbstractAdapter
    {
        $key = $repository->id ?? spl_object_id($repository);
        if (isset($this->adapters[$key])) {
            return $this->adapters[$key];
        }
        $scm = $this->scm($repository) ?? throw new ScmCommandFailed('Unknown repository type '.$repository->type);
        $class = $this->config->adapterClass($scm);
        $bin = $this->config->command($scm);
        $adapter = GitAdapter::class === $class
            ? new GitAdapter($repository->url, $bin, $repository->pathEncoding, $repository->rootUrl ?: null)
            : new $class($repository->url, $repository->rootUrl, $repository->login, $this->password($repository), $repository->pathEncoding, $bin);
        $this->adapters[$key] = $adapter;
        if ((null === $repository->rootUrl || '' === $repository->rootUrl) && \in_array($scm, ['Subversion', 'Git', 'Mercurial', 'Bazaar'], true)) {
            $root = $adapter->rootUrl();
            if (null !== $root && '' !== $root && null !== $repository->id) {
                $repository->rootUrl = $root;
                $this->em->getConnection()->executeStatement('UPDATE repositories SET root_url = ? WHERE id = ?', [$root, (int) $repository->id]);
            }
        }

        return $adapter;
    }

    /** Repository#password (values ciphered by Redmine are decrypted with REDMINE_CIPHER_KEY) */
    public function password(Repository $repository): ?string
    {
        $pwd = $repository->password;
        if (null !== $pwd && str_starts_with($pwd, 'aes-256-cbc:')) {
            $key = (string) ($_SERVER['REDMINE_CIPHER_KEY'] ?? $_ENV['REDMINE_CIPHER_KEY'] ?? getenv('REDMINE_CIPHER_KEY') ?: '');
            $pwd = '' === $key ? null : RedmineMigrator::decryptRedmineText($pwd, $key);
        }

        return $pwd;
    }

    /** Type specific behaviour (app/models/repository/*.rb) */
    public function model(Repository $repository): AbstractModel
    {
        return $this->modelFor($this->scm($repository) ?? 'Git');
    }

    public function modelFor(string $scm): AbstractModel
    {
        return $this->models[$scm] ??= match ($scm) {
            'Subversion' => new SubversionModel($this, $this->em),
            'Mercurial' => new MercurialModel($this, $this->em),
            'Cvs' => new CvsModel($this, $this->em),
            'Bazaar' => new BazaarModel($this, $this->em),
            'Filesystem' => new FilesystemModel($this, $this->em),
            default => new GitModel($this, $this->em),
        };
    }

    public function isGit(Repository $repository): bool
    {
        return 'Repository::Git' === $repository->type;
    }

    /** Setting.enabled_scm: enabled and available SCMs (Redmine::Scm::Base order) */
    public function enabledScms(): array
    {
        $enabled = array_map('strval', $this->settings->array('enabled_scm'));

        return array_values(array_filter($this->config->all(), fn (string $scm) => \in_array($scm, $enabled, true) && $this->config->available($scm)));
    }

    /** Repository.scm_available (client available and path regexp configured) */
    public function scmAvailable(string $scm = 'Git'): bool
    {
        return $this->config->available($scm);
    }

    /** Repository#adapter_enabled? */
    public function adapterEnabled(Repository $repository): bool
    {
        $scm = $this->scm($repository);

        return null !== $scm && \in_array($scm, $this->enabledScms(), true);
    }

    /** Repository.repository_class + factory */
    public function build(string $scm): Repository
    {
        $repository = new Repository();
        $repository->type = 'Repository::'.$scm;

        return $repository;
    }

    /** Repository#name */
    public function name(Repository $repository): string
    {
        if (null !== $repository->identifier && '' !== $repository->identifier) {
            return $repository->identifier;
        }
        if ($repository->isDefault) {
            return $this->i18n->l('field_repository_is_default');
        }

        return $this->scmName($repository);
    }

    /** Repository#scm_name ("CVS" for Repository::Cvs) */
    public function scmName(Repository $repository): string
    {
        $scm = $this->scm($repository);

        return null !== $scm ? $this->config->scmName($scm) : $repository->scmName();
    }

    public static function identifierParam(Repository $repository): string
    {
        return null !== $repository->identifier && '' !== $repository->identifier ? $repository->identifier : (string) $repository->id;
    }

    /** @return list<Repository> project repositories sorted like Repository#<=> */
    public function projectRepositories(Project $project): array
    {
        $repos = $this->em->getRepository(Repository::class)->findBy(['project' => $project]);
        usort($repos, static function (Repository $a, Repository $b): int {
            if ($a->isDefault) {
                return -1;
            }
            if ($b->isDefault) {
                return 1;
            }

            return strcmp((string) $a->identifier, (string) $b->identifier);
        });

        return $repos;
    }

    /** Project#repository (default one) */
    public function defaultRepository(Project $project): ?Repository
    {
        return $this->em->getRepository(Repository::class)->findOneBy(['project' => $project, 'isDefault' => true]);
    }

    public function findByIdentifierParam(Project $project, string $param): ?Repository
    {
        if (preg_match('/^\d+$/', $param)) {
            $r = $this->em->find(Repository::class, (int) $param);

            return $r && $r->project->id === $project->id ? $r : null;
        }

        return $this->em->getRepository(Repository::class)->findOneBy(['project' => $project, 'identifier' => $param]);
    }

    public function reportLastCommit(Repository $repository): bool
    {
        return $this->model($repository)->reportLastCommit($repository);
    }

    public function mergeExtraInfo(Repository $repository, array $values): void
    {
        $info = \is_array($repository->extraInfo) ? $repository->extraInfo : [];
        $repository->extraInfo = array_merge($info, $values);
    }

    /**
     * Repository validation (identifier format/uniqueness, url presence/format, SCM enabled, path regexp).
     *
     * @return list<string>
     */
    public function validate(Repository $repository, bool $isNew): array
    {
        $errors = [];
        $f = fn (string $field, string $msg, array $p = []) => $this->i18n->l($field).' '.$this->i18n->l('activerecord.errors.messages.'.$msg, $p);
        $model = $this->model($repository);
        foreach ($model->validate($repository) as [$field, $msg]) {
            $errors[] = $f($field, $msg);
        }
        foreach (['url' => $model->urlLabel(), 'root_url' => 'field_cvsroot'] as $attr => $label) {
            $v = 'url' === $attr ? $repository->url : (string) $repository->rootUrl;
            if (mb_strlen($v) > 255) {
                $errors[] = $f($label, 'too_long', ['count' => 255]);
            }
        }
        if (mb_strlen((string) $repository->login) > 60) {
            $errors[] = $f('field_login', 'too_long', ['count' => 60]);
        }
        if (mb_strlen((string) $repository->password) > 255) {
            $errors[] = $f('field_password', 'too_long', ['count' => 255]);
        }
        $id = (string) $repository->identifier;
        if ('' !== $id) {
            if (mb_strlen($id) > self::IDENTIFIER_MAX_LENGTH) {
                $errors[] = $f('field_identifier', 'too_long', ['count' => self::IDENTIFIER_MAX_LENGTH]);
            }
            if (\in_array($id, self::RESERVED_IDENTIFIERS, true)) {
                $errors[] = $f('field_identifier', 'exclusion');
            }
            if (!preg_match('/\A(?!\d+$)[a-z0-9\-_]*\z/', $id)) {
                $errors[] = $f('field_identifier', 'invalid');
            }
            $qb = $this->em->createQuery('SELECT COUNT(r.id) FROM App\Entity\Repository r WHERE r.project = :p AND r.identifier = :i'.($repository->id ? ' AND r.id <> :id' : ''))
                ->setParameter('p', $repository->project)->setParameter('i', $id);
            if ($repository->id) {
                $qb->setParameter('id', $repository->id);
            }
            if ((int) $qb->getSingleScalarResult() > 0) {
                $errors[] = $f('field_identifier', 'taken');
            }
        } elseif ($isNew && !$repository->isDefault) {
            // uniqueness of the blank identifier within the project
            $hasOther = (int) $this->em->createQuery('SELECT COUNT(r.id) FROM App\Entity\Repository r WHERE r.project = :p AND (r.identifier IS NULL OR r.identifier = \'\')')
                ->setParameter('p', $repository->project)->getSingleScalarResult();
            if ($hasOther > 0) {
                $errors[] = $f('field_identifier', 'blank');
            }
        }
        if ($isNew && !$this->adapterEnabled($repository)) {
            $errors[] = $f('label_scm', 'invalid');
        }
        // validate_repository_path (on create: the path attribute cannot change afterwards)
        $scm = $this->scm($repository);
        if ($isNew && null !== $scm) {
            $attr = $model->pathAttribute();
            $value = 'root_url' === $attr ? $repository->rootUrl : $repository->url;
            if ('' !== trim((string) $value) && !$this->config->pathAllowed($scm, $value, $repository->project->identifier)) {
                $errors[] = $f('root_url' === $attr ? 'field_cvsroot' : $model->urlLabel(), 'invalid');
            }
        }

        return array_values(array_unique($errors));
    }

    /** Saves the repository: Repository#check_default (only one default per project; first one is default) */
    public function save(Repository $repository): void
    {
        $repository->url = trim($repository->url);
        if (null !== $repository->rootUrl) {
            $repository->rootUrl = trim($repository->rootUrl);
        }
        $count = (int) $this->em->createQuery('SELECT COUNT(r.id) FROM App\Entity\Repository r WHERE r.project = :p'.($repository->id ? ' AND r.id <> :id' : ''))
            ->setParameters($repository->id ? ['p' => $repository->project, 'id' => $repository->id] : ['p' => $repository->project])->getSingleScalarResult();
        if (0 === $count) {
            $repository->isDefault = true;
        }
        if ($repository->isDefault) {
            $this->em->createQuery('UPDATE App\Entity\Repository r SET r.isDefault = false WHERE r.project = :p'.($repository->id ? ' AND r.id <> :id' : ''))
                ->setParameters($repository->id ? ['p' => $repository->project, 'id' => $repository->id] : ['p' => $repository->project])->execute();
        }
        $repository->createdOn ??= new \DateTimeImmutable();
        $this->em->persist($repository);
        $this->em->flush();
    }

    /** Destroys the repository and its changesets (Repository#clear_changesets) */
    public function destroy(Repository $repository): void
    {
        $this->clearChangesets($repository);
        $this->em->remove($repository);
        $this->em->flush();
    }

    public function clearChangesets(Repository $repository): void
    {
        $conn = $this->em->getConnection();
        $sub = 'SELECT id FROM changesets WHERE repository_id = ?';
        $id = [(int) $repository->id];
        $conn->executeStatement("DELETE FROM changes WHERE changeset_id IN ($sub)", $id);
        $conn->executeStatement("DELETE FROM changesets_issues WHERE changeset_id IN ($sub)", $id);
        $conn->executeStatement("DELETE FROM changeset_parents WHERE changeset_id IN ($sub) OR parent_id IN ($sub)", [...$id, ...$id]);
        $conn->executeStatement('DELETE FROM changesets WHERE repository_id = ?', $id);
        $this->model($repository)->clearChangesets($repository);
    }

    public function changesetCount(Repository $repository): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(c.id) FROM App\Entity\Changeset c WHERE c.repository = :r')->setParameter('r', $repository)->getSingleScalarResult();
    }

    /** @return list<Changeset> Repository#changesets (committed_on DESC, id DESC; id DESC for Mercurial) */
    public function changesets(Repository $repository, int $limit, int $offset = 0): array
    {
        return $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r ORDER BY '.$this->model($repository)->changesetsOrder())
            ->setParameter('r', $repository)->setMaxResults($limit)->setFirstResult($offset)->getResult();
    }

    public function latestChangeset(Repository $repository): ?Changeset
    {
        return $this->model($repository)->latestChangeset($repository);
    }

    /** Repository#find_changeset_by_name */
    public function findChangesetByName(Repository $repository, ?string $name): ?Changeset
    {
        return $this->model($repository)->findChangesetByName($repository, $name);
    }

    public function previousChangeset(Changeset $c): ?Changeset
    {
        return $this->model($c->repository)->previousChangeset($c);
    }

    public function nextChangeset(Changeset $c): ?Changeset
    {
        return $this->model($c->repository)->nextChangeset($c);
    }

    /** @return list<Changeset> */
    public function children(Changeset $c): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT changeset_id FROM changeset_parents WHERE parent_id = ?', [(int) $c->id]);

        return [] === $ids ? [] : $this->em->getRepository(Changeset::class)->findBy(['id' => array_map('intval', $ids)]);
    }

    /** @return list<Change> */
    public function changes(Changeset $c, int $limit = 1000): array
    {
        return $this->em->createQuery('SELECT ch FROM App\Entity\Change ch WHERE ch.changeset = :c ORDER BY ch.path ASC')
            ->setParameter('c', $c)->setMaxResults($limit)->getResult();
    }

    /** Repository#latest_changesets */
    public function latestChangesets(Repository $repository, ?string $path, ?string $rev, int $limit = 10): array
    {
        return $this->model($repository)->latestChangesets($repository, $path, $rev, $limit);
    }

    /** Repository#entries (with load_entries_changesets) */
    public function entries(Repository $repository, ?string $path, ?string $rev): ?array
    {
        return $this->model($repository)->entries($repository, $path, $rev);
    }

    public function entry(Repository $repository, ?string $path, ?string $rev): ?ScmEntry
    {
        return $this->model($repository)->entry($repository, $path, $rev);
    }

    public function cat(Repository $repository, string $path, ?string $rev): ?string
    {
        return $this->model($repository)->cat($repository, $path, $rev);
    }

    /** @return list<string>|null */
    public function diff(Repository $repository, ?string $path, ?string $rev, ?string $revTo): ?array
    {
        return $this->model($repository)->diff($repository, $path, $rev, $revTo);
    }

    public function annotate(Repository $repository, string $path, ?string $rev): ?ScmAnnotate
    {
        return $this->model($repository)->annotate($repository, $path, $rev);
    }

    /** @return array<string, string>|null */
    public function properties(Repository $repository, ?string $path, ?string $rev): ?array
    {
        return $this->model($repository)->properties($repository, $path, $rev);
    }

    public function defaultBranch(Repository $repository): ?string
    {
        try {
            return $this->model($repository)->defaultBranch($repository);
        } catch (ScmCommandFailed) {
            return null;
        }
    }

    /** Repository#fetch_changesets */
    public function fetchChangesets(Repository $repository): void
    {
        $this->model($repository)->fetchChangesets($repository);
    }

    /**
     * Repository.fetch_changesets: fetches the changesets of all the repositories of the active
     * projects with the repository module (or of the given projects).
     *
     * @param list<Project>|null $projects
     *
     * @return list<string> errors
     */
    public function fetchAllChangesets(?array $projects = null): array
    {
        $projects ??= $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE p.status = 1 AND EXISTS (SELECT em.id FROM App\Entity\EnabledModule em WHERE em.project = p AND em.name = 'repository') ORDER BY p.id")->getResult();
        $errors = [];
        foreach ($projects as $project) {
            foreach ($this->em->getRepository(Repository::class)->findBy(['project' => $project], ['id' => 'ASC']) as $repository) {
                if (!$this->adapterEnabled($repository)) {
                    continue;
                }
                try {
                    $this->fetchChangesets($repository);
                } catch (ScmCommandFailed $e) {
                    $errors[] = 'scm: error during fetching changesets: '.$e->getMessage();
                    $this->logger?->error('scm: error during fetching changesets: '.$e->getMessage());
                }
            }
        }

        return $errors;
    }

    /**
     * Changeset.create: before_create (committer/comments to UTF-8, user mapping), after_create
     * (issue references). Returns null when the revision already exists.
     *
     * @param array{revision: string, scmid?: ?string, committer?: ?string, committed_on?: ?\DateTimeImmutable, comments?: ?string} $attrs
     * @param list<Changeset>                                                                                                      $parents
     * @param list<array<string, mixed>>                                                                                           $paths   changes created with create_change
     */
    public function createChangeset(Repository $repository, array $attrs, array $parents = [], array $paths = []): ?Changeset
    {
        $revision = (string) $attrs['revision'];
        if ((int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM changesets WHERE repository_id = ? AND revision = ?', [(int) $repository->id, $revision]) > 0) {
            return null;
        }
        $encoding = $this->model($repository)->repoLogEncoding($repository);
        $cs = new Changeset();
        $cs->repository = $repository;
        $cs->revision = $revision;
        $cs->scmid = $attrs['scmid'] ?? null;
        $cs->committer = AbstractAdapter::toUtf8($attrs['committer'] ?? null, $encoding);
        $time = $attrs['committed_on'] ?? null;
        $cs->committedOn = ($time ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $cs->commitDate = new \DateTimeImmutable($cs->committedOn->format('Y-m-d'));
        $cs->comments = trim((string) AbstractAdapter::toUtf8((string) ($attrs['comments'] ?? ''), $encoding));
        $cs->user = $this->findCommitterUser($repository, $cs->committer);
        foreach ($parents as $p) {
            $cs->parents->add($p);
        }
        $this->em->persist($cs);
        foreach ($paths as $path) {
            $this->createChange($cs, $path);
        }
        $this->em->flush();
        $this->scanCommentForIssueIds($cs);

        return $cs;
    }

    /** Changeset#create_change / Change.create (paths with invalid UTF-8 are fixed) */
    public function createChange(Changeset $cs, array $change): Change
    {
        $ch = new Change();
        $ch->changeset = $cs;
        $ch->action = (string) ($change['action'] ?? '');
        $ch->path = (string) AbstractAdapter::replaceInvalidUtf8((string) ($change['path'] ?? ''));
        $ch->fromPath = AbstractAdapter::replaceInvalidUtf8($change['from_path'] ?? null);
        $ch->fromRevision = isset($change['from_revision']) ? (string) $change['from_revision'] : null;
        $ch->revision = isset($change['revision']) ? (string) $change['revision'] : null;
        $ch->branch = isset($change['branch']) ? (string) $change['branch'] : null;
        $this->em->persist($ch);

        return $ch;
    }

    /** Repository#find_committer_user */
    public function findCommitterUser(Repository $repository, ?string $committer): ?User
    {
        if (null === $committer || '' === trim($committer)) {
            return null;
        }
        $key = $repository->id.'|'.$committer;
        if (\array_key_exists($key, $this->committerUsers)) {
            $cached = $this->committerUsers[$key];
            if (null === $cached || $this->em->contains($cached)) {
                return $cached;
            }
            $found = $this->em->find(User::class, $cached->id);

            return $this->committerUsers[$key] = $found instanceof User ? $found : null;
        }
        $user = null;
        $c = $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND c.committer = :c AND c.user IS NOT NULL')
            ->setParameter('r', $repository)->setParameter('c', $committer)->setMaxResults(1)->getResult();
        if ([] !== $c) {
            $user = $c[0]->user;
        } elseif (preg_match('/^([^<]+)(<(.*)>)?$/', trim($committer), $m)) {
            $username = trim($m[1]);
            $email = $m[3] ?? '';
            $user = $this->em->getRepository(User::class)->findOneBy(['login' => $username]);
            if (null === $user && '' !== $email) {
                $uid = $this->em->getConnection()->fetchOne('SELECT user_id FROM email_addresses WHERE LOWER(address) = LOWER(?)', [$email]);
                $user = $uid ? $this->em->find(User::class, (int) $uid) : null;
            }
        }

        return $this->committerUsers[$key] = ($user instanceof User ? $user : null);
    }

    /** @return list<array{0: string, 1: int|null}> [committer, user_id] */
    public function committers(Repository $repository): array
    {
        return array_map(static fn ($r) => [(string) $r['committer'], null !== $r['user_id'] ? (int) $r['user_id'] : null],
            $this->em->getConnection()->fetchAllAssociative('SELECT DISTINCT committer, user_id FROM changesets WHERE repository_id = ? ORDER BY committer', [(int) $repository->id]));
    }

    /** Repository#committer_ids= */
    public function setCommitterIds(Repository $repository, array $map): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->committers($repository) as [$committer, $userId]) {
            if (!\array_key_exists($committer, $map)) {
                continue;
            }
            $new = (int) $map[$committer];
            if ($new !== (int) $userId) {
                $conn->executeStatement('UPDATE changesets SET user_id = ? WHERE repository_id = ? AND committer = ?', [$new > 0 ? $new : null, (int) $repository->id, $committer]);
            }
        }
        $this->committerUsers = [];
    }

    // ---- Changeset helpers ----

    /** Changeset#format_identifier (git: 8 chars, hg: "rev:scmid12", others: revision) */
    public static function formatIdentifier(Changeset $c): string
    {
        return match ($c->repository->type) {
            'Repository::Git' => substr($c->revision, 0, 8),
            'Repository::Mercurial' => $c->revision.':'.substr((string) $c->scmid, 0, 12),
            default => $c->revision,
        };
    }

    /** Changeset#identifier (git/hg: scmid, others: revision) */
    public static function identifier(Changeset $c): string
    {
        return match ($c->repository->type) {
            'Repository::Git', 'Repository::Mercurial' => (string) ($c->scmid ?: $c->revision),
            default => $c->revision,
        };
    }

    /** @return array{0: string, 1: string} [short_comments, long_comments] */
    public static function splitComments(?string $comments): array
    {
        $comments = (string) $comments;
        if (preg_match('/\A(.+?)\r?\n(.*)$/s', $comments, $m)) {
            return [$m[1], trim($m[2])];
        }

        return [$comments, ''];
    }

    public function title(Changeset $c): string
    {
        $repo = null !== $c->repository->identifier && '' !== $c->repository->identifier ? ' ('.$c->repository->identifier.')' : '';
        $short = self::splitComments($c->comments)[0];

        return $this->i18n->l('label_revision').' '.self::formatIdentifier($c).$repo.('' === $short ? '' : ': '.$short);
    }

    /** Changeset#text_tag */
    public static function textTag(Changeset $c, ?Project $refProject = null): string
    {
        $repo = null !== $c->repository->identifier && '' !== $c->repository->identifier ? $c->repository->identifier.'|' : '';
        $tag = $c->scmid ? 'commit:'.$repo.$c->scmid : $repo.'r'.$c->revision;
        if (null !== $refProject && $refProject->id !== $c->repository->project->id) {
            $tag = $c->repository->project->identifier.':'.$tag;
        }

        return $tag;
    }

    /** Changeset#find_referenced_issue_by_id */
    public function findReferencedIssue(Changeset $c, int|string|null $id): ?Issue
    {
        if (null === $id || '' === $id) {
            return null;
        }
        $issue = $this->em->find(Issue::class, (int) $id);
        if (null === $issue || $this->settings->bool('commit_cross_project_ref')) {
            return $issue;
        }
        $project = $c->repository->project;
        $ip = $issue->project;
        if ($project->id === $ip->id || $ip->isDescendantOf($project) || $project->isDescendantOf($ip)) {
            return $issue;
        }

        return null;
    }

    /** @return list<array{keywords: list<string>, status_id?: string, done_ratio?: string, if_tracker_id?: string}> */
    public function commitUpdateKeywords(): array
    {
        $out = [];
        foreach ((array) $this->settings->get('commit_update_keywords') as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $rule = array_filter($rule, static fn ($v) => null !== $v && '' !== $v);
            $kws = array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($rule['keywords'] ?? '')))), static fn ($k) => '' !== $k));
            if ([] === $kws) {
                continue;
            }
            $rule['keywords'] = $kws;
            $out[] = $rule;
        }

        return $out;
    }

    /** Changeset#scan_comment_for_issue_ids */
    public function scanCommentForIssueIds(Changeset $c): void
    {
        $comments = (string) $c->comments;
        if ('' === trim($comments)) {
            return;
        }
        $refKeywords = array_map('trim', explode(',', strtolower($this->settings->string('commit_ref_keywords'))));
        $refAny = \in_array('*', $refKeywords, true);
        $refKeywords = array_values(array_filter($refKeywords, static fn ($k) => '*' !== $k && '' !== $k));
        $rules = $this->commitUpdateKeywords();
        $fixKeywords = [];
        foreach ($rules as $r) {
            array_push($fixKeywords, ...$r['keywords']);
        }
        $kw = implode('|', array_map(static fn ($k) => preg_quote($k, '/'), array_merge($refKeywords, $fixKeywords)));
        $t = self::TIMELOG_RE;
        $regexp = '/([\s(\[,-]|^)(('.('' === $kw ? '(?!)' : $kw).')[\s:]+)?(#\d+(\s+@'.$t.')?([\s,;&]+#\d+(\s+@'.$t.')?)*)(?=[[:punct:]]|\s|<|$)/iu';
        $referenced = [];
        if (preg_match_all($regexp, $comments, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $action = strtolower($m[3] ?? '');
                $refs = $m[4];
                if ('' === $action && !$refAny) {
                    continue;
                }
                preg_match_all('/#(\d+)(\s+@('.$t.'))?/', $refs, $rm, \PREG_SET_ORDER);
                foreach ($rm as $r) {
                    $issue = $this->findReferencedIssue($c, $r[1]);
                    $hours = $r[3] ?? null;
                    if (null !== $issue && !$this->issueLinkedToSameCommit($issue, $c)) {
                        $referenced[$issue->id] = $issue;
                        if (!($c->repository->createdOn && $c->committedOn < $c->repository->createdOn)) {
                            if (\in_array($action, $fixKeywords, true)) {
                                $this->fixIssue($c, $issue, $action, $rules);
                            }
                            if (null !== $hours && '' !== $hours && $this->settings->bool('commit_logtime_enabled')) {
                                $this->logTime($c, $issue, $hours);
                            }
                        }
                    }
                }
            }
        }
        if ([] !== $referenced) {
            foreach ($referenced as $issue) {
                if (!$c->issues->contains($issue)) {
                    $c->issues->add($issue);
                }
            }
            $this->em->flush();
        }
    }

    /** Repository#same_commits_in_scope(issue.changesets, changeset).any? */
    private function issueLinkedToSameCommit(Issue $issue, Changeset $c): bool
    {
        $r = $c->repository;
        $sql = 'SELECT COUNT(*) FROM changesets cs JOIN changesets_issues ci ON ci.changeset_id = cs.id JOIN repositories r ON r.id = cs.repository_id
                WHERE ci.issue_id = ? AND r.url = ? AND COALESCE(r.root_url, \'\') = ? AND r.type = ? AND '.($c->scmid ? 'cs.scmid = ?' : 'cs.revision = ?');

        return (int) $this->em->getConnection()->fetchOne($sql, [(int) $issue->id, $r->url, (string) $r->rootUrl, (string) $r->type, $c->scmid ?: $c->revision]) > 0;
    }

    /** Changeset#fix_issue: status / done ratio from the matching commit_update_keywords rule, with a journal */
    private function fixIssue(Changeset $c, Issue $issue, string $action, array $rules): void
    {
        $this->em->refresh($issue);
        if ($issue->isClosed()) {
            return;
        }
        $rule = null;
        foreach ($rules as $r) {
            if (\in_array($action, $r['keywords'], true) && (empty($r['if_tracker_id']) || (string) $r['if_tracker_id'] === (string) $issue->tracker->id)) {
                $rule = $r;
                break;
            }
        }
        $changed = false;
        if (null !== $rule) {
            if (!empty($rule['status_id']) && ($status = $this->em->find(IssueStatus::class, (int) $rule['status_id'])) && $status->id !== $issue->status->id) {
                $issue->status = $status;
                $changed = true;
            }
            if (isset($rule['done_ratio']) && '' !== (string) $rule['done_ratio'] && (int) $rule['done_ratio'] !== $issue->doneRatio) {
                $issue->doneRatio = (int) $rule['done_ratio'];
                $changed = true;
            }
        }
        if (null !== $this->hooks) {
            // plugins may change the issue (Redmine saves it when issue.changes.any?)
            $before = self::issueFingerprint($issue);
            $this->hooks->call('model_changeset_scan_commit_for_issue_ids_pre_issue_update', ['changeset' => $c, 'issue' => $issue, 'action' => $action]);
            $changed = $changed || $before !== self::issueFingerprint($issue);
        }
        if (!$changed) {
            return;
        }
        $user = $c->user ?? $this->currentUser->anonymous();
        $lang = $this->settings->string('default_language') ?: 'en';
        $this->issues->setNotes($issue, $this->i18n->l('text_status_changed_by_changeset', ['value' => self::textTag($c, $issue->project)], $lang));
        try {
            $errors = $this->issues->save($issue, $user);
            if ([] !== $errors) {
                $this->logger?->warning(\sprintf('Issue #%d could not be saved by changeset %d: %s', $issue->id, $c->id, implode(', ', $errors)));
                $this->em->refresh($issue);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning(\sprintf('Issue #%d could not be saved by changeset %d: %s', $issue->id, $c->id, $e->getMessage()));
        }
    }

    private ?\App\Plugin\Hook\Hooks $hooks = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setHooks(\App\Plugin\Hook\Hooks $hooks): void
    {
        $this->hooks = $hooks;
    }

    /** Scalar/association snapshot of an issue, to detect the changes made by hook listeners */
    private static function issueFingerprint(Issue $issue): string
    {
        $out = [];
        foreach (get_object_vars($issue) as $k => $v) {
            $out[$k] = match (true) {
                null === $v, \is_scalar($v) => $v,
                $v instanceof \DateTimeInterface => $v->format('c'),
                \is_object($v) && property_exists($v, 'id') => $v::class.'#'.($v->id ?? ''),
                default => null,
            };
        }

        return serialize($out);
    }

    /** Changeset#log_time */
    private function logTime(Changeset $c, Issue $issue, string $hours): void
    {
        if (null === $c->user) {
            return;
        }
        $h = self::parseHours($hours);
        if (null === $h || $h <= 0) {
            return;
        }
        $activityId = $this->settings->int('commit_logtime_activity_id');
        $activity = $activityId > 0 ? $this->em->find(TimeEntryActivity::class, $activityId) : null;
        $activity ??= $this->em->getRepository(TimeEntryActivity::class)->findOneBy(['isDefault' => true, 'project' => null])
            ?? $this->em->getRepository(TimeEntryActivity::class)->findOneBy(['project' => null], ['position' => 'ASC']);
        if (null === $activity) {
            return;
        }
        $lang = $this->settings->string('default_language') ?: 'en';
        $te = new TimeEntry();
        $te->project = $issue->project;
        $te->issue = $issue;
        $te->user = $c->user;
        $te->author = $c->user;
        $te->hours = $h;
        $te->activity = $activity;
        $te->comments = mb_substr($this->i18n->l('text_time_logged_by_changeset', ['value' => self::textTag($c, $issue->project)], $lang), 0, 1024);
        $te->setSpentOn($c->commitDate ?? new \DateTimeImmutable('today'));
        $this->em->persist($te);
        $this->em->flush();
    }

    /** Redmine's String#to_hours for the formats of TIMELOG_RE */
    public static function parseHours(string $s): ?float
    {
        $s = strtolower(trim($s));
        if (preg_match('/^(\d+)(?:h|hours?)(?:(\d+)(?:m|min)?)?$/', $s, $m)) {
            return (int) $m[1] + (isset($m[2]) ? (int) $m[2] / 60 : 0);
        }
        if (preg_match('/^(\d+)(?:m|min)$/', $s, $m)) {
            return (int) $m[1] / 60;
        }
        if (preg_match('/^(\d+):(\d+)$/', $s, $m)) {
            return (int) $m[1] + (int) $m[2] / 60;
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)h?$/', $s, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    /** Stats: commits and changes per month over the last 12 months / per author (graph data) */
    public function graphCommitsPerMonth(Repository $repository): array
    {
        $today = $this->i18n->today();
        $from = $today->modify('first day of this month')->modify('-11 months');
        $conn = $this->em->getConnection();
        $commits = array_fill(0, 12, 0);
        $changes = array_fill(0, 12, 0);
        foreach ($conn->fetchAllAssociative('SELECT commit_date, COUNT(*) AS n FROM changesets WHERE repository_id = ? AND commit_date BETWEEN ? AND ? GROUP BY commit_date', [(int) $repository->id, $from->format('Y-m-d'), $today->format('Y-m-d')]) as $r) {
            $commits[((int) $today->format('n') - (int) substr((string) $r['commit_date'], 5, 2) + 12) % 12] += (int) $r['n'];
        }
        foreach ($conn->fetchAllAssociative('SELECT cs.commit_date, COUNT(*) AS n FROM changes c JOIN changesets cs ON cs.id = c.changeset_id WHERE cs.repository_id = ? AND cs.commit_date BETWEEN ? AND ? GROUP BY cs.commit_date', [(int) $repository->id, $from->format('Y-m-d'), $today->format('Y-m-d')]) as $r) {
            $changes[((int) $today->format('n') - (int) substr((string) $r['commit_date'], 5, 2) + 12) % 12] += (int) $r['n'];
        }
        $labels = [];
        for ($m = 0; $m < 12; ++$m) {
            $labels[] = $this->i18n->monthName((((int) $today->format('n') - 1 - $m) % 12 + 12) % 12 + 1);
        }

        return ['labels' => array_reverse($labels), 'commits' => array_reverse($commits), 'changes' => array_reverse($changes)];
    }

    public function graphCommitsPerAuthor(Repository $repository): array
    {
        $conn = $this->em->getConnection();
        $stats = [];
        foreach ($conn->fetchAllAssociative('SELECT committer, user_id, COUNT(*) AS n FROM changesets WHERE repository_id = ? GROUP BY committer, user_id', [(int) $repository->id]) as $r) {
            $name = $r['user_id'] && ($u = $this->em->find(User::class, (int) $r['user_id'])) ? $u->getName() : (string) $r['committer'];
            $stats[$name]['commits'] = ($stats[$name]['commits'] ?? 0) + (int) $r['n'];
        }
        foreach ($conn->fetchAllAssociative('SELECT cs.committer, cs.user_id, COUNT(*) AS n FROM changes c JOIN changesets cs ON cs.id = c.changeset_id WHERE cs.repository_id = ? GROUP BY cs.committer, cs.user_id', [(int) $repository->id]) as $r) {
            $name = $r['user_id'] && ($u = $this->em->find(User::class, (int) $r['user_id'])) ? $u->getName() : (string) $r['committer'];
            $stats[$name]['changes'] = ($stats[$name]['changes'] ?? 0) + (int) $r['n'];
        }
        $labels = array_map(static fn ($n) => preg_replace('/<.+@.+>/', '', (string) $n), array_map('strval', array_keys($stats)));
        $commits = array_map(static fn ($s) => $s['commits'] ?? 0, array_values($stats));
        $changes = array_map(static fn ($s) => $s['changes'] ?? 0, array_values($stats));
        while (\count($labels) < 10) {
            $labels[] = '';
            $commits[] = 0;
            $changes[] = 0;
        }
        $labels = array_reverse($labels);
        $commits = array_reverse($commits);
        $changes = array_reverse($changes);

        return ['labels' => $labels, 'commits' => $commits, 'changes' => $changes];
    }
}
