<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\IssueService;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Quotes the description of an issue or the notes of one of its journals into new notes (JournalsController#new).
 */
final class IssueQuote
{
    public function __construct(
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly IssueService $issues,
        private readonly JournalFinder $journals,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /** Quoting is for those who may add notes to the issue */
    public function authorize(Issue $issue): void
    {
        if (!$this->issues->isNotesAddable($issue) && !$this->auth->allowedTo('add_issue_notes', $issue->project)) {
            $this->guard->deny();
        }
    }

    /**
     * The text to add to the notes: "X wrote:" followed by the selected text sent by the page (quote), else
     * by the whole notes of the journal named by journal_id, or by the description of the issue.
     *
     * @return array{content: string, private: bool} private: the quoted notes are private, so is the reply
     */
    public function quote(Request $request, Issue $issue): array
    {
        $json = json_decode((string) $request->getContent(), true);
        $partial = \is_array($json) ? ($json['quote'] ?? null) : $request->request->get('quote');
        $lang = $this->settings->string('default_language') ?: 'en';
        $journal = null;
        $journalId = $request->query->get('journal_id') ?? $request->request->get('journal_id');
        if (null !== $journalId && '' !== $journalId) {
            $journal = $this->journals->find((int) $journalId);
            if ($journal->issue->id !== $issue->id) {
                throw new NotFoundHttpException();
            }
            $indice = (string) ($request->query->get('journal_indice') ?? $request->request->get('journal_indice', ''));
            $header = $this->i18n->l('text_user_wrote_in', ['value' => $journal->user->getName(), 'link' => '#note-'.$indice], $lang)."\n> ";
            $text = $journal->notes;
        } else {
            $header = $this->i18n->l('text_user_wrote', ['value' => $issue->author->getName()], $lang)."\n> ";
            $text = $issue->description;
        }

        return [
            'content' => self::build($header, (string) $text, \is_string($partial) ? $partial : null),
            'private' => null !== $journal && $journal->privateNotes,
        ];
    }

    /** The header followed by the text as a quote ("> " lines), its preformatted blocks left out unless selected */
    public static function build(string $header, string $text, ?string $partial = null): string
    {
        $quote = null !== $partial && '' !== trim($partial) ? $partial : (string) preg_replace('#<pre>(.*?)</pre>#s', '[...]', trim($text));

        return $header.preg_replace('/(\r?\n|\r\n?)/', "\n> ", $quote)."\n\n";
    }
}
