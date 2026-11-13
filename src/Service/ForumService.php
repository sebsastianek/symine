<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Board;
use App\Entity\Message;
use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of Redmine's Board and Message models behaviour: validation, safe attributes, counters
 * (topics_count, messages_count, last_message_id, replies_count, last_reply_id), positions
 * (acts_as_positioned scoped by project_id/parent_id), tree destroy, watchers.
 */
final class ForumService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly Localizer $i18n,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
    ) {
    }

    // --- Boards ------------------------------------------------------------------

    /** @return list<Board> */
    public function boards(Project $project): array
    {
        return $this->em->getRepository(Board::class)->findBy(['project' => $project], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Board.board_tree: list of [board, level] sorted by position.
     *
     * @param list<Board> $boards
     *
     * @return list<array{0: Board, 1: int}>
     */
    public function boardTree(array $boards, ?Board $parent = null, int $level = 0, array &$visited = []): array
    {
        $children = array_values(array_filter($boards, static fn (Board $b) => ($b->parent?->id) === ($parent?->id)));
        usort($children, static fn (Board $a, Board $b) => [(int) $a->position, $a->id] <=> [(int) $b->position, $b->id]);
        $tree = [];
        foreach ($children as $b) {
            if (isset($visited[$b->id])) {
                continue; // guards against cycles
            }
            $visited[$b->id] = true;
            $tree[] = [$b, $level];
            foreach ($this->boardTree($boards, $b, $level + 1, $visited) as $node) {
                $tree[] = $node;
            }
        }

        return $tree;
    }

    /** boards_options_for_select: list of [label (html), id] */
    public function boardOptions(array $boards): array
    {
        $out = [];
        foreach ($this->boardTree($boards) as [$board, $level]) {
            $label = ($level > 0 ? str_repeat('&nbsp;', 2 * $level).'&#187; ' : '').htmlspecialchars($board->name);
            $out[] = ['label' => $label, 'id' => $board->id];
        }

        return $out;
    }

    /** @return list<Board> ancestors (root first) */
    public function ancestors(Board $board): array
    {
        $out = [];
        $seen = [];
        $p = $board->parent;
        while (null !== $p && !isset($seen[$p->id])) {
            $seen[$p->id] = true;
            array_unshift($out, $p);
            $p = $p->parent;
        }

        return $out;
    }

    /** @return list<Board> project.boards - self_and_descendants */
    public function validParents(Board $board): array
    {
        $boards = $this->boards($board->project);
        if (null === $board->id) {
            return $boards;
        }
        $excluded = [$board->id => true];
        foreach ($this->boardTree($boards, $board) as [$b]) {
            $excluded[$b->id] = true;
        }

        return array_values(array_filter($boards, static fn (Board $b) => !isset($excluded[$b->id])));
    }

    /** Board#safe_attributes= ('name', 'description', 'parent_id', 'position') */
    public function assignBoard(Board $board, array $attrs): void
    {
        if (\array_key_exists('name', $attrs)) {
            $board->name = trim((string) $attrs['name']);
        }
        if (\array_key_exists('description', $attrs)) {
            $board->description = (string) $attrs['description'];
        }
        if (\array_key_exists('parent_id', $attrs)) {
            $pid = (int) $attrs['parent_id'];
            $board->parent = $pid > 0 ? $this->em->find(Board::class, $pid) : null;
        }
        if (\array_key_exists('position', $attrs) && '' !== (string) $attrs['position']) {
            $board->position = (int) $attrs['position'];
        }
    }

    /** @return list<string> */
    public function validateBoard(Board $board, ?int $previousParentId = null): array
    {
        $errors = [];
        if ('' === trim($board->name)) {
            $errors[] = $this->i18n->l('field_name').' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($board->name) > 30) {
            $errors[] = $this->i18n->l('field_name').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 30]);
        }
        if ('' === trim((string) $board->description)) {
            $errors[] = $this->i18n->l('field_description').' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen((string) $board->description) > 255) {
            $errors[] = $this->i18n->l('field_description').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if (null !== $board->parent && $board->parent->id !== $previousParentId) {
            $valid = array_map(static fn (Board $b) => $b->id, $this->validParents($board));
            if (!\in_array($board->parent->id, $valid, true)) {
                $errors[] = $this->i18n->l('field_board_parent').' '.$this->i18n->l('activerecord.errors.messages.invalid');
            }
        }

        return $errors;
    }

    /**
     * Saves a board with acts_as_positioned semantics.
     *
     * @param array{position: ?int, parent_id: ?int}|null $before state before the change (null for a new board)
     */
    public function saveBoard(Board $board, ?array $before = null): void
    {
        $conn = $this->em->getConnection();
        $isNew = null === $board->id;
        $scopeChanged = !$isNew && ($before['parent_id'] ?? null) !== ($board->parent?->id);
        if (null === $board->position) {
            $max = (int) $conn->fetchOne('SELECT MAX(position) FROM boards WHERE project_id = ? AND '.$this->parentCond($board->parent?->id), $this->scopeParams($board->project->id, $board->parent?->id));
            $board->position = $max + ($isNew ? 1 : 0);
        }
        $this->em->persist($board);
        $this->em->flush();
        $id = (int) $board->id;
        if ($isNew) {
            $this->insertPosition($board);
        } elseif ($scopeChanged) {
            if (null !== $before['position']) {
                $conn->executeStatement('UPDATE boards SET position = position - 1 WHERE project_id = ? AND '.$this->parentCond($before['parent_id']).' AND position >= ? AND id <> ?', [...$this->scopeParams($board->project->id, $before['parent_id']), $before['position'], $id]);
            }
            $this->insertPosition($board);
        } elseif (($before['position'] ?? null) !== $board->position) {
            if (null === $before['position']) {
                $this->insertPosition($board);
            } else {
                $old = (int) $before['position'];
                $new = (int) $board->position;
                $offset = $old <=> $new;
                [$min, $max] = [min($old, $new), max($old, $new)];
                $r = $conn->executeStatement('UPDATE boards SET position = position + ('.$offset.') WHERE project_id = ? AND '.$this->parentCond($board->parent?->id).' AND id <> ? AND position BETWEEN ? AND ?', [...$this->scopeParams($board->project->id, $board->parent?->id), $id, $min, $max]);
                if ($r !== $max - $min) {
                    $ids = $conn->fetchFirstColumn('SELECT id FROM boards WHERE project_id = ? AND '.$this->parentCond($board->parent?->id).' ORDER BY position, id', $this->scopeParams($board->project->id, $board->parent?->id));
                    foreach ($ids as $p => $bid) {
                        $conn->executeStatement('UPDATE boards SET position = ? WHERE id = ?', [$p + 1, $bid]);
                    }
                }
            }
        }
        $this->refreshBoards($board->project);
    }

    private function insertPosition(Board $board): void
    {
        $this->em->getConnection()->executeStatement('UPDATE boards SET position = position + 1 WHERE project_id = ? AND '.$this->parentCond($board->parent?->id).' AND position >= ? AND id <> ?', [...$this->scopeParams($board->project->id, $board->parent?->id), $board->position, $board->id]);
    }

    private function parentCond(?int $parentId): string
    {
        return null === $parentId ? 'parent_id IS NULL' : 'parent_id = ?';
    }

    private function scopeParams(?int $projectId, ?int $parentId): array
    {
        return null === $parentId ? [$projectId] : [$projectId, $parentId];
    }

    private function refreshBoards(Project $project): void
    {
        foreach ($this->boards($project) as $b) {
            $this->em->refresh($b);
        }
    }

    /** Board#destroy: messages destroyed, children boards nullified, position removed, watchers removed */
    public function destroyBoard(Board $board): void
    {
        $conn = $this->em->getConnection();
        $id = (int) $board->id;
        $messageIds = $conn->fetchFirstColumn('SELECT id FROM messages WHERE board_id = ?', [$id]);
        foreach ($messageIds as $mid) {
            $this->attachments->deleteForContainer('Message', (int) $mid);
            $this->watchers->removeAll('Message', (int) $mid);
            $conn->executeStatement("DELETE FROM reactions WHERE reactable_type = 'Message' AND reactable_id = ?", [$mid]);
        }
        $this->em->flush();
        $conn->executeStatement('UPDATE boards SET last_message_id = NULL WHERE id = ?', [$id]);
        $conn->executeStatement('DELETE FROM messages WHERE board_id = ?', [$id]);
        $conn->executeStatement('UPDATE boards SET parent_id = NULL WHERE parent_id = ?', [$id]);
        $this->watchers->removeAll('Board', $id);
        $project = $board->project;
        $position = $board->position;
        $parentId = $board->parent?->id;
        $this->em->clear();
        $conn->executeStatement('DELETE FROM boards WHERE id = ?', [$id]);
        if (null !== $position) {
            $conn->executeStatement('UPDATE boards SET position = position - 1 WHERE project_id = ? AND '.$this->parentCond($parentId).' AND position >= ? AND id <> ?', [...$this->scopeParams($project->id, $parentId), $position, $id]);
        }
    }

    /** Board.reset_counters! */
    public function resetBoardCounters(int $boardId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE boards SET topics_count = (SELECT COUNT(*) FROM messages WHERE board_id = :id AND parent_id IS NULL),'
            .' messages_count = (SELECT COUNT(*) FROM messages WHERE board_id = :id),'
            .' last_message_id = (SELECT MAX(id) FROM messages WHERE board_id = :id) WHERE id = :id',
            ['id' => $boardId]
        );
    }

    // --- Messages ----------------------------------------------------------------

    public function root(Message $m): Message
    {
        $seen = [];
        while (null !== $m->parent && !isset($seen[spl_object_id($m)])) {
            $seen[spl_object_id($m)] = true;
            $m = $m->parent;
        }

        return $m;
    }

    public function editableBy(Message $m, User $user): bool
    {
        $project = $m->board->project;

        return $user->isLogged() && ($this->auth->allowedTo('edit_messages', $project, $user)
            || ($m->author?->id === $user->id && $this->auth->allowedTo('edit_own_messages', $project, $user)));
    }

    public function destroyableBy(Message $m, User $user): bool
    {
        $project = $m->board->project;

        return $user->isLogged() && ($this->auth->allowedTo('delete_messages', $project, $user)
            || ($m->author?->id === $user->id && $this->auth->allowedTo('delete_own_messages', $project, $user)));
    }

    /** Message#safe_attribute? */
    public function safeAttribute(Message $m, string $attr, User $user): bool
    {
        if (\in_array($attr, ['subject', 'content'], true)) {
            return true;
        }

        return \in_array($attr, ['locked', 'sticky', 'board_id'], true) && $this->auth->allowedTo('edit_messages', $m->board->project, $user);
    }

    /** Message#safe_attributes= */
    public function assignMessage(Message $m, array $attrs, User $user): void
    {
        if (\array_key_exists('subject', $attrs)) {
            $m->subject = (string) $attrs['subject'];
        }
        if (\array_key_exists('content', $attrs)) {
            $m->content = (string) $attrs['content'];
        }
        if ($this->safeAttribute($m, 'locked', $user)) {
            if (\array_key_exists('locked', $attrs)) {
                $m->locked = \in_array((string) $attrs['locked'], ['1', 'true'], true);
            }
            if (\array_key_exists('sticky', $attrs)) {
                $m->sticky = \in_array((string) $attrs['sticky'], ['1', 'true'], true) ? 1 : 0;
            }
            if (\array_key_exists('board_id', $attrs) && '' !== (string) $attrs['board_id']) {
                $board = $this->em->find(Board::class, (int) $attrs['board_id']);
                // only boards of the same project are listed in the form
                if (null !== $board && $board->project->id === $m->board->project->id) {
                    $m->board = $board;
                }
            }
        }
    }

    /** @return list<string> */
    public function validateMessage(Message $m, bool $isNew): array
    {
        $errors = [];
        if ('' === trim($m->subject)) {
            $errors[] = $this->i18n->l('field_subject').' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($m->subject) > 255) {
            $errors[] = $this->i18n->l('field_subject').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if ('' === trim((string) $m->content)) {
            $errors[] = $this->i18n->l('field_content').' '.$this->i18n->l('activerecord.errors.messages.blank');
        }
        if ($isNew && null !== $m->parent && $this->root($m)->locked) {
            $errors[] = 'Topic is locked';
        }

        return $errors;
    }

    /** Creates a topic or a reply (after_create: add_author_as_watcher, reset_counters!, counter cache) */
    public function createMessage(Message $m): void
    {
        $now = new \DateTimeImmutable();
        $m->createdOn = $now;
        $m->updatedOn = $now;
        $this->em->persist($m);
        $this->em->flush();
        $conn = $this->em->getConnection();
        if (null !== $m->parent) {
            // acts_as_tree counter_cache => :replies_count
            $conn->executeStatement('UPDATE messages SET replies_count = COALESCE(replies_count, 0) + 1 WHERE id = ?', [$m->parent->id]);
        }
        $root = $this->root($m);
        if (null !== $m->author) {
            $this->watchers->add('Message', (int) $root->id, $m->author);
        }
        $this->resetMessageCounters($m);
        $this->em->flush();
        $this->refreshMessage($m);
    }

    private function resetMessageCounters(Message $m, ?int $parentId = null, ?int $boardId = null): void
    {
        $conn = $this->em->getConnection();
        $parentId ??= $m->parent?->id;
        if (null !== $parentId) {
            $conn->executeStatement('UPDATE messages SET last_reply_id = (SELECT MAX(id) FROM messages WHERE parent_id = :p) WHERE id = :p', ['p' => $parentId]);
        }
        $this->resetBoardCounters($boardId ?? (int) $m->board->id);
    }

    /** Message#save on update (after_update :update_messages_board) */
    public function updateMessage(Message $m, int $previousBoardId): void
    {
        $m->updatedOn = new \DateTimeImmutable();
        $this->em->flush();
        if ($previousBoardId !== $m->board->id) {
            $root = $this->root($m);
            $this->em->getConnection()->executeStatement('UPDATE messages SET board_id = ? WHERE id = ? OR parent_id = ?', [$m->board->id, $root->id, $root->id]);
            $this->resetBoardCounters($previousBoardId);
            $this->resetBoardCounters((int) $m->board->id);
        }
        $this->refreshMessage($m);
    }

    /** Message#destroy: children destroyed first (acts_as_tree), attachments, watchers, counters */
    public function destroyMessage(Message $m): void
    {
        $conn = $this->em->getConnection();
        $id = (int) $m->id;
        foreach ($conn->fetchFirstColumn('SELECT id FROM messages WHERE parent_id = ? ORDER BY id', [$id]) as $childId) {
            $child = $this->em->find(Message::class, (int) $childId);
            if (null !== $child) {
                $this->destroyMessage($child);
            }
        }
        $parentId = $m->parent?->id;
        $boardId = (int) $m->board->id;
        $this->attachments->deleteForContainer('Message', $id);
        $this->watchers->removeAll('Message', $id);
        $this->em->flush();
        $conn->executeStatement("DELETE FROM reactions WHERE reactable_type = 'Message' AND reactable_id = ?", [$id]);
        // references to the message being deleted
        $conn->executeStatement('UPDATE boards SET last_message_id = NULL WHERE last_message_id = ?', [$id]);
        $conn->executeStatement('UPDATE messages SET last_reply_id = NULL WHERE last_reply_id = ?', [$id]);
        $this->em->detach($m);
        $conn->executeStatement('DELETE FROM messages WHERE id = ?', [$id]);
        if (null !== $parentId) {
            $conn->executeStatement('UPDATE messages SET replies_count = CASE WHEN COALESCE(replies_count, 0) > 0 THEN replies_count - 1 ELSE 0 END WHERE id = ?', [$parentId]);
        }
        $this->resetMessageCounters($m, $parentId, $boardId);
        // reload in-memory objects that may still reference the deleted message
        if (null !== $m->parent && $this->em->contains($m->parent)) {
            $this->em->refresh($m->parent);
        }
        if ($this->em->contains($m->board)) {
            $this->em->refresh($m->board);
        }
    }

    private function refreshMessage(Message $m): void
    {
        $this->em->refresh($m);
        if (null !== $m->parent) {
            $this->em->refresh($m->parent);
        }
        $this->em->refresh($m->board);
    }

    /** Redmine::QuoteReply::Builder (quote_root_message / quote_message) */
    public function quote(Message $m, ?string $partial, string $defaultLanguage): string
    {
        $author = $m->author?->getName() ?? '';
        $header = null === $m->parent
            ? $this->i18n->l('text_user_wrote', ['value' => $author], $defaultLanguage)
            : $this->i18n->l('text_user_wrote_in', ['value' => $author, 'link' => 'message#'.$m->id], $defaultLanguage);
        $text = null !== $partial && '' !== trim($partial) ? $partial : (preg_replace('#<pre>(.*?)</pre>#s', '[...]', trim((string) $m->content)) ?? '');

        return $header."\n> ".preg_replace('/(\r?\n|\r\n?)/', "\n> ", $text)."\n\n";
    }
}
