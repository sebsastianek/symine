<?php

declare(strict_types=1);

namespace App\Service\Admin\CustomFields;

use App\Service\Admin\FieldFormats;
use Doctrine\ORM\EntityManagerInterface;

/** The list of Administration > Custom fields: one tab per kind of customized object having fields. */
final class CustomFieldIndex
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly CustomFieldFinder $finder)
    {
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        $byType = [];
        foreach ($this->finder->all() as $cf) {
            $byType[$cf::typeName()][] = $cf;
        }
        $projectsCount = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT custom_field_id, COUNT(*) c FROM custom_fields_projects GROUP BY custom_field_id') as $row) {
            $projectsCount[(int) $row['custom_field_id']] = (int) $row['c'];
        }
        $tabs = [];
        foreach (FieldFormats::TABS as $name => $label) {
            if (isset($byType[$name])) {
                $tabs[] = ['name' => $name, 'label' => $label, 'template' => 'admin/custom_fields/_index.html.twig'];
            }
        }

        return [
            'tabs' => $tabs,
            'custom_fields_by_type' => $byType,
            'projects_count' => $projectsCount,
        ];
    }
}
