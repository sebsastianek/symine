<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\Project;
use App\Entity\Query as QueryRecord;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of Redmine's ProjectAdminQuery (app/models/project_admin_query.rb): the administration
 * projects list (/admin/projects). All projects (any status), list display only, admin only.
 */
#[Exclude]
class ProjectAdminQuery extends ProjectQuery
{
    /** ProjectAdminQuery.visible: all queries for admins, none for the others */
    public static function recordVisibleInScope(QueryRecord $record, User $user): bool
    {
        return $user->admin;
    }

    public function isVisibleBy(?User $user = null): bool
    {
        return (bool) ($user ?? $this->ctx->user())->admin;
    }

    public function isEditableBy(?User $user = null): bool
    {
        return (bool) ($user ?? $this->ctx->user())->admin;
    }

    public function availableDisplayTypes(): array
    {
        return ['list'];
    }

    public function displayType(): string
    {
        return 'list';
    }

    public function projectStatusesValues(): array
    {
        $values = parent::projectStatusesValues();
        $values[] = [$this->ctx->l('project_status_archived'), (string) Project::STATUS_ARCHIVED];
        $values[] = [$this->ctx->l('project_status_scheduled_for_deletion'), (string) Project::STATUS_SCHEDULED_FOR_DELETION];

        return $values;
    }

    /** base_scope: Project.where(statement) - no visibility restriction */
    public function baseConditionSql(): string
    {
        return '1=1';
    }
}
