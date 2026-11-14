<?php

declare(strict_types=1);

namespace App\Theme\Http;

use App\Theme\Theme;
use App\Theme\ThemeRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gives controllers the installed theme named by the route: an action declares a "Theme $theme" argument and the
 * {id} of the URL is looked up in the registry (404 when there is no such theme or when it cannot be used).
 */
final class ThemeValueResolver implements ValueResolverInterface
{
    public function __construct(private readonly ThemeRegistry $registry)
    {
    }

    /** @return iterable<Theme> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Theme::class !== $argument->getType()) {
            return [];
        }
        $id = $request->attributes->get('id');
        $theme = \is_string($id) ? $this->registry->get($id) : null;
        if (null === $theme) {
            throw new NotFoundHttpException(\sprintf('Theme "%s" not found.', \is_scalar($id) ? $id : ''));
        }

        return [$theme];
    }
}
