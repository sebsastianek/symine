<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Version;
use App\Service\CustomFieldService;
use App\Service\VersionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form of a version (versions/_form.html.erb), as a page and as the "new version" modal of the issue form.
 *
 * Fields: version[name], [description], [status], [wiki_page_title], [effective_date] (due_date in the API),
 * [sharing], [default_project_version], [custom_field_values][<id>] (custom_fields: [{id, value}] in the API),
 * back_url.
 *
 * @phpstan-type Extra array{default_project_version: bool, custom_field_values: array<int|string, mixed>, invalid_date?: bool}
 */
final class VersionForm
{
    public function __construct(
        private readonly VersionService $versions,
        private readonly CustomFieldService $customFields,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Fills a version that is not saved from the given attributes (the new form opened with version[...]).
     *
     * @param array<string, mixed> $attrs
     *
     * @return Extra what was given and is not a property of the version
     */
    public function prefill(Version $version, array $attrs): array
    {
        return $this->versions->assign($version, $attrs);
    }

    /**
     * Assigns the submitted attributes and saves the version when they are valid.
     *
     * @param array<string, mixed> $attrs
     *
     * @return array{0: list<string>, 1: Extra} the errors (none: saved) and what was submitted besides the
     *                                           properties of the version
     */
    public function submit(Version $version, array $attrs): array
    {
        $extra = $this->versions->assign($version, $attrs);

        return [$this->versions->save($version, $extra), $extra];
    }

    /**
     * After a rejected update: the version as it is saved, with the submitted attributes on top, so that the
     * form is shown again with what was typed.
     *
     * @param array<string, mixed> $attrs
     */
    public function restore(Version $version, array $attrs): void
    {
        $this->em->refresh($version);
        $this->versions->assign($version, $attrs);
    }

    /**
     * @param list<string> $errors
     * @param Extra|null   $extra  what submit() or prefill() returned; null for the form of a saved version
     *
     * @return array<string, mixed> variables of versions/new.html.twig and versions/edit.html.twig
     */
    public function view(Version $version, Request $request, array $errors = [], ?array $extra = null): array
    {
        $extra ??= ['default_project_version' => false, 'custom_field_values' => []];
        $cfValues = null !== $version->id ? $this->customFields->values('Version', $version->id) : $this->customFields->defaultValues($this->versions->visibleCustomFields($version));
        foreach ($extra['custom_field_values'] ?? [] as $fid => $v) {
            $cfValues[$fid] = $v;
        }

        return [
            'project' => $version->project,
            'version' => $version,
            'errors' => $errors,
            'extra' => $extra,
            'cf_values' => $cfValues,
            'back_url' => (string) ($request->query->get('back_url') ?? $request->request->get('back_url') ?? ''),
        ];
    }

    /**
     * The modal only shows the custom field values that were submitted: a new one starts without any.
     *
     * @param list<string> $errors
     * @param Extra        $extra
     *
     * @return array<string, mixed> variables of versions/_new_modal.html.twig
     */
    public function modalView(Version $version, array $extra, array $errors = [], bool $submitted = false): array
    {
        return [
            'project' => $version->project,
            'version' => $version,
            'errors' => $errors,
            'extra' => $extra,
            'cf_values' => $submitted ? $extra['custom_field_values'] : [],
        ];
    }
}
