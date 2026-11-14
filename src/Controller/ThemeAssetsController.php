<?php

declare(strict_types=1);

namespace App\Controller;

use App\Theme\Http\ThemeAssetResponder;
use App\Theme\Theme;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/** Serves the files of the themes (App\Theme\Http\ThemeAssetResponder); the Theme comes from the {id} of the route */
#[AsController]
final class ThemeAssetsController
{
    private const string ID = '[A-Za-z0-9][A-Za-z0-9_-]*';

    public function __construct(private readonly ThemeAssetResponder $responder)
    {
    }

    #[Route('/themes/{id}/generated.css', name: 'theme_generated_css', requirements: ['id' => self::ID], methods: ['GET'], priority: 2)]
    public function generated(Request $request, Theme $theme): Response
    {
        return $this->responder->generatedStylesheet($request, $theme);
    }

    #[Route('/themes/{id}/uploads/{file}', name: 'theme_upload', requirements: ['id' => self::ID, 'file' => '[a-z0-9][a-z0-9_-]*\.[a-z]+'], methods: ['GET'], priority: 2)]
    public function upload(Request $request, Theme $theme, string $file): Response
    {
        return $this->responder->upload($request, $theme, $file);
    }

    #[Route('/themes/{id}/{path}', name: 'theme_asset', requirements: ['id' => self::ID, 'path' => '.+'], methods: ['GET'])]
    public function asset(Request $request, Theme $theme, string $path): Response
    {
        return $this->responder->asset($request, $theme, $path);
    }
}
