<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Api\ApiRenderer;
use App\Entity\Attachment;
use App\I18n\Localizer;
use App\Service\AttachmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The file name and description of attachments: changed one at a time through the REST API
 * ({"attachment": {"filename": .., "description": ..}}) or for all the files of an object with the
 * "edit attached files" form (attachments[<id>][filename], attachments[<id>][description]).
 */
final class AttachmentForm
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly AttachmentContainers $containers,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Saves the changes of an API request when they are valid.
     *
     * @return list<string> the errors that prevented saving
     */
    public function update(Attachment $a, Request $request): array
    {
        $attrs = (array) (ApiRenderer::payload($request)['attachment'] ?? []);
        $errors = $this->apply($a, $attrs);
        if ([] === $errors) {
            $this->em->flush();
        }

        return $errors;
    }

    /**
     * Saves the changes of the "edit attached files" form when they are all valid.
     *
     * @param list<Attachment> $atts
     *
     * @return list<string> the errors that prevented saving
     */
    public function updateAll(array $atts, Request $request): array
    {
        $params = $request->request->all('attachments');
        $errors = [];
        foreach ($atts as $a) {
            if (isset($params[$a->id]) && \is_array($params[$a->id])) {
                array_push($errors, ...$this->apply($a, $params[$a->id]));
            }
        }
        if ([] === $errors) {
            $this->em->flush();
        }

        return $errors;
    }

    /**
     * The "edit attached files" form.
     *
     * @param non-empty-list<Attachment> $atts
     * @param list<string>               $errors
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, string $objectType, int $objectId, array $atts, array $errors = []): array
    {
        return [
            'attachments' => $atts,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'container_link' => $this->containers->link($atts[0]),
            'project' => $this->containers->projectOf($atts[0]),
            'errors' => $errors,
            'back_url' => $request->query->get('back_url') ?? $request->request->get('back_url') ?? $this->containers->url($atts[0]),
            'main_menu_selected' => $this->containers->menuItem($atts[0]),
        ];
    }

    /**
     * @param array<string, mixed> $attrs
     *
     * @return list<string>
     */
    private function apply(Attachment $a, array $attrs): array
    {
        $errors = [];
        if (\array_key_exists('filename', $attrs)) {
            $name = trim((string) $attrs['filename']);
            if ('' === $name) {
                $errors[] = $this->i18n->l('field_filename').' '.$this->i18n->l('activerecord.errors.messages.blank');
            } elseif (!$this->attachments->isExtensionAllowed($name)) {
                $errors[] = $this->i18n->l('field_filename').' '.$this->i18n->l('error_attachment_extension_not_allowed', ['extension' => pathinfo($name, \PATHINFO_EXTENSION)]);
            } else {
                $a->filename = AttachmentService::sanitizeFilename($name);
            }
        }
        if (\array_key_exists('description', $attrs)) {
            $a->description = mb_substr(trim((string) $attrs['description']), 0, 255);
        }

        return $errors;
    }
}
