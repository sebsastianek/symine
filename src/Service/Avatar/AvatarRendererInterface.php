<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use App\Entity\Principal;

/**
 * The picture shown next to the name of a user or a group (Redmine AvatarsHelper#avatar): the avatar() function
 * of the templates.
 *
 * Extension point: plugins that keep pictures of their own (uploaded photos, a directory) decorate this service
 * and fall back to the decorated one (docs/PLUGINS.md, "Decorating core services").
 */
interface AvatarRendererInterface
{
    /** @return string HTML, '' when there is nobody to show */
    public function html(?Principal $user, int $size = 24, ?string $class = null): string;
}
