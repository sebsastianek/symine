<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\Version;
use App\Event\ContentEvent;
use App\Service\AttachmentService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of FilesController (files_controller.rb): files attached to the project or its versions. */
final class FilesController extends AbstractController
{
    private const array SORT_COLUMNS = ['filename' => 'filename', 'created_on' => 'createdOn', 'size' => 'filesize', 'downloads' => 'downloads'];

    public function __construct(
        private readonly ApiRenderer $api,
        private readonly AttachmentService $attachments,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    #[Route('/projects/{project_id}/files.{_format}', name: 'project_files', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_files', $project);

        $criteria = $this->sortCriteria((string) $request->query->get('sort', ''));
        $versions = array_reverse($this->projects->sortVersions($this->em->getRepository(Version::class)->findBy(['project' => $project])));
        $containers = [['container' => $project, 'version' => null, 'files' => $this->filesOf('Project', (int) $project->id, $criteria)]];
        foreach ($versions as $v) {
            $containers[] = ['container' => $v, 'version' => $v, 'files' => $this->filesOf('Version', (int) $v->id, $criteria)];
        }

        if ($this->isApi($request)) {
            $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();
            $files = [];
            foreach ($containers as $c) {
                foreach ($c['files'] as $a) {
                    $item = [
                        'id' => $a->id, 'filename' => $a->filename, 'filesize' => $a->getFilesize(), 'content_type' => $a->contentType,
                        'description' => $a->description, 'content_url' => $base.'/attachments/download/'.$a->id.'/'.rawurlencode($a->filename),
                    ];
                    if ($a->isImage()) {
                        $item['thumbnail_url'] = $base.'/attachments/thumbnail/'.$a->id;
                    }
                    $item['author'] = ['id' => $a->author->id, 'name' => $a->author->getName()];
                    $item['created_on'] = ApiRenderer::time($a->createdOn);
                    if (null !== $c['version']) {
                        $item['version'] = ['id' => $c['version']->id, 'name' => $c['version']->name];
                    }
                    $item['digest'] = $a->digest;
                    $item['downloads'] = $a->downloads;
                    $files[] = $item;
                }
            }

            return $this->api->render($request, ['files' => $files]);
        }

        $headers = [];
        $query = $request->query->all();
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
                'caption' => $this->l($caption), 'css' => $css, 'icon' => $icon,
                'url' => $this->generateUrl('project_files', ['project_id' => $project->identifier]).'?'.http_build_query(array_merge($query, ['sort' => $param])),
            ];
        }

        return $this->render('files/index.html.twig', [
            'project' => $project, 'containers' => $containers, 'headers' => $headers,
            'delete_allowed' => $this->auth->allowedTo('manage_files', $project),
            'main_menu_selected' => 'files', 'page_title' => $this->l('label_attachment_plural'),
            'body_css' => 'controller-files action-index',
        ]);
    }

    #[Route('/projects/{project_id}/files/new', name: 'file_new', methods: ['GET'])]
    public function new(string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_files', $project);

        return $this->renderNew($project);
    }

    #[Route('/projects/{project_id}/files.{_format}', name: 'file_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_files', $project);
        $isApi = $this->isApi($request);
        $payload = $isApi ? ApiRenderer::payload($request) : [];
        $fileParams = (array) ($payload['file'] ?? $request->request->all('file'));
        $versionId = $request->request->get('version_id') ?? ($payload['version_id'] ?? null) ?? ($fileParams['version_id'] ?? null);
        $container = $project;
        if (null !== $versionId && '' !== (string) $versionId) {
            $container = $this->em->getRepository(Version::class)->findOneBy(['id' => (int) $versionId, 'project' => $project]);
            if (null === $container) {
                throw $this->createNotFoundException();
            }
        }
        $uploads = null;
        if ($isApi) {
            $uploads = !empty($fileParams['token']) ? [$fileParams] : (array) ($payload['attachments'] ?? []);
        }
        [$atts, $errors] = $this->attachments->fromRequest($request, $this->user(), $uploads);
        if ([] !== $atts) {
            $this->attachments->attachTo($atts, $container instanceof Version ? 'Version' : 'Project', (int) $container->id);
            $this->em->flush();
            $this->events->dispatch(new ContentEvent('attachment', 'created', $atts[0], $this->user(), ['container' => $container, 'attachments' => $atts, 'project' => $project]));
            if ($isApi) {
                return $this->api->noContent();
            }
            if ([] !== $errors) {
                $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => \count($errors)]));
            }
            $this->addFlash('notice', $this->l('label_file_added'));

            return $this->redirectToRoute('project_files', ['project_id' => $project->identifier]);
        }
        if ($isApi) {
            return new Response('', 400);
        }
        $this->addFlash('error', $this->l('label_attachment').' '.$this->l('activerecord.errors.messages.invalid'));

        return $this->renderNew($project, 422);
    }

    private function renderNew(Project $project, int $status = 200): Response
    {
        $versions = $this->em->getRepository(Version::class)->findBy(['project' => $project]);

        return $this->render('files/new.html.twig', [
            'project' => $project, 'versions' => $this->projects->sortVersions($versions),
            'main_menu_selected' => 'files', 'page_title' => $this->l('label_attachment_new'),
            'body_css' => 'controller-files action-new',
        ], new Response(null, $status));
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

    /** @return list<Attachment> */
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
