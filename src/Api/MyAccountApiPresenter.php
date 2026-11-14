<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\User;
use App\Entity\UserCustomField;
use App\Service\AccountService;
use App\Service\CustomFieldService;

/** The account of the current user in the REST API (GET /my/account.json|xml, my/show.api.rsb). */
final class MyAccountApiPresenter
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly CustomFieldService $customFields,
    ) {
    }

    /** @return array<string, mixed> the "user" element */
    public function present(User $user): array
    {
        $data = [
            'id' => $user->id,
            'login' => $user->login,
            'admin' => $user->admin,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'mail' => $user->getMail(),
            'created_on' => ApiRenderer::time($user->createdOn),
            'last_login_on' => ApiRenderer::time($user->lastLoginOn),
            'api_key' => $this->accounts->apiKey($user),
        ];
        $visible = array_values(array_filter($this->customFields->fieldsOf(UserCustomField::class), static fn ($f) => $f->visible || $user->admin));
        if ([] !== $visible) {
            $cfValues = $this->customFields->values('Principal', $user->id);
            $data['custom_fields'] = array_map(static fn ($f) => ['id' => $f->id, 'name' => $f->name] + ($f->isMultiple() ? ['multiple' => true] : []) + ['value' => $cfValues[$f->id] ?? ($f->isMultiple() ? [] : null)], $visible);
        }

        return $data;
    }
}
