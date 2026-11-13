<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Service\IssueService;
use App\Service\JournalDiff;
use App\Twig\IssuesExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's JournalsController: notes edition, description diff, quote and the changes feed. */
final class JournalsController extends AbstractController
{
    public function __construct(
        private readonly IssueService $issues,
        private readonly ApiRenderer $api,
        private readonly IssueApiPresenter $presenter,
        private readonly IssuesExtension $helpers,
    ) {
    }

    /** Journal.visible: the issue is visible and private notes are visible by the user */
    private function findJournal(int $id): Journal
    {
        $journal = $this->em->find(Journal::class, $id);
        if (null === $journal || 'Issue' !== $journal->journalizedType) {
            throw $this->createNotFoundException();
        }
        $user = $this->user();
        if (!$this->issues->isVisible($journal->issue, $user)) {
            throw $this->createNotFoundException();
        }
        if ($journal->privateNotes && !$this->issues->canViewPrivateNotes($journal->issue, $user) && $journal->user->id !== $user->id) {
            throw $this->createNotFoundException();
        }

        return $journal;
    }

    #[Route('/journals/{id}/edit', name: 'journal_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $journal = $this->findJournal($id);
        $this->authorizeNotes($journal);
        if (!$this->issues->isJournalEditable($journal)) {
            $this->deny();
        }
        $form = $this->renderView('journals/_notes_form.html.twig', [
            'journal' => $journal,
            'can_set_private' => $this->auth->allowedTo('set_notes_private', $journal->issue->project),
        ]);
        $jid = $journal->id;
        $js = <<<JS
            $("#journal-{$jid}-notes").hide();
            if ($("form#journal-{$jid}-form").length > 0) {
              $("#journal-{$jid}-form").show();
            } else {
              $("#journal-{$jid}-notes").after(%s);
            }
            (function() {
              var textarea = $("#journal-{$jid}-form .wiki-edit");
              if (textarea.length > 0) {
                textarea.focus();
                var l = textarea.val().length;
                textarea.get(0).setSelectionRange(l, l);
              }
            })();
            JS;

