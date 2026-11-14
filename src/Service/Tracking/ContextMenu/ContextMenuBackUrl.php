<?php

declare(strict_types=1);

namespace App\Service\Tracking\ContextMenu;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Where the links of a context menu come back to: the back_url parameter or the page the menu was opened on.
 */
final class ContextMenuBackUrl
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function of(Request $request): ?string
    {
        $params = array_replace($request->query->all(), $request->request->all());
        $url = isset($params['back_url']) && \is_string($params['back_url']) ? $params['back_url'] : null;
        if (null === $url && ($ref = $request->headers->get('referer'))) {
            $url = rawurldecode($ref);
        }
        if (null === $url || '' === $url) {
            return null;
        }
        // keep local urls only (Redmine#validate_back_url)
        $parts = parse_url($url);
        if (isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return null;
        }
        if (isset($parts['host'])) {
            $url = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : null;
    }

    /** Whether the menu was opened on a list of issues (list, Gantt chart, calendar): only those offer "Delete" */
    public function isIssueList(?string $back): bool
    {
        if (null === $back) {
            return false;
        }
        $path = (string) parse_url($back, \PHP_URL_PATH);
        try {
            $context = $this->router->getContext();
            $method = $context->getMethod();
            $context->setMethod('GET');
            try {
                $match = $this->router->match($path);
            } finally {
                $context->setMethod($method);
            }
        } catch (RoutingException) {
            return false;
        }

        return \in_array($match['_route'] ?? null, ['issues_index', 'project_issues', 'gantt', 'project_gantt', 'calendar', 'project_calendar'], true);
    }
}
