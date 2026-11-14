<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Entity\Issue;
use App\Entity\IssueRelation;
use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "add a relation" form of the issue page and its REST API equivalent (issue_relations#create): relates
 * the issue to each of the issues named in the form.
 */
final class IssueRelationForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueService $issues,
        private readonly IssueApiPresenter $presenter,
        private readonly Localizer $i18n,
    ) {
    }

    public function submit(Request $request, Issue $issue): IssueRelationSubmission
    {
        $params = ApiRequestMatcher::isApiRequest($request) ? (array) (ApiRenderer::payload($request)['relation'] ?? []) : $request->request->all('relation');
        $toIds = $params['issue_to_id'] ?? '';
        $toIds = \is_array($toIds) ? $toIds : (is_numeric($toIds) && !\is_string($toIds) ? [$toIds] : array_values(array_filter(array_map('trim', explode(',', (string) $toIds)), static fn ($v) => '' !== $v)));
        if ([] === $toIds) {
            $toIds = [''];
        }
        $type = (string) ($params['relation_type'] ?? IssueRelation::TYPE_RELATES);
        $delay = isset($params['delay']) && '' !== (string) $params['delay'] ? (int) $params['delay'] : null;
        $unsaved = [];
        $relation = null;
        $lastErrors = [];
        foreach ($toIds as $toId) {
            $toId = trim((string) $toId, " #\t");
            $to = ctype_digit($toId) ? (int) $toId : null;
            [$relation, $errors] = $this->issues->createRelation($issue, $to, $type, $delay);
            if (null === $relation) {
                if (null === $to) {
                    $errors = [$this->i18n->l('field_issue_to').' '.$this->i18n->l('activerecord.errors.messages.blank')];
                }
                $unsaved[] = ['issue_to_id' => $to, 'errors' => $errors];
                $lastErrors = $errors;
            }
        }

        return new IssueRelationSubmission($relation, $unsaved, $lastErrors);
    }

    /**
     * The relations of the issue the user may see, as they are now in the database (after one was added or
     * removed).
     *
     * @return list<IssueRelation>
     */
    public function relationsOf(Issue $issue): array
    {
        $this->em->refresh($issue);

        return $this->presenter->visibleRelations($issue);
    }
}
