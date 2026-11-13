<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Principal;
use App\Entity\User;
use App\I18n\Localizer;
use App\Query\QueryColumn;
use App\Query\UserQuery;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Port of UserQueriesHelper (+ the QueriesHelper parts used by the admin user list). */
final class UserQueryExtension extends AbstractExtension
{
    public function __construct(
        private readonly QueryExtension $queries,
        private readonly Localizer $i18n,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('user_query_column_content', $this->columnContent(...), $safe),
            new TwigFunction('user_query_rows', $this->rows(...)),
            new TwigFunction('user_query_totals', $this->renderTotals(...), $safe),
            new TwigFunction('user_status_label', $this->userStatusLabel(...)),
        ];
    }

    private static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, \ENT_QUOTES);
    }

    /** UserQueriesHelper#user_status_label */
    public function userStatusLabel(int|string|null $value): ?string
    {
        return match ((int) $value) {
            Principal::STATUS_ACTIVE => $this->i18n->l('status_active'),
            Principal::STATUS_REGISTERED => $this->i18n->l('status_registered'),
            Principal::STATUS_LOCKED => $this->i18n->l('status_locked'),
            default => null,
        };
    }

    /** UserQueriesHelper#twofa_scheme_label */
    public function twofaSchemeLabel(?string $value): string
    {
        return null !== $value && '' !== $value ? $this->i18n->l('twofa__'.$value.'__name') : $this->i18n->l('label_disabled');
    }

    /** QueriesHelper#column_content + UserQueriesHelper#column_value */
    public function columnContent(UserQuery $query, QueryColumn $column, User $user): string
    {
        $value = $query->columnValue($column, $user);
        if ('status' === $column->name) {
            return self::h($this->userStatusLabel($value));
        }
        if (\in_array($column->name, ['created_on', 'updated_on', 'last_login_on', 'passwd_changed_on'], true)) {
            return $value instanceof \DateTimeInterface ? self::h($this->i18n->formatTime($value)) : '';
        }

        return $this->queries->formatObject(\is_array($value) ? ['custom_field' => $value['custom_field'], 'value' => $value['value']] : $value);
    }

    /** UserQueriesHelper#csv_value (QueriesHelper#csv_content); null gives an empty unquoted CSV field */
    public function csvValue(UserQuery $query, QueryColumn $column, User $user): ?string
    {
        $value = $query->columnValue($column, $user);
        $dec = $this->i18n->l('general_csv_decimal_separator');
        if (\is_array($value)) {
            // custom field: blank values give "" (like Redmine's CSV)
            $raw = array_values(array_filter((array) $value['value'], static fn ($v) => null !== $v && '' !== (string) $v));
            if ([] === $raw) {
                return '';
            }
            if ('float' === $value['custom_field']->fieldFormat) {
                return implode(', ', array_map(static fn ($v) => str_replace('.', $dec, \sprintf('%.2f', (float) $v)), $raw));
            }

            return html_entity_decode(strip_tags($this->queries->formatObject(['custom_field' => $value['custom_field'], 'value' => $value['value']], false)), \ENT_QUOTES);
        }

        return match (true) {
            'status' === $column->name => (string) $this->userStatusLabel($value),
            'twofa_scheme' === $column->name => $this->twofaSchemeLabel($value),
            null === $value => '',
            \is_bool($value) => $this->i18n->l($value ? 'general_text_Yes' : 'general_text_No'),
            $value instanceof \DateTimeInterface => $this->i18n->formatTime($value),
            default => (string) $value,
        };
    }

    /**
     * QueriesHelper#grouped_query_results for the user list.
     *
     * @param list<User> $users
     *
     * @return list<array{user: User, group_name: ?string, group_count: ?int, group_totals: ?string}>
     */
    public function rows(UserQuery $query, array $users): array
    {
        $counts = $query->resultCountByGroup();
        $column = $query->groupByColumn();
        $totalsByGroup = [];
        if (null !== $column) {
            foreach ($query->totalableColumns() as $c) {
                $totalsByGroup[] = [$c, $query->totalByGroupFor($c)];
            }
        }
        $rows = [];
        $previous = null;
        foreach ($users as $u) {
            $row = ['user' => $u, 'group_name' => null, 'group_count' => null, 'group_totals' => null];
            if (null !== $counts && null !== $column) {
                $key = $query->groupKey($u);
                if ($key !== $previous) {
                    $name = '' === $key ? '' : $this->columnContent($query, $column, $u);
                    $row['group_name'] = '' === trim(strip_tags($name)) ? self::h($this->i18n->l('label_blank_value')) : $name;
                    $row['group_count'] = $counts[$key] ?? null;
                    $row['group_totals'] = implode(' ', array_map(fn (array $t) => $this->queries->totalTag($t[0], $t[1][$key] ?? 0), $totalsByGroup));
                    $previous = $key;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** QueriesHelper#render_query_totals */
    public function renderTotals(UserQuery $query): string
    {
        if ([] === $query->totalableColumns()) {
            return '';
        }

        return '<p class="query-totals">'.implode(' ', array_map(fn (array $t) => $this->queries->totalTag($t[0], $t[1]), $query->totals())).'</p>';
    }
}
