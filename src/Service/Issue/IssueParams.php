<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Api\ApiRenderer;
use App\Security\ApiRequestMatcher;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the parameters of the issue actions from a request the way Rails' params does: form body, query
 * string or API payload.
 */
final class IssueParams
{
    /** Request parameter from the body, then the query string (Request::get() is deprecated) */
    public static function get(Request $request, string $key, mixed $default = null): mixed
    {
        if ($request->request->has($key)) {
            return $request->request->all()[$key];
        }
        if ($request->query->has($key)) {
            return $request->query->all()[$key];
        }

        return $request->attributes->get($key, $default);
    }

    /** @return array<string, mixed> issue params from a form or an API payload */
    public static function issue(Request $request): array
    {
        if (ApiRequestMatcher::isApiRequest($request)) {
            $payload = ApiRenderer::payload($request);
            $issue = $payload['issue'] ?? [];
            if (!\is_array($issue)) {
                return [];
            }
            // XML: <custom_field id="1"><value>..</value></custom_field> (ids are attributes)
            if ('xml' === ApiRenderer::format($request) && isset($issue['custom_fields'])) {
                $xml = @simplexml_load_string((string) $request->getContent());
                $cfs = [];
                foreach ($xml && isset($xml->custom_fields) ? $xml->custom_fields->custom_field : [] as $cf) {
                    $value = $cf->value;
                    if (isset($value['type']) && 'array' === (string) $value['type']) {
                        $v = [];
                        foreach ($value->value as $item) {
                            $v[] = (string) $item;
                        }
                    } else {
                        $v = (string) $value;
                    }
                    $cfs[] = ['id' => (string) ($cf['id'] ?? $cf->id), 'value' => $v];
                }
                $issue['custom_fields'] = $cfs;
            }
            // API custom_fields: [{id:, value:}] => custom_field_values
            if (isset($issue['custom_fields']) && \is_array($issue['custom_fields'])) {
                $cfv = [];
                foreach ($issue['custom_fields'] as $cf) {
                    if (\is_array($cf) && isset($cf['id'])) {
                        $cfv[(string) $cf['id']] = $cf['value'] ?? '';
                    }
                }
                $issue['custom_field_values'] = ($issue['custom_field_values'] ?? []) + $cfv;
                unset($issue['custom_fields']);
            }
            if (isset($issue['uploads']['upload'])) {
                $issue['uploads'] = $issue['uploads']['upload'];
            }
            if (isset($issue['uploads']['token'])) {
                $issue['uploads'] = [$issue['uploads']];
            }
            if (isset($issue['watcher_user_ids']['watcher_user_id'])) {
                $issue['watcher_user_ids'] = (array) $issue['watcher_user_ids']['watcher_user_id'];
            }

            return $issue;
        }

        $all = $request->isMethod('GET') ? $request->query->all() : $request->request->all() + $request->query->all();

        return \is_array($all['issue'] ?? null) ? $all['issue'] : [];
    }

    /**
     * The attributes of a bulk update (IssuesController#parse_params_for_bulk_update): blank values are
     * dropped, "none" values clear the attribute.
     *
     * @return array<string, mixed>
     */
    public static function bulkAttributes(Request $request): array
    {
        // Rails params: query string (context menu links) merged with the form body
        $attributes = array_replace($request->query->all('issue'), $request->request->all('issue'));
        // parse_params_for_bulk_update: drop blank values
        $attributes = array_filter($attributes, static fn ($v) => !(null === $v || '' === $v || (\is_array($v) && [] === $v)));
        if (isset($attributes['custom_field_values']) && \is_array($attributes['custom_field_values'])) {
            $attributes['custom_field_values'] = array_filter($attributes['custom_field_values'], static fn ($v) => !(null === $v || '' === $v || (\is_array($v) && [] === array_filter($v, static fn ($x) => '' !== $x))));
        }

        return self::replaceNoneValues($attributes);
    }

    /**
     * "__none__" / "none" submitted for an attribute or a custom field means "no value".
     *
     * @param array<string, mixed> $attrs
     *
     * @return array<string, mixed>
     */
    public static function replaceNoneValues(array $attrs): array
    {
        foreach ($attrs as $k => $v) {
            if ('__none__' === $v || 'none' === $v) {
                $attrs[$k] = '';
            }
        }
        if (isset($attrs['custom_field_values']) && \is_array($attrs['custom_field_values'])) {
            foreach ($attrs['custom_field_values'] as $k => $v) {
                if (\is_array($v)) {
                    if (false !== ($pos = array_search('__none__', $v, true))) {
                        unset($v[$pos]);
                        $v[] = '';
                    }
                    $attrs['custom_field_values'][$k] = array_values($v);
                } elseif ('__none__' === $v) {
                    $attrs['custom_field_values'][$k] = '';
                }
            }
        }

        return $attrs;
    }

    /** @return list<string> the associations asked with ?include=a,b */
    public static function includes(Request $request): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('include', '')))));
    }
}
