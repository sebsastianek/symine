<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\IssueRelation;

/**
 * Outcome of the "add a relation" form or API call, which may name several related issues at once.
 */
final class IssueRelationSubmission
{
    /**
     * @param IssueRelation|null                                         $relation the relation created for the last issue named, if it could be
     * @param list<array{issue_to_id: int|null, errors: list<string>}> $unsaved  the related issues refused, with the reasons
     * @param list<string>                                               $errors   the reasons of the last refusal
     */
    public function __construct(
        public readonly ?IssueRelation $relation,
        public readonly array $unsaved,
        public readonly array $errors,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->unsaved;
    }
}
