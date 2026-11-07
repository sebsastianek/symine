<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'issue_relations')]
#[ORM\Index(name: 'index_issue_relations_on_issue_from_id', columns: ['issue_from_id'])]
#[ORM\Index(name: 'index_issue_relations_on_issue_to_id', columns: ['issue_to_id'])]
#[ORM\UniqueConstraint(name: 'index_issue_relations_on_issue_from_id_and_issue_to_id', columns: ['issue_from_id', 'issue_to_id'])]
class IssueRelation
{
    public const string TYPE_RELATES = 'relates';
    public const string TYPE_DUPLICATES = 'duplicates';
    public const string TYPE_DUPLICATED = 'duplicated';
    public const string TYPE_BLOCKS = 'blocks';
    public const string TYPE_BLOCKED = 'blocked';
    public const string TYPE_PRECEDES = 'precedes';
    public const string TYPE_FOLLOWS = 'follows';
    public const string TYPE_COPIED_TO = 'copied_to';
    public const string TYPE_COPIED_FROM = 'copied_from';

    /** type => [label, sym_label, order, reverse type] (same as Redmine) */
    public const array TYPES = [
        self::TYPE_RELATES => ['label_relates_to', 'label_relates_to', 1, null],
        self::TYPE_DUPLICATES => ['label_duplicates', 'label_duplicated_by', 2, self::TYPE_DUPLICATED],
        self::TYPE_DUPLICATED => ['label_duplicated_by', 'label_duplicates', 3, self::TYPE_DUPLICATES],
        self::TYPE_BLOCKS => ['label_blocks', 'label_blocked_by', 4, self::TYPE_BLOCKED],
        self::TYPE_BLOCKED => ['label_blocked_by', 'label_blocks', 5, self::TYPE_BLOCKS],
        self::TYPE_PRECEDES => ['label_precedes', 'label_follows', 6, self::TYPE_FOLLOWS],
        self::TYPE_FOLLOWS => ['label_follows', 'label_precedes', 7, self::TYPE_PRECEDES],
        self::TYPE_COPIED_TO => ['label_copied_to', 'label_copied_from', 8, self::TYPE_COPIED_FROM],
        self::TYPE_COPIED_FROM => ['label_copied_from', 'label_copied_to', 9, self::TYPE_COPIED_TO],
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Issue::class, inversedBy: 'relationsFrom')]
    #[ORM\JoinColumn(nullable: false)]
    public Issue $issueFrom;

    #[ORM\ManyToOne(targetEntity: Issue::class, inversedBy: 'relationsTo')]
    #[ORM\JoinColumn(nullable: false)]
    public Issue $issueTo;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $relationType = self::TYPE_RELATES;

    #[ORM\Column(nullable: true)]
    public ?int $delay = null;

    /** The issue on the other side of the relation */
    public function otherIssue(Issue $issue): Issue
    {
        return $this->issueFrom->id === $issue->id ? $this->issueTo : $this->issueFrom;
    }

    /** Label of the relation seen from the given issue */
    public function labelFor(Issue $issue): string
    {
        $t = self::TYPES[$this->relationType] ?? self::TYPES[self::TYPE_RELATES];

        return $this->issueFrom->id === $issue->id ? $t[0] : $t[1];
    }
}
