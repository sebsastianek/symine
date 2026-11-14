<?php

declare(strict_types=1);

namespace App\Service\Tracking\ContextMenu;

use App\I18n\Localizer;
use App\Twig\AppExtension;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The links of a context menu, written like ContextMenusHelper#context_menu_link writes them.
 */
final class ContextMenuLinks
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly AppExtension $app,
        private readonly Localizer $i18n,
    ) {
    }

    public function icon(string $name, string $label): string
    {
        return $this->app->spriteIcon($name, $label);
    }

    public function hasRoute(string $name): bool
    {
        return null !== $this->router->getRouteCollection()->get($name);
    }

    /** URL with Rails-style (sorted, "a[]=") query string; '#' when the route does not exist */
    public function route(string $name, array $params = [], array $query = []): string
    {
        try {
            $url = $this->router->generate($name, $params);
        } catch (RoutingException) {
            return '#';
        }
        $qs = QueryString::toQuery($query);

        return $url.('' !== $qs ? (str_contains($url, '?') ? '&' : '?').$qs : '');
    }

    public function copyLink(string $url): string
    {
        return '<a class="icon icon-copy-link" data-clipboard-text="'.htmlspecialchars($url).'" data-controller="clipboard" data-action="clipboard#copyText" href="#">'.$this->icon('copy-link', $this->i18n->l('button_copy_link')).'</a>';
    }

    /**
     * ContextMenusHelper#context_menu_link. $label is HTML.
     *
     * @param array{class?: string, selected?: bool, disabled?: bool, method?: string, confirm?: string, remote?: bool} $options
     */
    public function link(string $label, string $url, array $options = []): string
    {
        $classes = [$options['class'] ?? ''];
        $disabled = (bool) ($options['disabled'] ?? false);
        if ($options['selected'] ?? false) {
            $classes[] = 'icon disabled';
            $disabled = true;
            $label = $this->app->spriteIcon('checked', html_entity_decode($label, \ENT_QUOTES));
        }
        $method = $options['method'] ?? null;
        $confirm = $options['confirm'] ?? null;
        $onclick = null;
        if ($disabled || '#' === $url) {
            $method = null;
            $confirm = null;
            $onclick = 'return false;';
            $classes[] = 'disabled';
            $url = '#';
        }
        $tokens = [];
        foreach ($classes as $c) {
            foreach (preg_split('/\s+/', trim($c)) ?: [] as $t) {
                if ('' !== $t && !\in_array($t, $tokens, true)) {
                    $tokens[] = $t;
                }
            }
        }
        $a = '<a';
        if (null !== $confirm) {
            $a .= ' data-confirm="'.htmlspecialchars($confirm).'"';
        }
        // Rails keeps the hash insertion order: a given :class comes before the added :onclick
        $classFirst = isset($options['class']) && '' !== $options['class'];
        if ($classFirst) {
            $a .= ' class="'.implode(' ', $tokens).'"';
        }
        if (null !== $onclick) {
            $a .= ' onclick="'.$onclick.'"';
        }
        if (!$classFirst) {
            $a .= ' class="'.implode(' ', $tokens).'"';
        }
        if ($options['remote'] ?? false) {
            $a .= ' data-remote="true"';
        }
        if (null !== $method) {
            $a .= ' rel="nofollow" data-method="'.$method.'"';
        }

        return $a.' href="'.htmlspecialchars($url).'">'.$label.'</a>';
    }
}
