<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\Attachment;
use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\IssueRelation;
use App\Entity\Journal;
use App\Entity\JournalDetail;
use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\Settings;
use App\Service\TextFormatter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * View helpers used by the e-mail templates (ports of IssuesHelper#details_to_strings/show_detail,
 * IssuesHelper#email_issue_attributes, link_to_attachment(only_path: false), ...).
 * Every URL is absolute, built from the host_name and protocol settings (Mailer.default_url_options).
 */
final class MailHelper
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly TextFormatter $formatter,
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
        private readonly AttachmentService $attachments,
        private readonly EntityManagerInterface $em,
        private readonly \App\Security\Authorization $auth,
    ) {
    }

    /** protocol://host[:port][/prefix] from Setting.protocol and Setting.host_name */
    public function baseUrl(): string
    {
        $protocol = $this->settings->string('protocol') ?: 'http';
        $host = trim($this->settings->string('host_name'));
        if (preg_match('#\A(https?://)?(.+?)(:(\d+))?(/.+)?\z#i', $host, $m)) {
            return $protocol.'://'.$m[2].(isset($m[4]) && '' !== $m[4] ? ':'.$m[4] : '').rtrim($m[5] ?? '', '/');
        }

        return $protocol.'://'.$host;
    }

    public function url(string $path): string
    {
        return $this->baseUrl().'/'.ltrim($path, '/');
    }

    /** Wiki formatting with absolute links/images (textilizable :only_path => false) */
    public function textilizable(?string $text, ?Project $project = null, ?object $object = null): string
    {
        // the object gives its attachments to inline images, attachment: links and {{thumbnail}}
        $html = $this->formatter->format($text, $project, null === $object ? [] : ["object" => $object]);

        return $this->absolutize($html);
    }

    public function absolutize(string $html): string
    {
        $base = $this->baseUrl();

        return preg_replace_callback('/\b(href|src)="(\/(?!\/)[^"]*)"/', static fn ($m) => $m[1].'="'.$base.$m[2].'"', $html) ?? $html;
    }

    public function issueUrl(Issue $issue, ?string $anchor = null): string
    {
        return $this->url('/issues/'.$issue->id).(null !== $anchor ? '#'.$anchor : '');
    }

    public function attachmentUrl(Attachment $a, bool $download = false): string
    {
        return $this->url(($download ? '/attachments/download/' : '/attachments/').$a->id.'/'.rawurlencode($a->filename));
    }

    /** link_to_attachment(attachment, download: true, only_path: false) */
    public function linkToAttachment(Attachment $a, bool $download = true): string
    {
        return '<a href="'.htmlspecialchars($this->attachmentUrl($a, $download)).'" class="icon icon-attachment">'.htmlspecialchars($a->filename).'</a>';
    }

    /** @return list<Attachment> */
    public function attachmentsOf(string $containerType, ?int $id): array
    {
        return null === $id ? [] : $this->attachments->forContainer($containerType, $id);
    }

    public function humanSize(int|string|null $bytes): string
    {
        $bytes = (int) $bytes;
        $units = ['byte', 'kb', 'mb', 'gb', 'tb'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 4) {
            $n /= 1024;
            ++$i;
        }
        $num = 0 === $i ? (string) $bytes : rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
        $unit = 0 === $i ? $this->i18n->l('number.human.storage_units.units.byte', ['count' => $bytes]) : $this->i18n->l('number.human.storage_units.units.'.$units[$i]);

        return $num.' '.$unit;
    }

    /** "%-37s" style separator of Redmine's text templates: ---Files------------------------------ */
    public function textSeparator(string $label): string
    {
        $pad = 37 - mb_strlen($label);

        return '---'.$label.($pad > 0 ? str_repeat('-', $pad) : '');
    }

    /**
     * IssuesHelper#email_issue_attributes.
     *
     * @return list<array{label: string, value: string}>
     */
    public function issueAttributes(Issue $issue, User $user): array
    {
        $items = [];
        $disabled = $issue->tracker->getDisabledCoreFields();
        foreach (['author', 'status', 'priority', 'assigned_to', 'category', 'fixed_version', 'start_date', 'due_date', 'parent_issue'] as $attr) {
            if (\in_array($attr, $disabled, true) || \in_array($attr.'_id', $disabled, true)) {
                continue;
            }
            $value = match ($attr) {
                'author' => isset($issue->author) ? $issue->author->getName() : '',
                'status' => $issue->status->name,
                'priority' => $issue->priority->name,
                'assigned_to' => $issue->assignedTo?->getName() ?? '',
                'category' => $issue->category?->name ?? '',
                'fixed_version' => (string) ($issue->fixedVersion?->name ?? ''),
                'start_date' => $issue->startDate?->format('Y-m-d') ?? '',
                'due_date' => $issue->dueDate?->format('Y-m-d') ?? '',
                'parent_issue' => null !== $issue->parent ? (string) $issue->parent : '',
            };
            if ('' === trim($value)) {
                continue;
            }
            $items[] = ['label' => $this->i18n->l('field_'.$attr), 'value' => $value];
        }
        $values = $this->customFields->values('Issue', $issue->id);
        foreach ($this->customFields->visibleIssueFields($issue, $user) as $cf) {
            $v = $values[$cf->id] ?? null;
            if (null === $v || [] === $v || '' === $v) {
                continue;
            }
            $formatted = $this->customFields->formatValue($cf, $v);
            if ('' === trim($formatted)) {
                continue;
            }
            $items[] = ['label' => $cf->name, 'value' => $formatted];
        }

        return $items;
    }

    /**
     * IssuesHelper#details_to_strings: localized lines describing journal details.
     *
     * @param list<JournalDetail> $details
     *
     * @return list<string> (HTML strings when $html is true)
     */
    public function detailsToStrings(array $details, bool $html): array
    {
        $strings = [];
        $multi = [];
        foreach ($details as $d) {
            if ('cf' === $d->property) {
                $cf = $this->em->find(CustomField::class, (int) $d->propKey);
                if ($cf && $cf->isMultiple()) {
                    $multi[$cf->id] ??= ['field' => $cf, 'added' => [], 'deleted' => [], 'journal' => $d->journal];
                    if (null !== $d->oldValue) {
                        $multi[$cf->id]['deleted'][] = $d->oldValue;
                    }
                    if (null !== $d->value) {
                        $multi[$cf->id]['added'][] = $d->value;
                    }
                    continue;
                }
            }
            $strings[] = $this->showDetail($d, $html);
        }
        foreach ($multi as $m) {
            if ([] !== $m['added']) {
                $d = new JournalDetail();
                $d->property = 'cf';
                $d->propKey = (string) $m['field']->id;
                $d->value = implode("\0", $m['added']);
                $d->journal = $m['journal'];
                $strings[] = $this->showDetail($d, $html, true);
            }
            if ([] !== $m['deleted']) {
                $d = new JournalDetail();
                $d->property = 'cf';
                $d->propKey = (string) $m['field']->id;
                $d->oldValue = implode("\0", $m['deleted']);
                $d->journal = $m['journal'];
                $strings[] = $this->showDetail($d, $html, true);
            }
        }

        return $strings;
    }

    /** IssuesHelper#show_detail (only_path: false) */
    public function showDetail(JournalDetail $d, bool $html, bool $multipleGroup = false): string
    {
        $multiple = $multipleGroup;
        $showDiff = false;
        $label = null;
        $value = null;
        $old = null;
        $rawValue = $d->value;
        $rawOld = $d->oldValue;
        $h = static fn (?string $s) => htmlspecialchars((string) $s, \ENT_QUOTES);

        switch ($d->property) {
            case 'attr':
                if ('description' === $d->propKey) {
                    $label = $this->i18n->l('field_description');
                    $showDiff = true;
                    break;
                }
                if ('child_id' === $d->propKey) {
                    $label = $this->i18n->l('label_subtask');
                    $value = null !== $rawValue && '' !== $rawValue ? '#'.$rawValue : null;
                    $old = null !== $rawOld && '' !== $rawOld ? '#'.$rawOld : null;
                    $multiple = true;
                    break;
                }
                $info = $this->issues->detailLabel($d);
                $label = $info['label'];
                $value = $info['new'];
                $old = $info['old'];
                if ('project_id' === $d->propKey) {
                    // project names are shown only if visible
                    $value = null !== $rawValue && '' !== $rawValue ? $this->visibleProjectName((int) $rawValue) : null;
                    $old = null !== $rawOld && '' !== $rawOld ? $this->visibleProjectName((int) $rawOld) : null;
                }
                if ('is_private' === $d->propKey) {
                    $value = null === $rawValue || '' === $rawValue ? null : $this->i18n->l('0' === $rawValue ? 'general_text_No' : 'general_text_Yes');
                    $old = null === $rawOld || '' === $rawOld ? null : $this->i18n->l('0' === $rawOld ? 'general_text_No' : 'general_text_Yes');
                }
                break;
            case 'cf':
                $cf = $this->em->find(CustomField::class, (int) $d->propKey);
                if ($cf) {
                    $label = $cf->name;
                    if (\in_array($cf->fieldFormat, ['text'], true)) {
                        $showDiff = true;
                    } else {
                        $multiple = $multiple || $cf->isMultiple();
                        $value = null !== $rawValue ? $this->customFields->formatValue($cf, $multipleGroup ? explode("\0", $rawValue) : $rawValue) : null;
                        $old = null !== $rawOld ? $this->customFields->formatValue($cf, $multipleGroup ? explode("\0", $rawOld) : $rawOld) : null;
                    }
                }
                break;
            case 'attachment':
                $label = $this->i18n->l('label_attachment');
                break;
            case 'relation':
                if (null !== $rawValue && null === $rawOld) {
                    $value = $this->relationIssue((int) $rawValue, $html);
                } elseif (null !== $rawOld && null === $rawValue) {
                    $old = $this->relationIssue((int) $rawOld, $html);
                }
                if (isset(IssueRelation::TYPES[$d->propKey])) {
                    $label = $this->i18n->l(IssueRelation::TYPES[$d->propKey][0]);
                }
                break;
        }
        $label ??= $d->propKey;
        $value ??= $multipleGroup && null !== $rawValue ? str_replace("\0", ', ', $rawValue) : $rawValue;
        $old ??= $multipleGroup && null !== $rawOld ? str_replace("\0", ', ', $rawOld) : $rawOld;

        if ($html) {
            $label = '<strong>'.$h($label).'</strong>';
            if (null !== $rawOld) {
                $old = 'relation' === $d->property ? $old : '<i>'.$h($old).'</i>';
                if (null === $rawValue || '' === $rawValue) {
                    if ('relation' !== $d->property) {
                        $old = '<del>'.$old.'</del>';
                    }
                }
            }
            if ('attachment' === $d->property && null !== $rawValue && '' !== $rawValue
                && ($a = $this->em->find(Attachment::class, (int) $d->propKey))) {
                $value = '<a href="'.$h($this->attachmentUrl($a)).'" class="icon icon-attachment">'.$h($a->filename).'</a>';
            } elseif (null !== $value && 'relation' !== $d->property) {
                $value = '<i>'.$h($value).'</i>';
            }
        }

        if ($showDiff) {
            $s = $this->i18n->l('text_journal_changed_no_detail', ['label' => $label]);
            if ($html && null !== $d->id) {
                $url = $this->url('/journals/'.$d->journal->id.'/diff?detail_id='.$d->id);
                $s .= ' (<a href="'.$h($url).'" title="'.$h($this->i18n->l('label_view_diff')).'">'.$h($this->i18n->l('label_diff')).'</a>)';
            }

            return $s;
        }
        if (null !== $rawValue && '' !== $rawValue) {
            return match ($d->property) {
                'attr', 'cf' => null !== $rawOld && '' !== $rawOld
                    ? $this->i18n->l('text_journal_changed', ['label' => $label, 'old' => $old, 'new' => $value])
                    : ($multiple ? $this->i18n->l('text_journal_added', ['label' => $label, 'value' => $value]) : $this->i18n->l('text_journal_set_to', ['label' => $label, 'value' => $value])),
                default => $this->i18n->l('text_journal_added', ['label' => $label, 'value' => $value]),
            };
        }

        return $this->i18n->l('text_journal_deleted', ['label' => $label, 'old' => $old]);
    }

    private function visibleProjectName(int $id): ?string
    {
        $p = $this->em->find(Project::class, $id);

        return $p && $this->auth->isProjectVisible($p) ? $p->name : null;
    }

    private function relationIssue(int $id, bool $html): string
    {
        $issue = $this->em->find(Issue::class, $id);
        if (null === $issue || !$this->issues->isVisible($issue)) {
            return $this->i18n->l('label_issue').' #'.$id;
        }
        if (!$html) {
            return (string) $issue;
        }

        return '<a href="'.htmlspecialchars($this->issueUrl($issue)).'" class="issue">'.htmlspecialchars($issue->tracker->name.' #'.$issue->id).'</a>'.htmlspecialchars(': '.$issue->subject);
    }

    /** IssuesHelper#due_date_distance_in_words */
    public function dueDateDistance(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '';
        }
        $today = $this->i18n->today();
        $d = \DateTimeImmutable::createFromInterface($date)->setTimezone($today->getTimezone())->setTime(0, 0);
        $words = $this->i18n->distanceOfTimeInWords(new \DateTimeImmutable($today->format('Y-m-d').' 00:00:00', $today->getTimezone()), $d);

        return $this->i18n->l($d < $today ? 'label_roadmap_overdue' : 'label_roadmap_due_in', ['value' => $words]);
    }

    /** link_to_issue(issue, project: true, only_path: false) */
    public function linkToIssue(Issue $issue, bool $withProject = true): string
    {
        $s = '<a href="'.htmlspecialchars($this->issueUrl($issue)).'" class="issue tracker-'.$issue->tracker->id.' status-'.$issue->status->id.' priority-'.$issue->priority->id.($issue->isClosed() ? ' closed' : '').'">'
            .htmlspecialchars($issue->tracker->name.' #'.$issue->id).'</a>'.htmlspecialchars(': '.$issue->subject);

        return $withProject ? htmlspecialchars($issue->project->name.' - ').$s : $s;
    }
}
