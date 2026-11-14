<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\News;
use App\Service\AttachmentService;
use App\Service\ReactionService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the REST API structures of app/views/news/index.api.rsb and show.api.rsb.
 */
final class NewsApiPresenter
{
    /** Elements rendered with XML attributes in news/show.api.rsb */
    public const array XML_ATTRIBUTES = ['comment' => ['id']];

    public function __construct(
        private readonly AttachmentApiPresenter $attachmentPresenter,
        private readonly AttachmentService $attachments,
        private readonly ReactionService $reactions,
    ) {
    }

    /**
     * index.api.rsb: a page of the news list.
     *
     * @param list<News>                     $newss
     * @param array{offset: int, limit: int} $pager
     *
     * @return array<string, mixed>
     */
    public function index(array $newss, int $count, array $pager): array
    {
        return [
            'news' => array_map(fn (News $n) => $this->news($n, false), $newss),
            'total_count' => $count, 'offset' => $pager['offset'], 'limit' => $pager['limit'],
        ];
    }

    /**
     * show.api.rsb: the news item with what the "include" parameter asks for (attachments, comments, reactions).
     *
     * @param list<Comment> $comments
     *
     * @return array<string, mixed>
     */
    public function shown(Request $request, News $news, array $comments): array
    {
        $include = array_map('trim', explode(',', (string) $request->query->get('include', '')));
        $data = $this->news($news, true);
        $withReactions = \in_array('reactions', $include, true);
        if ($withReactions && null !== ($r = $this->reactions->apiReactions($news))) {
            $data['reactions'] = $r;
        }
        if (\in_array('attachments', $include, true)) {
            $data['attachments'] = array_map(fn (Attachment $a) => $this->attachmentPresenter->attributes($request, $a), $this->attachments->forContainer('News', $news->id));
        }
        if (\in_array('comments', $include, true)) {
            if ($withReactions) {
                $this->reactions->preload($comments);
            }
            $data['comments'] = array_map(fn (Comment $c) => [
                'id' => $c->id, 'author' => ['id' => $c->author->id, 'name' => $c->author->getName()], 'content' => $c->content,
            ] + ($withReactions && null !== ($r = $this->reactions->apiReactions($c)) ? ['reactions' => $r] : []), $comments);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function news(News $n, bool $show): array
    {
        $data = ['id' => $n->id];
        if ($n->project) {
            $data['project'] = ['id' => $n->project->id, 'name' => $n->project->name];
        }
        $data['author'] = ['id' => $n->author->id, 'name' => $n->author->getName()];
        $data['title'] = $n->title;
        if (!$show || '' !== trim((string) $n->summary)) {
            $data['summary'] = $n->summary;
        }
        $data['description'] = $n->description;
        $data['created_on'] = ApiRenderer::time($n->createdOn);

        return $data;
    }
}
