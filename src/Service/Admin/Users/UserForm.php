<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Api\ApiRenderer;
use App\Entity\Principal;
use App\Entity\User;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use App\Service\Admin\SecurityNotifier;
use App\Service\Admin\UserManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * The user form of the administration and the users API (users#create, users#update): reads user[...],
 * pref[...] and send_information from the form or the API payload, saves the account and sends the
 * e-mails that go with it.
 */
final class UserForm
{
    public function __construct(
        private readonly UserManager $users,
        private readonly SecurityNotifier $security,
        private readonly CurrentUser $currentUser,
    ) {
    }

    public function create(Request $request): UserSubmission
    {
        $api = ApiRequestMatcher::isApiRequest($request);
        $payload = $api ? ApiRenderer::payload($request) : $request->request->all();
        $attrs = \is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $pref = \is_array($payload['pref'] ?? null) ? $payload['pref'] : null;
        $user = $this->users->newUser();
        unset($attrs['group_ids']);
        if (!$api && !\array_key_exists('password_confirmation', $attrs)) {
            $attrs['password_confirmation'] = '';
        }
        [$errors, $password] = $this->users->save($user, $attrs, $pref);
        if ([] === $errors && !empty($payload['send_information'])) {
            $this->users->deliverAccountInformation($user, $password);
        }

        return new UserSubmission($user, $errors, $attrs, !empty($payload['send_information']));
    }

    public function update(User $user, Request $request): UserSubmission
    {
        $api = ApiRequestMatcher::isApiRequest($request);
        $payload = $api ? ApiRenderer::payload($request) : array_merge($request->query->all(), $request->request->all());
        $attrs = \is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $pref = \is_array($payload['pref'] ?? null) ? $payload['pref'] : null;
        $wasStatus = $user->status;
        // is_updating_password: a password was given and the account is (or becomes) internal
        $isUpdatingPassword = '' !== (string) ($attrs['password'] ?? '')
            && (null === $user->authSourceId || '' === trim((string) ($attrs['auth_source_id'] ?? '')));
        if (\array_key_exists('password', $attrs) && '' === (string) $attrs['password']) {
            unset($attrs['password'], $attrs['password_confirmation']);
        }
        [$errors, $password] = $this->users->save($user, $attrs, $pref);
        if ([] === $errors) {
            $wasActivated = Principal::STATUS_REGISTERED === $wasStatus && Principal::STATUS_ACTIVE === $user->status;
            if ($isUpdatingPassword) {
                $this->security->passwordUpdated($user);
            }
            if ($wasActivated) {
                $this->security->accountActivated($user);
            }
            if (!$wasActivated && $user->isActive() && !empty($payload['send_information']) && $user->id !== $this->currentUser->get()->id) {
                $this->users->deliverAccountInformation($user, $password);
            }
        }

        return new UserSubmission($user, $errors, $attrs, !empty($payload['send_information']));
    }
}
