<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Entity\Changeset;
use App\Entity\Repository;
use App\Service\Settings;
use App\Tests\Content\ContentWebTestCase;

/**
 * Functional tests of the repository browser against the seeded database (project "ecommerce").
 * Every SCM is enabled with a permissive scm_xxx_path_regexp (SYMINE_SCM_XXX_PATH_REGEXP).
 */
abstract class ScmWebTestCase extends ContentWebTestCase
{
    public const array SCMS = ['SUBVERSION', 'MERCURIAL', 'CVS', 'BAZAAR', 'GIT', 'FILESYSTEM'];

    protected function setUp(): void
    {
        foreach (self::SCMS as $scm) {
            $_SERVER['SYMINE_SCM_'.$scm.'_PATH_REGEXP'] = '.+';
        }
        parent::setUp();
        $this->settings()->setMany(['enabled_scm' => ['Subversion', 'Mercurial', 'Cvs', 'Bazaar', 'Git', 'Filesystem']]);
    }

    protected function tearDown(): void
    {
        foreach (self::SCMS as $scm) {
            unset($_SERVER['SYMINE_SCM_'.$scm.'_PATH_REGEXP']);
        }
        parent::tearDown();
    }

    protected function settings(): Settings
    {
        return static::getContainer()->get(Settings::class);
    }

    /** Creates a repository through the project settings form (as admin) */
    protected function createRepository(string $scm, array $attrs): Repository
    {
        $this->login('admin');
        $code = $this->submit('/projects/ecommerce/repositories', ['repository_scm' => $scm, 'repository' => $attrs]);
        self::assertSame(302, $code, (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $repo = $this->em->getRepository(Repository::class)->findOneBy(['type' => 'Repository::'.$scm], ['id' => 'DESC']);
        self::assertNotNull($repo);

        return $repo;
    }

    protected function changesetCount(Repository $repo): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM changesets WHERE repository_id = ?', [$repo->id]);
    }

    protected function changeCount(Repository $repo): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM changes c JOIN changesets cs ON cs.id = c.changeset_id WHERE cs.repository_id = ?', [$repo->id]);
    }

    protected function changeset(Repository $repo, string $revision): ?Changeset
    {
        $this->em->clear();

        return $this->em->getRepository(Changeset::class)->findOneBy(['repository' => $repo->id, 'revision' => $revision]);
    }

    /** @return list<int> ids of the issues referenced by the changeset with the given comment */
    protected function referencedIssues(Repository $repo, string $commentStart): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT ci.issue_id FROM changesets_issues ci JOIN changesets cs ON cs.id = ci.changeset_id WHERE cs.repository_id = ? AND cs.comments LIKE ? ORDER BY ci.issue_id',
            [$repo->id, $commentStart.'%'],
        ));
    }

    protected function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
