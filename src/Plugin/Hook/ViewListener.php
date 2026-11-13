<?php

declare(strict_types=1);

namespace App\Plugin\Hook;

use Symfony\Contracts\Service\Attribute\Required;
use Twig\Environment;

/**
 * Base class of view hook listeners (Redmine::Hook::ViewListener).
 *
 * render_on equivalent: declare the templates rendered for hooks; the hook context is passed to the
 * template as variables.
 *
 *     final class HelloHooks extends ViewListener
 *     {
 *         protected const array RENDER_ON = [
 *             'view_issues_show_details_bottom' => '@HelloPlugin/hooks/_issue_details.html.twig',
 *         ];
 *     }
 *
 * Methods named after a hook can also be defined (they take precedence over RENDER_ON).
 */
abstract class ViewListener implements HookListenerInterface
{
    /** @var array<string, string|list<string>> hook => template(s) */
    protected const array RENDER_ON = [];

    protected Environment $twig;

    #[Required]
    public function setTwig(Environment $twig): void
    {
        $this->twig = $twig;
    }

    public function rendersOn(string $hook): bool
    {
        return isset(static::RENDER_ON[$hook]);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<string>
     */
    public function renderOn(string $hook, array $context): array
    {
        $out = [];
        foreach ((array) (static::RENDER_ON[$hook] ?? []) as $template) {
            $out[] = $this->render($template, $context);
        }

        return $out;
    }

    /** @param array<string, mixed> $context */
    protected function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
