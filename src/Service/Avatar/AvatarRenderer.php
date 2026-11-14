<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use App\Entity\AnonymousUser;
use App\Entity\Group;
use App\Entity\Principal;
use App\Entity\User;
use App\Service\Settings;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Redmine's avatars: Gravatar when it is enabled, the initials of the user otherwise. */
#[AsAlias(AvatarRendererInterface::class)]
final class AvatarRenderer implements AvatarRendererInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function html(?Principal $user, int $size = 24, ?string $class = null): string
    {
        if (null === $user) {
            return '';
        }
        $cls = trim('avatar '.($class ?? '').' s'.$size);
        if ($user instanceof AnonymousUser) {
            return '<img class="'.$cls.' anonymous-avatar" src="/assets/anonymous.png" width="'.$size.'" height="'.$size.'" alt="">';
        }
        if ($user instanceof Group) {
            return '<img class="'.$cls.' group-avatar" src="/assets/group.svg" width="'.$size.'" height="'.$size.'" alt="">';
        }
        if ($user instanceof User && $this->settings->bool('gravatar_enabled') && $user->getMail()) {
            $hash = md5(strtolower(trim((string) $user->getMail())));
            $default = $this->settings->string('gravatar_default') ?: 'identicon';

            return '<img class="'.$cls.' gravatar" title="'.htmlspecialchars($user->getName()).'" src="https://www.gravatar.com/avatar/'.$hash.'?rating=PG&size='.($size * 2).'&default='.rawurlencode($default).'" width="'.$size.'" height="'.$size.'" alt="">';
        }
        $initials = mb_strtoupper(mb_substr($user->firstname, 0, 1).mb_substr($user->lastname, 0, 1));

        return '<span role="img" class="'.$cls.' avatar-color-'.((int) $user->id % 8).'" title="'.htmlspecialchars($user->getName()).'">'.htmlspecialchars($initials).'</span>';
    }
}