        return $this->js(\sprintf($js, json_encode($form, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE)));
    }

    #[Route('/journals/{id}.{_format}', name: 'journal_update', requirements: ['id' => '\d+', '_format' => 'html|json|xml|js'], defaults: ['_format' => 'html'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id, string $_format = 'html'): Response
    {
        $journal = $this->findJournal($id);
        $this->authorizeNotes($journal);
        if (!$this->issues->isJournalEditable($journal)) {
            $this->deny();
        }
        $attrs = $this->isApi($request) ? (array) (ApiRenderer::payload($request)['journal'] ?? []) : array_merge($request->query->all('journal'), $request->request->all('journal'));
        if (\array_key_exists('notes', $attrs)) {
            $journal->notes = preg_replace('/\r\n?|\n/', "\r\n", (string) $attrs['notes']);
        }
        if (\array_key_exists('private_notes', $attrs) && $this->auth->allowedTo('set_notes_private', $journal->issue->project)) {
            $journal->privateNotes = \in_array($attrs['private_notes'], [true, 1, '1', 'true'], true);
        }
        $journal->updatedBy = $this->user();
        $journal->updatedOn = new \DateTimeImmutable();
        $issue = $journal->issue;
        // Redmine 7: a journal whose notes are emptied is kept (not destroyed), even without details
        $removed = false;
        if (!$journal->hasNotes() && $journal->details->count() > 0) {
            $journal->privateNotes = false;
        }
        $this->em->flush();
        $this->callHook('controller_journals_edit_post', ['journal' => $journal, 'params' => $request->request->all(), 'project' => $issue->project]);

        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        if ('js' === $_format || $request->isXmlHttpRequest()) {
            $jid = $id;
            if ($removed || (!$journal->hasNotes() && 0 === $journal->details->count())) {
                return $this->js("$(\"#change-{$jid}\").remove();");
            }
            $this->presenter->visibleJournals($issue); // sets indices
            $vars = ['journal' => $journal, 'issue' => $issue, 'reply_links' => $this->issues->isNotesAddable($issue)];
            $enc = fn (string $tpl) => json_encode($this->renderView($tpl, $vars), \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE);
            $js = '$("#change-'.$jid.'").attr("class", '.json_encode($this->helpers->journalCssClasses($journal)).');'
                .'$("#change-'.$jid.' .journal-actions").html('.$enc('journals/_actions.html.twig').');'
                .'$("#journal-'.$jid.'-private_notes").replaceWith('.$enc('journals/_private_notes_indicator.html.twig').');'
                .'$("#journal-'.$jid.'-notes").replaceWith('.$enc('journals/_notes.html.twig').');'
                .'$("#journal-'.$jid.'-notes").show();'
                .'$("#journal-'.$jid.'-form").remove();'
                .'var journal_header = $("#change-'.$jid.'>div.note>h4.journal-header>.journal-info");'
                .'var journal_updated_info = journal_header.find("span.update-info");'
                .'if (journal_updated_info.length > 0) { journal_updated_info.replaceWith('.$enc('journals/_update_info.html.twig').'); } else { journal_header.append('.$enc('journals/_update_info.html.twig').'); }'
                .'if (typeof setupHoverTooltips === "function") { setupHoverTooltips(); }';

            return $this->js($js);
        }

        return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
    }

    #[Route('/journals/{id}/diff', name: 'journal_diff', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function diff(Request $request, int $id): Response
    {
        $journal = $this->findJournal($id);
        $issue = $journal->issue;
        $this->authorize('view_issues', $issue->project);
        $detail = null;
        $detailId = $request->query->get('detail_id');
        foreach ($journal->details as $d) {
            if (null !== $detailId && '' !== $detailId ? $d->id === (int) $detailId : ('attr' === $d->property && 'description' === $d->propKey)) {
                $detail = $d;
                break;
            }
        }
        if (null === $detail) {
            throw $this->createNotFoundException();
        }
        if ('cf' === $detail->property && !\in_array($detail, $this->helpers->visibleDetails($journal), true)) {
            $this->deny();
        }

        return $this->render('journals/diff.html.twig', [
            'journal' => $journal,
            'issue' => $issue,
            'project' => $issue->project,
            'diff_html' => JournalDiff::toHtml((string) $detail->value, (string) $detail->oldValue),
            'main_menu_selected' => 'issues',
            'page_title' => $issue->tracker->name.' #'.$issue->id.': '.$issue->subject,
            'body_css' => 'controller-journals action-diff',
        ]);
    }

    /** Quote an issue description or a journal into the notes field (JournalsController#new) */
    #[Route('/issues/{id}/quoted', name: 'issue_quoted', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function quote(Request $request, int $id): Response
    {
        $issue = $this->em->find(Issue::class, $id);
        if (null === $issue) {
            throw $this->createNotFoundException();
        }
        if (!$this->issues->isVisible($issue)) {
            $this->deny();
        }
        if (!$this->issues->isNotesAddable($issue) && !$this->auth->allowedTo('add_issue_notes', $issue->project)) {
            $this->deny();
        }
        $json = json_decode((string) $request->getContent(), true);
        $partial = \is_array($json) ? ($json['quote'] ?? null) : $request->request->get('quote');
        $lang = $this->settings->string('default_language') ?: 'en';
        $journal = null;
        $journalId = $request->query->get('journal_id') ?? $request->request->get('journal_id');
        if (null !== $journalId && '' !== $journalId) {
            $journal = $this->findJournal((int) $journalId);
            if ($journal->issue->id !== $issue->id) {
                throw $this->createNotFoundException();
            }
            $indice = (string) ($request->query->get('journal_indice') ?? $request->request->get('journal_indice', ''));
            $header = $this->i18n->l('text_user_wrote_in', ['value' => $journal->user->getName(), 'link' => '#note-'.$indice], $lang)."\n> ";
            $text = $journal->notes;
        } else {
            $header = $this->i18n->l('text_user_wrote', ['value' => $issue->author->getName()], $lang)."\n> ";
            $text = $issue->description;
        }
        $content = self::buildQuote($header, (string) $text, \is_string($partial) ? $partial : null);
        $js = "$('#update').show();\nshowAndScrollTo(\"add_notes\");\n"
            ."var notes = $('#issue_notes').val();\nif (notes > \"\") { notes = notes + \"\\n\\n\"}\n"
            ."$('#issue_notes').blur().focus().val(notes + ".json_encode($content, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).");\n";
        if ($journal && $journal->privateNotes) {
            $js .= "$('#issue_private_notes').prop('checked', true);\n";
        }

        return $this->js($js);
    }

    public static function buildQuote(string $header, string $text, ?string $partial = null): string
    {
        $quote = null !== $partial && '' !== trim($partial) ? $partial : (string) preg_replace('#<pre>(.*?)</pre>#s', '[...]', trim($text));

        return $header.preg_replace('/(\r?\n|\r\n?)/', "\n> ", $quote)."\n\n";
    }

    /** Atom feed of the issue changes (/issues/changes) */
    #[Route('/issues/changes.{_format}', name: 'issue_changes', requirements: ['_format' => 'atom'], defaults: ['_format' => 'atom'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        $project = null;
        if ($pid = $request->query->get('project_id')) {
            $project = $this->findProject((string) $pid);
        }
        $cond = $this->issues->visibleCondition(null, 'i');
        $qb = $this->em->createQueryBuilder()->select('j')->from(Journal::class, 'j')->join('j.issue', 'i')->where($cond)
            ->orderBy('j.createdOn', 'DESC')->setMaxResults(25);
        if ($project) {
            $qb->andWhere('i.project = :p')->setParameter('p', $project->id);
        }
        $canPrivate = fn (Journal $j) => !$j->privateNotes || $this->issues->canViewPrivateNotes($j->issue) || $j->user->id === $this->user()->id;
        $journals = array_values(array_filter($qb->getQuery()->getResult(), $canPrivate));

        return $this->render('journals/index.atom.twig', [
            'journals' => $journals,
            'title' => ($project ? $project->name : $this->settings->appTitle()).': '.$this->l('label_changes_details'),
        ], new Response('', 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']));
    }

    private function authorizeNotes(Journal $journal): void
    {
        $p = $journal->issue->project;
        if (!$this->auth->allowedTo('edit_issue_notes', $p) && !$this->auth->allowedTo('edit_own_issue_notes', $p)) {
            $this->deny();
        }
    }

    private function js(string $js): Response
    {
        return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }
}
