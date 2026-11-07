<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\AnonymousUser;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Menu\MenuBuilder;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\Settings;
use App\Service\TextFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Port of the most used Redmine view helpers (ApplicationHelper, IconsHelper, AvatarsHelper, ...). */
final class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public const string CSRF_ID = 'symine';

    public function __construct(
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly UrlGeneratorInterface $urls,
        private readonly MenuBuilder $menus,
        private readonly TextFormatter $formatter,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function getGlobals(): array
    {
        return ['settings' => $this->settings, 'i18n' => $this->i18n, 'csrf_param' => '_token'];
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('l', $this->i18n->l(...)),
            new TwigFunction('current_user', $this->currentUser->get(...)),
            new TwigFunction('setting', $this->settings->get(...)),
            new TwigFunction('allowed_to', fn (string $perm, ?Project $project = null, bool $global = false) => $this->auth->allowedTo($perm, $project, null, $global)),
            new TwigFunction('allowed_to_globally', fn (string $perm) => $this->auth->allowedToGlobally($perm)),
            new TwigFunction('csrf_token_value', fn () => $this->csrf->getToken(self::CSRF_ID)->getValue()),
            new TwigFunction('csrf_field', fn () => '<input type="hidden" name="_token" value="'.htmlspecialchars($this->csrf->getToken(self::CSRF_ID)->getValue()).'" autocomplete="off">', $safe),
            new TwigFunction('sprite_icon', $this->spriteIcon(...), $safe),
            new TwigFunction('avatar', $this->avatar(...), $safe),
            new TwigFunction('link_to_user', $this->linkToUser(...), $safe),
            new TwigFunction('link_to_principal', $this->linkToUser(...), $safe),
            new TwigFunction('link_to_issue', $this->linkToIssue(...), $safe),
            new TwigFunction('link_to_project', $this->linkToProject(...), $safe),
            new TwigFunction('link_to_version', $this->linkToVersion(...), $safe),
            new TwigFunction('issue_css_classes', $this->issueCssClasses(...)),
            new TwigFunction('priority_css_classes', $this->priorityCssClasses(...)),
            new TwigFunction('textilizable', $this->formatter->format(...), $safe),
            new TwigFunction('top_menu', $this->menus->topMenu(...)),
            new TwigFunction('account_menu', $this->menus->accountMenu(...)),
            new TwigFunction('application_menu', $this->menus->applicationMenu(...)),
            new TwigFunction('project_menu', $this->menus->projectMenu(...)),
            new TwigFunction('admin_menu', $this->menus->adminMenu(...)),
            new TwigFunction('format_date', $this->i18n->formatDate(...)),
            new TwigFunction('format_time', $this->i18n->formatTime(...)),
            new TwigFunction('format_hours', $this->i18n->formatHours(...)),
            new TwigFunction('time_tag', $this->timeTag(...), $safe),
            new TwigFunction('distance_of_time', $this->i18n->distanceOfTimeInWords(...)),
            new TwigFunction('authoring', $this->authoring(...), $safe),
            new TwigFunction('progress_bar', $this->progressBar(...), $safe),
            new TwigFunction('number_to_human_size', $this->humanSize(...)),
            new TwigFunction('checked_if', static fn (bool $c) => $c ? 'checked' : '', $safe),
            new TwigFunction('selected_if', static fn (bool $c) => $c ? 'selected' : '', $safe),
            new TwigFunction('path_exists', fn (string $route) => $this->routeExists($route)),
            new TwigFunction('today', fn () => $this->i18n->today()),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_date', $this->i18n->formatDate(...)),
            new TwigFilter('format_time', $this->i18n->formatTime(...)),
            new TwigFilter('format_hours', $this->i18n->formatHours(...)),
            new TwigFilter('truncate', static fn (?string $s, int $len = 60) => null === $s ? '' : (mb_strlen($s) > $len ? mb_substr($s, 0, $len - 3).'...' : $s)),
        ];
    }

    private function routeExists(string $route): bool
    {
        try {
            $this->urls->generate($route, []);

            return true;
        } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException) {
            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    public function spriteIcon(string $name, ?string $label = null, int $size = 18, bool $iconOnly = false, ?string $cssClass = null, bool $filled = false): string
    {
        $classes = 's'.$size.' icon-svg'.($filled ? ' icon-svg-filled' : '').($cssClass ? ' '.$cssClass : '');
        $svg = '<svg class="'.$classes.'" aria-hidden="true"><use href="/assets/icons.svg#icon--'.htmlspecialchars($name).'"></use></svg>';
        if (null !== $label) {
            $svg .= '<span class="icon-label'.($iconOnly ? ' hidden' : '').'">'.htmlspecialchars($label).'</span>';
        }

        return $svg;
    }

    public function avatar(?Principal $user, int $size = 24, ?string $class = null): string
    {
        if (null === $user) {
            return '';
        }
        $cls = trim('avatar '.($class ?? '').' s'.$size);
        if ($user instanceof AnonymousUser) {
            return '<img class="'.$cls.' anonymous-avatar" src="/assets/anonymous.png" width="'.$size.'" height="'.$size.'" alt="">';
        }
        if ($user instanceof Group) {
            return '<img class="'.$cls.' group-avatar" src="/assets/group.svg" width="'.$size.'" height="'.$size.'" alt="">';
        }
        if ($user instanceof User && $this->settings->bool('gravatar_enabled') && $user->getMail()) {
            $hash = md5(strtolower(trim((string) $user->getMail())));
            $default = $this->settings->string('gravatar_default') ?: 'identicon';

            return '<img class="'.$cls.' gravatar" title="'.htmlspecialchars($user->getName()).'" src="https://www.gravatar.com/avatar/'.$hash.'?rating=PG&size='.($size * 2).'&default='.rawurlencode($default).'" width="'.$size.'" height="'.$size.'" alt="">';
        }
        $initials = mb_strtoupper(mb_substr($user->firstname, 0, 1).mb_substr($user->lastname, 0, 1));

        return '<span role="img" class="'.$cls.' avatar-color-'.((int) $user->id % 8).'" title="'.htmlspecialchars($user->getName()).'">'.htmlspecialchars($initials).'</span>';
    }

    public function linkToUser(?Principal $user, ?string $class = null): string
    {
        if (null === $user) {
            return '';
        }
        $name = htmlspecialchars($user->getName($this->settings->string('user_format') ?: null));
        if ($user instanceof AnonymousUser) {
            return $name;
        }
        if ($user instanceof Group) {
            $url = $this->routeExists('group_show') ? $this->urls->generate('group_show', ['id' => $user->id]) : null;
        } elseif ($user->isActive() || ($this->currentUser->get()->admin && $user instanceof User && $user->isLogged())) {
            $url = $this->routeExists('user_show') ? $this->urls->generate('user_show', ['id' => $user->id]) : null;
        } else {
            $url = null;
        }
        if (null === $url) {
            return $name;
        }
        $css = trim(($user instanceof Group ? 'group' : 'user').' '.($user->isActive() ? 'active' : ($user->isLocked() ? 'locked' : 'registered')).' '.($class ?? ''));

        return '<a class="'.$css.'" href="'.htmlspecialchars($url).'">'.$name.'</a>';
    }

    /** @param array{tracker?: bool, subject?: bool, truncate?: int, project?: bool} $options */
    public function linkToIssue(Issue $issue, array $options = []): string
    {
        $text = (false === ($options['tracker'] ?? true)) ? '#'.$issue->id : $issue->tracker->name.' #'.$issue->id;
        $title = null;
        $subject = null;
        if (false === ($options['subject'] ?? true)) {
            $title = mb_strimwidth($issue->subject, 0, 60, '...');
        } else {
            $subject = isset($options['truncate']) ? mb_strimwidth($issue->subject, 0, $options['truncate'], '...') : $issue->subject;
        }
        $url = $this->urls->generate('issue_show', ['id' => $issue->id]);
        $s = '<a href="'.htmlspecialchars($url).'" class="'.$this->issueCssClasses($issue).'"'.($title ? ' title="'.htmlspecialchars($title).'"' : '').'>'.htmlspecialchars($text).'</a>';
        if (null !== $subject) {
            $s .= htmlspecialchars(': '.$subject);
        }
        if ($options['project'] ?? false) {
            $s = htmlspecialchars($issue->project->name.' - ').$s;
        }

        return $s;
    }

    public function linkToProject(?Project $project, ?string $action = null): string
    {
        if (null === $project) {
            return '';
        }
        if ($project->isArchived()) {
            return htmlspecialchars($project->name);
        }
        $url = $this->urls->generate($action ?? 'project_show', ['id' => $project->identifier]);

        return '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($project->name).'</a>';
    }

    public function linkToVersion(?Version $version, ?Project $context = null): string
    {
        if (null === $version) {
            return '';
        }
        $name = htmlspecialchars($version->displayName($context));
        if (!$this->routeExists('version_show') || !$this->auth->allowedTo('view_issues', $version->project)) {
            return $name;
        }

        return '<a href="'.htmlspecialchars($this->urls->generate('version_show', ['id' => $version->id])).'">'.$name.'</a>';
    }

    public function issueCssClasses(Issue $issue): string
    {
        $user = $this->currentUser->get();
        $s = 'issue tracker-'.$issue->tracker->id.' status-'.$issue->status->id.' '.$this->priorityCssClasses($issue->priority);
        if ($issue->isClosed()) {
            $s .= ' closed';
        }
        if ($issue->isOverdue()) {
            $s .= ' overdue';
        }
        if (!$issue->isLeaf()) {
            $s .= ' parent';
        }
        if (null !== $issue->parent) {
            $s .= ' child';
        }
        if ($issue->isPrivate) {
            $s .= ' private';
        }
        if ($issue->isBehindSchedule()) {
            $s .= ' behind-schedule';
        }
        if ($user->isLogged()) {
            if ($issue->author->id === $user->id) {
                $s .= ' created-by-me';
            }
            if (null !== $issue->assignedTo && $user->isOrBelongsTo($issue->assignedTo)) {
                $s .= ' assigned-to-me';
            }
            if ($issue->assignedTo instanceof Group && $user->isOrBelongsTo($issue->assignedTo)) {
                $s .= ' assigned-to-my-group';
            }
        }

        return $s;
    }

    public function priorityCssClasses(IssuePriority $priority): string
    {
        return 'priority-'.$priority->id.($priority->positionName ? ' priority-'.$priority->positionName : '');
    }

    public function timeTag(?\DateTimeInterface $time): string
    {
        if (null === $time) {
            return '';
        }

        return '<span title="'.htmlspecialchars($this->i18n->formatTime($time)).'">'.htmlspecialchars($this->i18n->distanceOfTimeInWords($time)).'</span>';
    }

    /** "Added by John Smith 3 days ago." */
    public function authoring(?\DateTimeInterface $created, ?Principal $author, string $label = 'label_added_time_by'): string
    {
        return $this->i18n->l($label, ['author' => $this->linkToUser($author), 'age' => $this->timeTag($created)]);
    }

    /** @param list<float>|float $pcts */
    public function progressBar(array|float|int $pcts, ?string $legend = null, string $class = 'progress', ?string $titles = null): string
    {
        $pcts = \is_array($pcts) ? $pcts : [$pcts];
        $pcts = array_map(static fn ($p) => (int) round((float) $p), $pcts);
        $pcts[] = $pcts[0];
        $pcts[1] = ($pcts[1] ?? $pcts[0]) - $pcts[0];
        $closed = $pcts[0];
        $done = $pcts[1];
        $todo = 100 - $closed - $done;
        $html = '<table class="'.$class.' progress-'.$pcts[0].'"><tr>';
        if ($closed > 0) {
            $html .= '<td style="width: '.$closed.'%;" class="closed" title="'.htmlspecialchars((string) $titles).'"></td>';
        }
        if ($done > 0) {
            $html .= '<td style="width: '.$done.'%;" class="done"></td>';
        }
        if ($todo > 0) {
            $html .= '<td style="width: '.$todo.'%;" class="todo"></td>';
        }
        $html .= '</tr></table>';
        if (null !== $legend) {
            $html .= '<p class="percent">'.htmlspecialchars($legend).'</p>';
        }

        return $html;
    }

    public function humanSize(int|string|null $bytes): string
    {
        $bytes = (int) $bytes;
        $units = ['number.human.storage_units.units.byte', 'number.human.storage_units.units.kb', 'number.human.storage_units.units.mb', 'number.human.storage_units.units.gb', 'number.human.storage_units.units.tb'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 4) {
            $n /= 1024;
            ++$i;
        }
        $num = 0 === $i ? (string) $bytes : rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
        $unit = 0 === $i ? $this->i18n->l($units[0], ['count' => $bytes]) : $this->i18n->l($units[$i]);

        return $num.' '.$unit;
    }
}
