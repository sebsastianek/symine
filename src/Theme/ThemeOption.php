<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * A typed, customisable setting of a theme.
 *
 *  - choice: one of the declared choices; exposed as the data-theme-<name>="<choice>" attribute of <html>
 *            and as the tokens of the chosen choice;
 *  - toggle: "1" or "0"; exposed as data-theme-<name>="on|off" and as its tokens when on;
 *  - color:  a CSS colour, or the id of one of the presets; written to the CSS custom property "token";
 *  - length: a CSS length (number + unit), within min/max; written to "token";
 *  - number: a number within min/max; written to "token";
 *  - text:   free text for templates (theme_option()); written to "token" as a CSS string when one is set;
 *  - image:  an uploaded picture; written to "token" as url(...) when one is set.
 *
 * Administrators set every option; options with "user: true" can also be set by each user.
 */
final readonly class ThemeOption
{
    public const string TYPE_CHOICE = 'choice';
    public const string TYPE_TOGGLE = 'toggle';
    public const string TYPE_COLOR = 'color';
    public const string TYPE_LENGTH = 'length';
    public const string TYPE_NUMBER = 'number';
    public const string TYPE_TEXT = 'text';
    public const string TYPE_IMAGE = 'image';

    public const array TYPES = [self::TYPE_CHOICE, self::TYPE_TOGGLE, self::TYPE_COLOR, self::TYPE_LENGTH, self::TYPE_NUMBER, self::TYPE_TEXT, self::TYPE_IMAGE];

    public const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /**
     * @param array<string, string>                                                                                                                              $label       locale => label
     * @param array<string, string>                                                                                                                              $description locale => help text
     * @param array<string, array{label: array<string, string>, description: array<string, string>, tokens: array<string, string>, schemes: array<string, array<string, string>>, swatch: ?string}> $choices
     * @param array<string, array{label: array<string, string>, value: string, schemes: array<string, string>}>                                                  $presets     colour presets
     * @param array<string, string>                                                                                                                              $tokens      tokens of a toggle that is on
     * @param array<string, array<string, string>>                                                                                                               $schemes     per scheme tokens of a toggle that is on
     * @param list<string>                                                                                                                                       $units       accepted units of a length
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $label,
        public string $default = '',
        public bool $user = false,
        public bool $quick = false,
        public array $description = [],
        public string $group = '',
        public array $choices = [],
        public array $presets = [],
        public ?string $token = null,
        public array $tokens = [],
        public array $schemes = [],
        public ?float $min = null,
        public ?float $max = null,
        public ?float $step = null,
        public array $units = ['px'],
    ) {
    }

    /** Name of the <html> attribute carrying the value, for the types that have one */
    public function attribute(): ?string
    {
        if (Theme::OPTION_COLOR_SCHEME === $this->name) {
            return 'data-color-scheme';
        }
        if (\in_array($this->type, [self::TYPE_CHOICE, self::TYPE_TOGGLE], true) || (self::TYPE_COLOR === $this->type && [] !== $this->presets)) {
            return 'data-theme-'.str_replace('_', '-', $this->name);
        }

        return null;
    }

    /** Value of the <html> attribute for an option value */
    public function attributeValue(string $value): ?string
    {
        return match (true) {
            null === $this->attribute() => null,
            self::TYPE_TOGGLE === $this->type => '1' === $value ? 'on' : 'off',
            self::TYPE_COLOR === $this->type => isset($this->presets[$value]) ? $value : 'custom',
            default => $value,
        };
    }

    public function isPreset(string $value): bool
    {
        return self::TYPE_COLOR === $this->type && isset($this->presets[$value]);
    }

    /**
     * Valid, canonical form of a submitted or stored value; null when it is not acceptable.
     * An empty string means "not set" for the types that allow it (text, image).
     */
    public function normalize(mixed $value): ?string
    {
        if (\is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (!\is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        switch ($this->type) {
            case self::TYPE_CHOICE:
                return isset($this->choices[$value]) ? $value : null;
            case self::TYPE_TOGGLE:
                return match (strtolower($value)) {
                    '1', 'on', 'true', 'yes' => '1',
                    '0', 'off', 'false', 'no', '' => '0',
                    default => null,
                };
            case self::TYPE_COLOR:
                return isset($this->presets[$value]) ? $value : CssValue::color($value);
            case self::TYPE_LENGTH:
                $length = CssValue::length($value, $this->units);
                if (null === $length) {
                    return null;
                }

                return $this->inRange((float) $length) ? $length : null;
            case self::TYPE_NUMBER:
                if (!is_numeric($value) || !$this->inRange((float) $value)) {
                    return null;
                }

                return (string) (0 + $value);
            case self::TYPE_TEXT:
                return mb_strlen($value) <= 255 && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) ? $value : null;
            case self::TYPE_IMAGE:
                // "": no picture; the default: a file shipped with the theme; otherwise the name of an uploaded file
                return '' === $value || $value === $this->default || preg_match('/^[a-z0-9][a-z0-9_-]*\.('.implode('|', self::IMAGE_EXTENSIONS).')$/', $value) ? $value : null;
        }

        return null;
    }

    /**
     * Value submitted by the appearance forms for this option: options[<name>], with options_custom[<name>]
     * holding the colour picked when "custom" is selected. Unchecked toggles submit nothing.
     */
    public function fromForm(mixed $submitted, mixed $custom = null): mixed
    {
        return match (true) {
            self::TYPE_TOGGLE === $this->type => null !== $submitted && '0' !== $submitted && '' !== $submitted ? '1' : '0',
            self::TYPE_COLOR === $this->type && 'custom' === $submitted => $custom,
            default => $submitted,
        };
    }

    private function inRange(float $number): bool
    {
        return (null === $this->min || $number >= $this->min) && (null === $this->max || $number <= $this->max);
    }

    /** Label in the given locale (English, then the first one, as fallbacks) */
    public static function text(array $labels, string $locale, string $fallback = ''): string
    {
        return (string) ($labels[$locale] ?? $labels[strtok($locale, '-')] ?? $labels['en'] ?? (array_values($labels)[0] ?? $fallback));
    }
}
