<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\ProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The files of a project (FilesController#index): the files attached to the project and to each of its
 * versions, sorted as asked by the "sort" parameter ("size:desc,filename"; by file name when absent).
 */
final class ProjectFilesView
{
    /** sort key => Attachment property */
    private const array SORT_COLUMNS = ['filename' => 'filename', 'created_on' => 'createdOn', 'size' => 'filesize', 'downloads' => 'downloads'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectService $projects,
        private readonly Authorization $auth,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The project first, then its versions (most recent first), each with its files.
     *
     * @return list<array{container: Project|Version, version: ?Version, files: list<Attachment>}>
     */
    public function containers(Project $project, string $sort): array
    {
        $criteria = $this->sortCriteria($sort);
        $versions = array_reverse($this->projects->sortVersions($this->em->getRepository(Version::class)->findBy(['project' => $project])));
        $containers = [['container' => $project, 'version' => null, 'files' => $this->filesOf('Project', (int) $project->id, $criteria)]];
        foreach ($versions as $v) {
            $containers[] = ['container' => $v, 'version' => $v, 'files' => $this->filesOf('Version', (int) $v->id, $criteria)];
        }

        return $containers;
    }

    /** @return array<string, mixed> variables of files/index.html.twig */
    public function view(Project $project, Request $request): array
    {
        $sort = (string) $request->query->get('sort', '');

        return [
            'project' => $project,
            'containers' => $this->containers($project, $sort),
            'headers' => $this->headers($project, $this->sortCriteria($sort), $request->query->all()),
            'delete_allowed' => $this->auth->allowedTo('manage_files', $project),
        ];
    }

    /**
     * The sortable column headers (sort_header_tag): a click on a header sorts by its column first, reversing
     * the order when the list is already sorted by it.
     *
     * @param list<array{0: string, 1: string}> $criteria
     * @param array<string, mixed>              $query    the parameters of the page, kept in the links
     *
     * @return list<array{caption: string, css: ?string, icon: string, url: string}>
     */
    private function headers(Project $project, array $criteria, array $query): array
    {
        $headers = [];
        foreach (['filename' => ['field_filename', 'asc'], 'created_on' => ['label_date', 'desc'], 'size' => ['field_filesize', 'desc'], 'downloads' => ['label_downloads_abbr', 'desc']] as $col => [$caption, $defaultOrder]) {
            $css = null;
            $icon = '';
            $order = $defaultOrder;
            if (($criteria[0][0] ?? null) === $col) {
                if ('asc' === $criteria[0][1]) {
                    [$css, $icon, $order] = ['sort asc icon icon-sorted-desc', 'angle-up', 'desc'];
                } else {
                    [$css, $icon, $order] = ['sort desc icon icon-sorted-asc', 'angle-down', 'asc'];
                }
            }
            $new = array_values(array_filter($criteria, static fn ($c) => $c[0] !== $col));
            array_unshift($new, [$col, $order]);
            $new = \array_slice($new, 0, 3);
            $param = implode(',', array_map(static fn ($c) => $c[0].('desc' === $c[1] ? ':desc' : ''), $new));
            $headers[] = [
                'caption' => $this->i18n->l($caption), 'css' => $css, 'icon' => $icon,
                'url' => $this->urls->generate('project_files', ['project_id' => $project->identifier]).'?'.http_build_query(array_merge($query, ['sort' => $param])),
            ];
        }

        return $headers;
    }

    /** @return list<array{0: string, 1: string}> sort criteria from "size:desc,filename" (default filename asc) */
    private function sortCriteria(string $param): array
    {
        $criteria = [];
        foreach (explode(',', $param) as $part) {
            [$key, $order] = array_pad(explode(':', trim($part), 2), 2, 'asc');
            if (isset(self::SORT_COLUMNS[$key]) && !\in_array($key, array_column($criteria, 0), true)) {
                $criteria[] = [$key, 'desc' === $order ? 'desc' : 'asc'];
            }
        }

        return [] === $criteria ? [['filename', 'asc']] : \array_slice($criteria, 0, 3);
    }

    /**
     * @param list<array{0: string, 1: string}> $criteria
     *
     * @return list<Attachment>
     */
    private function filesOf(string $type, int $id, array $criteria): array
    {
        $order = [];
        foreach ($criteria as [$key, $dir]) {
            $order[self::SORT_COLUMNS[$key]] = strtoupper($dir);
        }
        $order['id'] ??= 'ASC';

        return $this->em->getRepository(Attachment::class)->findBy(['containerType' => $type, 'containerId' => $id], $order);
    }
}
