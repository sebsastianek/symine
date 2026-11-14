<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Api\ApiRenderer;
use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\Version;
use App\Event\ContentEvent;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\ProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The "new file" form of a project (FilesController#new and #create): files are added to the project or to
 * one of its versions.
 *
 * Fields: version_id, attachments[<n>][file|token|description]; in the API: file: {token, version_id,
 * filename, description} or attachments: [{token, ...}].
 */
final class FileForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentService $attachments,
        private readonly ProjectService $projects,
        private readonly CurrentUser $currentUser,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * Attaches the submitted files to the project, or to the version given by version_id (404 when it is not
     * a version of the project).
     *
     * @return array{0: list<Attachment>, 1: list<string>} the files added (none: nothing was saved) and the
     *                                                     errors of the files that could not be saved
     */
    public function submit(Project $project, Request $request): array
    {
        $isApi = ApiRequestMatcher::isApiRequest($request);
        $payload = $isApi ? ApiRenderer::payload($request) : [];
        $fileParams = (array) ($payload['file'] ?? $request->request->all('file'));
        $versionId = $request->request->get('version_id') ?? ($payload['version_id'] ?? null) ?? ($fileParams['version_id'] ?? null);
        $container = $project;
        if (null !== $versionId && '' !== (string) $versionId) {
            $container = $this->em->getRepository(Version::class)->findOneBy(['id' => (int) $versionId, 'project' => $project]);
            if (null === $container) {
                throw new NotFoundHttpException();
            }
        }
        $uploads = null;
        if ($isApi) {
            $uploads = !empty($fileParams['token']) ? [$fileParams] : (array) ($payload['attachments'] ?? []);
        }
        $user = $this->currentUser->get();
        [$atts, $errors] = $this->attachments->fromRequest($request, $user, $uploads);
        if ([] !== $atts) {
            $this->attachments->attachTo($atts, $container instanceof Version ? 'Version' : 'Project', (int) $container->id);
            $this->em->flush();
            $this->events->dispatch(new ContentEvent('attachment', 'created', $atts[0], $user, ['container' => $container, 'attachments' => $atts, 'project' => $project]));
        }

        return [$atts, $errors];
    }

    /** @return array<string, mixed> variables of files/new.html.twig */
    public function view(Project $project): array
    {
        $versions = $this->em->getRepository(Version::class)->findBy(['project' => $project]);

        return ['project' => $project, 'versions' => $this->projects->sortVersions($versions)];
    }
}
