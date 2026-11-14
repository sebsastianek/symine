<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Plugin\Hook\Hooks;
use App\Security\AccessGuard;
use App\Security\ApiRequestMatcher;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\ProjectService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as BaseController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base controller with the equivalents of Redmine's ApplicationController helpers:
 * find_project, authorize, require_login, require_admin, render_403/404, l(), flash.
 * The access checks are those of App\Security\AccessGuard, which services use directly.
 */
abstract class AbstractController extends BaseController
{
    protected EntityManagerInterface $em;
    protected Authorization $auth;
    protected CurrentUser $currentUser;
    protected Settings $settings;
    protected Localizer $i18n;
    protected ProjectService $projects;
    protected RequestStack $requestStack;

    #[Required]
    public function setSymineServices(
        EntityManagerInterface $em,
        Authorization $auth,
        CurrentUser $currentUser,
        Settings $settings,
        Localizer $i18n,
        ProjectService $projects,
        RequestStack $requestStack,
    ): void {
        $this->em = $em;
        $this->auth = $auth;
        $this->currentUser = $currentUser;
        $this->settings = $settings;
        $this->i18n = $i18n;
        $this->projects = $projects;
        $this->requestStack = $requestStack;
    }

    protected AccessGuard $guard;

    #[Required]
    public function setAccessGuard(AccessGuard $guard): void
    {
        $this->guard = $guard;
    }

    protected ?Hooks $hooks = null;

    #[Required]
    public function setHooks(Hooks $hooks): void
    {
        $this->hooks = $hooks;
    }

    /**
     * Calls a Redmine hook (Redmine::Hook::Helper#call_hook in controllers): the context gets the controller,
     * the request and hook_caller, the caller passes the project when there is one.
     *
     * @param array<string, mixed> $context
     *
     * @return list<mixed>
     */
    protected function callHook(string $hook, array $context = []): array
    {
        return $this->hooks?->call($hook, $context + ['controller' => $this, 'project' => null, 'request' => $this->requestStack->getCurrentRequest(), 'hook_caller' => $this]) ?? [];
    }

    protected function user(): User
    {
        return $this->currentUser->get();
    }

    /** @param array<string, mixed> $params */
    protected function l(string $key, array $params = []): string
    {
        return $this->i18n->l($key, $params);
    }

    protected function flashNotice(string $key = 'notice_successful_update', array $params = []): void
    {
        $this->addFlash('notice', $this->l($key, $params));
    }

    protected function flashError(string $message): void
    {
        $this->addFlash('error', $message);
    }

    /** Finds a visible project by identifier or id (404 otherwise, 403 if not visible) */
    protected function findProject(string|int $id): Project
    {
        return $this->guard->findProject($id);
    }

    /** Raises 403 (or redirects anonymous users to login) unless the user has the permission */
    protected function authorize(string $permission, ?Project $project = null, bool $global = false): void
    {
        $this->guard->authorize($permission, $project, $global);
    }

    protected function requireLogin(): void
    {
        $this->guard->requireLogin();
    }

    protected function requireAdmin(): void
    {
        $this->guard->requireAdmin();
    }

    /** @return never */
    protected function deny(?string $message = null): void
    {
        $this->guard->deny($message);
    }

    protected function isApi(?Request $request = null): bool
    {
        $request ??= $this->requestStack->getCurrentRequest();

        return null !== $request && ApiRequestMatcher::isApiRequest($request);
    }

    /** Redirects to back_url when it is a local URL (Redmine's redirect_back_or_default) */
    protected function redirectBackOr(Request $request, string $default): Response
    {
        $back = (string) ($request->request->get('back_url') ?? $request->query->get('back_url') ?? '');
        if ('' !== $back && str_starts_with($back, '/') && !str_starts_with($back, '//')) {
            return $this->redirect($back);
        }

        return $this->redirect($default);
    }

    /** @param list<string> $errors */
    protected function apiErrors(array $errors, int $status = 422): JsonResponse
    {
        return new JsonResponse(['errors' => array_values($errors)], $status);
    }

    /** Pagination helper: [offset, limit, page] from request (per_page setting or API offset/limit) */
    protected function paginate(Request $request, int $count): array
    {
        $perPageOptions = $this->settings->perPageOptions();
        if ($this->isApi($request)) {
            $limit = max(1, min(100, (int) ($request->query->get('limit') ?: 25)));
            $offset = max(0, (int) $request->query->get('offset', 0));
            if ($request->query->has('page')) {
                $offset = max(0, ((int) $request->query->get('page') - 1) * $limit);
            }

            return ['offset' => $offset, 'limit' => $limit, 'page' => intdiv($offset, $limit) + 1, 'count' => $count, 'pages' => (int) ceil($count / $limit), 'per_page_options' => $perPageOptions];
        }
        $limit = (int) $request->query->get('per_page', 0);
        if (!\in_array($limit, $perPageOptions, true)) {
            $limit = $perPageOptions[0];
        }
        $pages = max(1, (int) ceil($count / $limit));
        $page = max(1, min($pages, (int) $request->query->get('page', 1)));

        return ['offset' => ($page - 1) * $limit, 'limit' => $limit, 'page' => $page, 'count' => $count, 'pages' => $pages, 'per_page_options' => $perPageOptions];
    }
}
