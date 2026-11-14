<?php

declare(strict_types=1);

namespace App\Service\Content\News;

use App\Api\ApiRenderer;
use App\Entity\News;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\Content\Submission;
use Symfony\Component\HttpFoundation\Request;

/**
 * The news form, submitted by the browser (news[title], news[summary], news[description], attachments[..]) or
 * through the REST API ({"news": {..., "uploads": [..]}}).
 */
final class NewsForm
{
    public function __construct(
        private readonly NewsService $news,
        private readonly AttachmentService $attachments,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    /** Saves a new news item when what was submitted is valid */
    public function create(News $news, Request $request): Submission
    {
        $attrs = $this->params($request);
        $this->assign($news, $attrs);
        $errors = $this->validate($news);
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->currentUser->get(), $this->uploads($request, $attrs));
        if ([] !== $errors) {
            return new Submission($errors);
        }
        $this->news->create($news, $atts);

        return new Submission([], \count($attErrors));
    }

    /** Saves the changes submitted for a news item when they are valid */
    public function update(News $news, Request $request): Submission
    {
        $attrs = $this->params($request);
        $this->assign($news, $attrs);
        $errors = $this->validate($news);
        if ([] !== $errors) {
            return new Submission($errors);
        }
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->currentUser->get(), $this->uploads($request, $attrs));
        $this->news->update($news, $atts);

        return new Submission([], \count($attErrors));
    }

    /**
     * @param Project|null $project the project of the form, null when the project is chosen in the form
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(News $news, ?Project $project, array $errors = []): array
    {
        return [
            'project' => $project ?? $news->project, 'form_project' => $project, 'news' => $news, 'errors' => $errors,
            'manage_projects' => null === $project ? $this->news->manageableProjects() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function params(Request $request): array
    {
        if (ApiRequestMatcher::isApiRequest($request)) {
            $payload = ApiRenderer::payload($request);

            return (array) ($payload['news'] ?? []);
        }

        return (array) $request->request->all('news');
    }

    /**
     * The "uploads" of an API payload; a browser form posts its files as attachments[..].
     *
     * @param array<string, mixed> $attrs
     */
    private function uploads(Request $request, array $attrs): mixed
    {
        return ApiRequestMatcher::isApiRequest($request) ? ($attrs['uploads'] ?? null) : null;
    }

    /**
     * safe_attributes 'title', 'summary', 'description'
     *
     * @param array<string, mixed> $attrs
     */
    private function assign(News $news, array $attrs): void
    {
        foreach (['title' => 'title', 'summary' => 'summary', 'description' => 'description'] as $key => $prop) {
            if (\array_key_exists($key, $attrs) && !\is_array($attrs[$key])) {
                $news->$prop = 'title' === $key ? (string) $attrs[$key] : (null === $attrs[$key] ? null : (string) $attrs[$key]);
            }
        }
    }

    /** @return list<string> */
    private function validate(News $news): array
    {
        $errors = [];
        if ('' === trim($news->title)) {
            $errors[] = $this->i18n->l('field_title').' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($news->title) > 60) {
            $errors[] = $this->i18n->l('field_title').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 60]);
        }
        if (mb_strlen((string) $news->summary) > 255) {
            $errors[] = $this->i18n->l('field_summary').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if ('' === trim((string) $news->description)) {
            $errors[] = $this->i18n->l('field_description').' '.$this->i18n->l('activerecord.errors.messages.blank');
        }

        return $errors;
    }
}
