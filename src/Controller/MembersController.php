<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Group;
use App\Entity\Member;
use App\Entity\Project;
use App\Service\MembersManager;
use App\Service\MembershipService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's MembersController (project memberships, HTML + REST API). */
final class MembersController extends AbstractController
{
    public function __construct(
        private readonly MembersManager $members,
        private readonly MembershipService $memberships,
        private readonly ApiRenderer $api,
    ) {
    }

    private function wantsJs(Request $request): bool
    {
        return str_ends_with($request->getPathInfo(), '.js')
            || ($request->isXmlHttpRequest() && str_contains((string) $request->headers->get('Accept'), 'javascript'));
    }

    private function authorizeMembers(Project $project, bool $read = false): void
    {
        if ($read && $this->auth->allowedTo('view_members', $project)) {
            return;
        }
        $this->authorize('manage_members', $project);
    }

    private function findMember(string $id): Member
    {
        $member = $this->em->find(Member::class, (int) $id);
        if (null === $member) {
            throw $this->createNotFoundException();
        }
        $this->findProject((string) $member->project->id);

        return $member;
    }

    /** @return array<string, mixed> membership API payload (members/show.api.rsb) */
    private function payload(Member $m): array
    {
        $data = [
            'id' => $m->id,
            'project' => ['id' => $m->project->id, 'name' => $m->project->name],
        ];
        $data[$m->principal instanceof Group ? 'group' : 'user'] = ['id' => $m->principal->id, 'name' => $m->principal->getName()];
        $roles = [];
        foreach ($m->memberRoles as $mr) {
            $r = ['id' => $mr->role->id, 'name' => $mr->role->name];
            if (null !== $mr->inheritedFrom) {
                $r['inherited'] = true;
            }
            $roles[] = $r;
        }
        $data['roles'] = $roles;

        return $data;
    }

