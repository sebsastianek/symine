<?php

declare(strict_types=1);

namespace App\Theme\Form;

use App\Entity\User;
use App\Theme\Theme;
use App\Theme\ThemeContext;
use App\Theme\ThemeOption;
use App\Theme\ThemeRegistry;
use App\Theme\ThemeSettings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What each person may change about the appearance: their theme (when administrators allow it) and the personal
 * options of the theme in use. Logged-in users keep their choices in their preferences, visitors in a cookie.
 *
 * Fields of the page: theme, options_theme (the theme the options were shown for), options[<name>],
 * options_custom[<name>]. Fields of the quick switcher: option, value.
 */
final class PersonalAppearanceForm
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly ThemeSettings $settings,
        private readonly ThemeContext $context,
    ) {
    }

    /** Whether there is anything a person may change */
    public function isAvailable(): bool
    {
        return $this->settings->usersMayChooseTheme() || $this->settings->usersMayCustomise();
    }

    public function isQuickUpdateAvailable(): bool
    {
        return $this->settings->usersMayCustomise();
    }

    /**
     * Saves the page of a logged-in user.
     *
     * @return array<string, string> option name => rejected value
     *
     * @throws NotFoundHttpException when the chosen theme is not installed
     */
    public function submit(User $user, Request $request): array
    {
        if ($this->settings->usersMayChooseTheme() && $request->request->has('theme')) {
            $id = (string) $request->request->get('theme');
            $this->settings->setUserTheme($user, '' === $id ? null : ($this->registry->get($id) ?? throw new NotFoundHttpException()));
            $this->context->refresh();
        }

        $theme = $this->context->theme();
        // the options belong to a theme: they are only saved when the form was shown for the theme now in use
        if (!$this->settings->usersMayCustomise() || $request->request->get('options_theme') !== $theme->id) {
            return [];
        }
        $submitted = $request->request->all('options');
        $custom = $request->request->all('options_custom');
        $site = $this->settings->siteOptions($theme);
        $values = [];
        foreach ($theme->userOptions() as $name => $option) {
            if (!isset($submitted[$name]) && ThemeOption::TYPE_TOGGLE !== $option->type) {
                continue;
            }
            $value = $option->fromForm($submitted[$name] ?? null, $custom[$name] ?? null);
            // choosing what the site uses means following the site, also when its setting changes later
            $values[$name] = $option->normalize($value) === $site[$name] ? null : $value;
        }
        $rejected = $this->settings->saveUserOptions($user, $theme, $values);
        $this->context->refresh();

        return $rejected;
    }

    /**
     * Sets one personal option of the theme in use (quick switcher); an empty value goes back to the site setting.
     * The cookie of a visitor who is not logged in is added to $response.
     *
     * @return string|null what is wrong with the request, null when the value was saved
     */
    public function quickUpdate(User $user, Request $request, Response $response): ?string
    {
        $theme = $this->context->theme();
        $name = (string) $request->request->get('option');
        if (!isset($theme->userOptions()[$name])) {
            return 'Unknown option';
        }
        $value = $request->request->get('value');
        $values = [$name => null === $value || '' === $value ? null : $value];

        if ($user->isLogged()) {
            $rejected = $this->settings->saveUserOptions($user, $theme, $values);
        } else {
            [$stored, $rejected] = $this->settings->applyUserValues($theme, $this->context->cookieOptions($theme) ?? [], $values);
            if ([] === $rejected) {
                $response->headers->setCookie($this->cookie($request, $theme, $stored));
            }
        }
        if ([] !== $rejected) {
            return 'Invalid value';
        }
        $this->context->refresh();

        return null;
    }

    /** @return array{theme: string, options: array<string, string>} the appearance now in effect, for the switcher */
    public function state(): array
    {
        return ['theme' => $this->context->theme()->id, 'options' => $this->context->options()];
    }

    /**
     * @param array<string, string> $rejected
     *
     * @return array<string, mixed> what the page shows
     */
    public function view(User $user, array $rejected = []): array
    {
        $theme = $this->context->theme();

        return [
            'user' => $user,
            'themes' => $this->settings->usersMayChooseTheme() ? $this->registry->all() : [],
            'site_theme' => $this->settings->siteTheme(),
            'chosen_theme' => $this->settings->userTheme($user),
            'active_theme' => $theme,
            'user_options' => $this->settings->usersMayCustomise() ? $theme->userOptions() : [],
            'values' => $this->context->options(),
            'site_values' => $this->settings->siteOptions($theme),
            'personal' => $this->context->personalOptions(),
            'rejected' => $rejected,
        ];
    }

    /** @param array<string, string> $values */
    private function cookie(Request $request, Theme $theme, array $values): Cookie
    {
        // one theme at a time: the cookie stays small and never outlives a change of theme
        $data = [] === $values ? '' : json_encode([$theme->id => $values], \JSON_THROW_ON_ERROR);

        return Cookie::create(ThemeContext::COOKIE, $data, '' === $data ? 1 : new \DateTimeImmutable('+1 year'), $request->getBasePath() ?: '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }
}
