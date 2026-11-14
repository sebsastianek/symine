<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Attachment;
use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Service\Wiki\WikiPageRevision;
use App\Service\Wiki\WikiPageView;
use App\Service\WikiService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the REST API structures of app/views/wiki/index.api.rsb and show.api.rsb.
 */
final class WikiApiPresenter
{
    /** Elements rendered with XML attributes in wiki/*.api.rsb */
    public const array XML_ATTRIBUTES = ['parent' => ['title']];

    public function __construct(
        private readonly WikiService $wikis,
        private readonly WikiPageView $views,
    ) {
    }

    /**
     * index.api.rsb: the pages of the wiki.
     *
     * @return array<string, mixed>
     */
    public function index(Wiki $wiki): array
    {
        $project = $wiki->project;

        return ['wiki_pages' => array_map(static fn (WikiPage $p) => array_filter([
            'title' => $p->title,
            'parent' => $p->parent ? ['title' => $p->parent->title] : null,
            'version' => $p->content?->version,
            'project' => ['id' => $project->id, 'name' => $project->name],
            'created_on' => ApiRenderer::time($p->createdOn),
            'updated_on' => ApiRenderer::time($p->content?->updatedOn),
        ], static fn ($v, $k) => 'parent' !== $k || null !== $v, \ARRAY_FILTER_USE_BOTH), $this->wikis->pages($wiki))];
    }

    /**
     * show.api.rsb: the page (or the version shown), with its files when the "include" parameter asks for attachments.
     *
     * @return array<string, mixed>
     */
    public function page(Request $request, Wiki $wiki, WikiPageRevision $revision): array
    {
        $project = $wiki->project;
        $page = $revision->page;
        $content = $revision->content;
        $data = [
            'title' => $page->title,
        ];
        if ($page->parent) {
            $data['parent'] = ['title' => $page->parent->title];
        }
        $data['text'] = $this->views->text($revision);
        $data['version'] = $content->version;
        if ($content->author) {
            $data['author'] = ['id' => $content->author->id, 'name' => $content->author->getName()];
        }
        $data['comments'] = $content->comments;
        $data['project'] = ['id' => $project->id, 'name' => $project->name];
        $data['created_on'] = ApiRenderer::time($page->createdOn);
        $data['updated_on'] = ApiRenderer::time($content->updatedOn);
        if (\in_array('attachments', explode(',', (string) $request->query->get('include', '')), true)) {
            $data['attachments'] = array_map(fn (Attachment $a) => $this->attachment($request, $a), $this->wikis->attachmentsOf($page));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function attachment(Request $request, Attachment $a): array
    {
        $base = $request->getSchemeAndHttpHost();

        return [
            'id' => $a->id,
            'filename' => $a->filename,
            'filesize' => $a->getFilesize(),
            'content_type' => $a->contentType,
            'description' => $a->description,
            'content_url' => $base.'/attachments/download/'.$a->id.'/'.rawurlencode($a->filename),
            'author' => ['id' => $a->author->id, 'name' => $a->author->getName()],
            'created_on' => ApiRenderer::time($a->createdOn),
        ];
    }
}