    private function settingsRedirect(Project $project): Response
    {
        return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'members']);
    }

    /** Re-renders the members tab (create/update/destroy .js responses) */
    private function membersTabJs(Project $project, string $extra = ''): Response
    {
        $html = $this->renderView('members/_members.html.twig', ['project' => $project, 'members' => $this->members->projectMembers($project), 'members_manager' => $this->members]);

        return new Response("$('#tab-content-members').html(".json_encode($html, \JSON_HEX_TAG).");\nhideOnLoad();\n".$extra, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    #[Route('/projects/{project_id}/memberships', name: 'project_memberships', methods: ['GET'])]
    #[Route('/projects/{project_id}/memberships.{_format}', name: 'project_memberships_format', requirements: ['_format' => 'json|xml'], methods: ['GET'])]
    public function index(Request $request, string $project_id, string $_format = 'html'): Response
    {
        $project = $this->findProject($project_id);
        $this->authorizeMembers($project, true);
        if ('html' === $_format) {
            return $this->settingsRedirect($project);
        }
        $all = $this->em->createQuery('SELECT m FROM App\Entity\Member m WHERE m.project = :p ORDER BY m.id')->setParameter('p', $project)->getResult();
        $pager = $this->paginate($request, \count($all));
        $list = \array_slice($all, $pager['offset'], $pager['limit']);

        return $this->api->render($request, [
            'memberships' => array_map($this->payload(...), $list),
            'total_count' => \count($all), 'offset' => $pager['offset'], 'limit' => $pager['limit'],
        ]);
    }

    #[Route('/memberships/{id}.{_format}', name: 'membership_show', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, string $id): Response
    {
        $member = $this->findMember($id);
        $this->authorizeMembers($member->project, true);

        return $this->api->render($request, ['membership' => $this->payload($member)]);
    }

    #[Route('/projects/{project_id}/memberships/new', name: 'member_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorizeMembers($project);
        [$principals, $count] = $this->members->principalsForNewMembers($project, null);
        $vars = [
            'project' => $project,
            'principals' => $principals,
            'principal_count' => $count,
            'roles' => $this->members->managedRoles($project),
        ];
        if ($this->wantsJs($request)) {
            $html = $this->renderView('members/_new_modal.html.twig', $vars);

            return new Response("$('#ajax-modal').html(".json_encode($html, \JSON_HEX_TAG).");\nshowModal('ajax-modal', '90%');", 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('members/new.html.twig', $vars + [
            'main_menu_selected' => 'settings', 'page_title' => $this->l('label_member_new'), 'body_css' => 'controller-members action-new',
        ]);
    }

    #[Route('/projects/{project_id}/memberships/autocomplete', name: 'member_autocomplete', methods: ['GET'])]
    #[Route('/projects/{project_id}/memberships/autocomplete.js', name: 'member_autocomplete_js', methods: ['GET'])]
    public function autocomplete(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorizeMembers($project);
        $page = max(1, (int) $request->query->get('page', 1));
        [$principals, $count] = $this->members->principalsForNewMembers($project, (string) $request->query->get('q', ''), ($page - 1) * 100, 100);
        $html = $this->renderView('members/_principals.html.twig', ['project' => $project, 'principals' => $principals, 'principal_count' => $count, 'page' => $page, 'q' => (string) $request->query->get('q', '')]);

        return new Response("$('#principals_for_new_member').html(".json_encode($html, \JSON_HEX_TAG).');', 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    #[Route('/projects/{project_id}/memberships', name: 'member_create', methods: ['POST'])]
    #[Route('/projects/{project_id}/memberships.{_format}', name: 'member_create_format', requirements: ['_format' => 'json|xml|js'], methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorizeMembers($project);
        $api = $this->isApi($request);
        $data = $api ? (array) (ApiRenderer::payload($request)['membership'] ?? []) : (array) ($request->request->all()['membership'] ?? []);
        $userIds = $data['user_ids'] ?? $data['user_id'] ?? [];
        $userIds = \is_array($userIds) ? array_values(array_filter($userIds, static fn ($v) => '' !== (string) $v)) : [$userIds];
        $roleIds = (array) ($data['role_ids'] ?? []);
        $result = ['members' => [], 'errors' => []];
        if ($api || [] !== $data) {
            $result = $this->members->create($project, $userIds, $roleIds, null, $this->l(...));
        }
        if ($api) {
            if ([] !== $result['errors'] || [] === $result['members']) {
                return $this->api->errors($request, $result['errors'] ?: [$this->l('field_principal').' '.$this->l('activerecord.errors.messages.blank')]);
            }
            $member = $result['members'][0];
            $response = $this->api->render($request, ['membership' => $this->payload($member)], 201);
            $response->headers->set('Location', $this->generateUrl('membership_show', ['id' => $member->id, '_format' => ApiRenderer::format($request)], 0));

            return $response;
        }
        if ($this->wantsJs($request)) {
            $extra = '';
            if ([] !== $result['errors']) {
                $extra = 'alert('.json_encode($this->l('notice_failed_to_save_members', ['errors' => implode(', ', $result['errors'])]), \JSON_HEX_TAG).');';
            } elseif ([] !== $result['members']) {
                $extra = "hideModal();\n";
                foreach ($result['members'] as $m) {
                    $extra .= '$("#member-'.$m->id.'").effect("highlight");'."\n";
                }
            }

            return $this->membersTabJs($project, $extra);
        }
        if ([] !== $result['errors']) {
            $this->flashError($this->l('notice_failed_to_save_members', ['errors' => implode(', ', $result['errors'])]));
        }

        return $this->settingsRedirect($project);
    }

    #[Route('/memberships/{id}/edit', name: 'member_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(Request $request, string $id): Response
    {
        $member = $this->findMember($id);
        $this->authorizeMembers($member->project);
        $vars = ['member' => $member, 'roles' => $this->members->givableRoles(), 'members_manager' => $this->members, 'xhr' => $this->wantsJs($request)];
        if ($this->wantsJs($request)) {
            $html = $this->renderView('members/_edit.html.twig', $vars);

            return new Response('$("#member-'.$member->id.'-roles").hide();'."\n".'$("#member-'.$member->id.'-form").html('.json_encode($html, \JSON_HEX_TAG).');', 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('members/edit.html.twig', $vars + [
            'project' => $member->project, 'main_menu_selected' => 'settings',
            'page_title' => $member->principal->getName().' - '.$member->project->name, 'body_css' => 'controller-members action-edit',
        ]);
    }

    #[Route('/memberships/{id}', name: 'member_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH', 'POST'])]
    #[Route('/memberships/{id}.{_format}', name: 'member_update_format', requirements: ['id' => '\d+', '_format' => 'json|xml|js'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $id): Response
    {
        $member = $this->findMember($id);
        $project = $member->project;
        $this->authorizeMembers($project);
        $api = $this->isApi($request);
        $data = $api ? (array) (ApiRenderer::payload($request)['membership'] ?? []) : (array) ($request->request->all()['membership'] ?? []);
        $saved = true;
        if (\array_key_exists('role_ids', $data)) {
            $roles = $this->members->editableRoleIds($member, $project, (array) $data['role_ids']);
            $hasInherited = false;
            foreach ($member->memberRoles as $mr) {
                $hasInherited = $hasInherited || null !== $mr->inheritedFrom;
            }
            if ([] === $roles && !$hasInherited) {
                $saved = false;
            } else {
                $this->memberships->setRoles($member, $roles);
            }
        }
        if ($api) {
            return $saved ? $this->api->noContent() : $this->api->errors($request, [$this->l('field_role').' '.$this->l('activerecord.errors.messages.empty')]);
        }
        if ($this->wantsJs($request)) {
            return $this->membersTabJs($project, '$("#member-'.(int) $id.'").effect("highlight");');
        }

        return $this->settingsRedirect($project);
    }

    #[Route('/memberships/{id}', name: 'member_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/memberships/{id}.{_format}', name: 'member_destroy_format', requirements: ['id' => '\d+', '_format' => 'json|xml|js'], methods: ['DELETE'])]
    public function destroy(Request $request, string $id): Response
    {
        $member = $this->findMember($id);
        $project = $member->project;
        $this->authorizeMembers($project);
        $destroyed = false;
        if ($this->members->isDeletable($member)) {
            $this->members->destroy($member);
            $destroyed = true;
        }
        if ($this->isApi($request)) {
            return $destroyed ? $this->api->noContent() : new Response('', 422);
        }
        if ($this->wantsJs($request)) {
            return $this->membersTabJs($project);
        }

        return $this->settingsRedirect($project);
    }
}
