<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Project;
use App\I18n\Localizer;
use App\Service\ProjectService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The access checks of Redmine's ApplicationController (require_login, require_admin, authorize, find_project,
 * deny_access), for the services that load what an action works on. Controllers use the same checks through
 * the helpers of App\Controller\AbstractController, which delegate here.
 *
 * A refused anonymous visitor is sent to the login page (AccessDeniedException: the firewall entry point);
 * a refused user gets a 403.
 */
final class AccessGuard
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly Localizer $i18n,
        private readonly ProjectService $projects,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function deny(?string $message = null): never
    {
        if (!$this->currentUser->get()->isLogged()) {
            // triggers the entry point (redirect to login / 401)
            throw new AccessDeniedException('Access Denied.');
        }
        throw new HttpException(403, $message ?? $this->i18n->l('notice_not_authorized'));
    }

    public function requireLogin(): void
    {
        if (!$this->currentUser->get()->isLogged()) {
            throw new AccessDeniedException('Access Denied.');
        }
    }

    public function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->currentUser->get()->admin) {
            throw new HttpException(403, $this->i18n->l('notice_not_authorized'));
        }
    }

    /** Raises 403 (or sends anonymous users to the login page) unless the user has the permission */
    public function authorize(string $permission, ?Project $project = null, bool $global = false): void
    {
        if (!$this->auth->allowedTo($permission, $project, null, $global)) {
            if (null !== $project && $project->isArchived()) {
                // ApplicationController#authorize: render_403 :message => :notice_not_authorized_archived_project
                $this->requestStack->getCurrentRequest()?->attributes->set('_archived_project', $project);
                throw new HttpException(403, $this->i18n->l('notice_not_authorized_archived_project'));
            }
            $this->deny();
        }
    }

    /** Finds a visible project by identifier or id (404 when it does not exist, 403 when it is not visible) */
    public function findProject(string|int $id): Project
    {
        $project = $this->projects->findByIdentifierOrId($id);
        if (null === $project) {
            throw new NotFoundHttpException();
        }
        if ($project->isArchived() && !$this->currentUser->get()->admin) {
            throw new HttpException(403, $this->i18n->l('notice_not_authorized_archived_project'));
        }
        if (!$this->auth->isProjectVisible($project)) {
            if ($project->isArchived()) {
                // @archived_project: the error page offers the "Unarchive" button to administrators
                $this->requestStack->getCurrentRequest()?->attributes->set('_archived_project', $project);
                throw new HttpException(403, $this->i18n->l('notice_not_authorized_archived_project'));
            }
            $this->deny();
        }

        return $project;
    }
}
