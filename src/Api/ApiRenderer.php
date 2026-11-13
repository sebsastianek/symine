<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders Redmine REST API payloads in JSON or XML with the same structure as Redmine's *.api.rsb views.
 *
 * Collections: render(['issues' => [...], 'total_count' => 10, 'offset' => 0, 'limit' => 25], 'issues', ...)
 * In XML, the collection becomes <issues type="array" total_count=".." offset=".." limit="..">.
 * Objects: render(['issue' => [...]]). Nested lists use the singular of the key as element name.
 */
final class ApiRenderer
{
    /**
     * @param array<string, mixed>        $data
     * @param array<string, list<string>> $xmlAttributes nested element name => keys rendered as XML attributes
     *                                                   (e.g. ['custom_field' => ['id', 'name', 'multiple']])
     */
    public function render(Request $request, array $data, int $status = 200, array $xmlAttributes = []): Response
    {
        $format = self::format($request);
        // ApplicationController#api_meta: no paging metadata with the "nometa" param or X-Redmine-Nometa header
        if (($request->query->has('nometa') && '' !== (string) $request->query->get('nometa')) || $request->headers->has('X-Redmine-Nometa')) {
            $data = array_diff_key($data, ['total_count' => 1, 'offset' => 1, 'limit' => 1]);
        }
        if ('xml' === $format) {
            $this->xmlAttributes = $xmlAttributes;
            try {
                return new Response($this->toXml($data), $status, ['Content-Type' => 'application/xml; charset=utf-8']);
            } finally {
                $this->xmlAttributes = [];
            }
        }
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        // Redmine::Views::Builders::Json: JSONP only when Setting.jsonp_enabled?, callback stripped to [a-zA-Z0-9_.]
        $callback = $request->query->get('callback') ?? $request->query->get('jsonp');
        if (null !== $callback && true === $this->settings?->bool('jsonp_enabled')) {
            $callback = (string) preg_replace('/[^a-zA-Z0-9_.]/', '', (string) $callback);
            if ('' !== $callback) {
                return new Response($callback.'('.$json.')', $status, ['Content-Type' => 'application/javascript; charset=utf-8']);
            }
        }

        return new JsonResponse($json, $status, [], true);
    }

    public function __construct(private readonly ?\App\Service\Settings $settings = null)
    {
    }

    public function noContent(): Response
    {
        return new Response('', 204);
    }

    /** @param list<string> $errors */
    public function errors(Request $request, array $errors, int $status = 422): Response
    {
        if ('xml' === self::format($request)) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n<errors type=\"array\">";
            foreach ($errors as $e) {
                $xml .= '<error>'.htmlspecialchars($e, \ENT_XML1).'</error>';
            }

            return new Response($xml.'</errors>', $status, ['Content-Type' => 'application/xml; charset=utf-8']);
        }

        return new JsonResponse(['errors' => array_values($errors)], $status);
    }

    public static function format(Request $request): string
    {
        if (str_ends_with($request->getPathInfo(), '.xml') || 'xml' === $request->query->get('format')) {
            return 'xml';
        }

        return 'json';
    }

    /**
     * Reads the request payload (JSON or XML body) as an array, e.g. ['issue' => [...]].
     *
     * @return array<string, mixed>
     */
    public static function payload(Request $request): array
    {
        $content = (string) $request->getContent();
        if ('' === trim($content)) {
            return $request->request->all();
        }
        $type = (string) $request->headers->get('Content-Type');
        if (str_contains($type, 'xml') || str_starts_with(ltrim($content), '<')) {
            $xml = @simplexml_load_string($content);
            if (false === $xml) {
                return [];
            }

            return [$xml->getName() => self::xmlToArray($xml)];
        }
        $data = json_decode($content, true);

        return \is_array($data) ? $data : [];
    }

    private static function xmlToArray(\SimpleXMLElement $el): mixed
    {
        $attrs = $el->attributes();
        if (isset($attrs['type']) && 'array' === (string) $attrs['type']) {
            $out = [];
            foreach ($el->children() as $child) {
                $out[] = self::xmlToArray($child);
            }

            return $out;
        }
        if (0 === $el->count()) {
            return (string) $el;
        }
        $out = [];
        foreach ($el->children() as $name => $child) {
            $out[$name] = self::xmlToArray($child);
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    public function toXml(array $data): string
    {
        $meta = array_filter($data, static fn ($v, $k) => \in_array($k, ['total_count', 'offset', 'limit'], true), \ARRAY_FILTER_USE_BOTH);
        $rest = array_diff_key($data, $meta);
        $root = (string) array_key_first($rest);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $value = $rest[$root] ?? [];
        if (\is_array($value) && array_is_list($value)) {
            $attrs = ' type="array"';
            foreach ($meta as $k => $v) {
                $attrs .= ' '.$k.'="'.(int) $v.'"';
            }
            $xml .= '<'.$root.$attrs.'>';
            foreach ($value as $item) {
                $xml .= $this->element(self::singular($root), $item);
            }

            return $xml.'</'.$root.'>';
        }

        return $xml.$this->element($root, $value);
    }

    /** @var array<string, list<string>> */
    private array $xmlAttributes = [];

    private function element(string $name, mixed $value, int $depth = 0): string
    {
        if (\is_float($value)) {
            // Ruby Float#to_s: 8.0 => "8.0"
            $value = (floor($value) === $value && abs($value) < 1e15) ? number_format($value, 1, '.', '') : (string) $value;
        }
        if (null === $value) {
            return '<'.$name.'/>';
        }
        if (\is_bool($value)) {
            return '<'.$name.'>'.($value ? 'true' : 'false').'</'.$name.'>';
        }
        if (!\is_array($value)) {
            return '<'.$name.'>'.htmlspecialchars((string) $value, \ENT_XML1 | \ENT_QUOTES).'</'.$name.'>';
        }
        if (array_is_list($value)) {
            $s = '<'.$name.' type="array">';
            foreach ($value as $item) {
                $s .= $this->element(self::singular($name), $item, $depth + 1);
            }

            return $s.'</'.$name.'>';
        }
        // scalar values of "id"/"name" pairs are rendered as attributes, like Redmine (<status id="1" name="New"/>)
        // (nested only: top level collection items are rendered with child elements, e.g. <role><id>3</id>..)
        $isRef = $depth > 0 && [] !== $value && [] === array_diff(array_keys($value), ['id', 'name', 'is_closed', 'inherited']) && !\is_array($value['id'] ?? null);
        if ($isRef) {
            $attrs = '';
            foreach ($value as $k => $v) {
                $attrs .= ' '.$k.'="'.htmlspecialchars(\is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, \ENT_XML1 | \ENT_QUOTES).'"';
            }

            return '<'.$name.$attrs.'/>';
        }
        $attrKeys = $depth > 0 ? ($this->xmlAttributes[$name] ?? []) : [];
        // Redmine renders custom field values nested in an object as <custom_field id=".." name=".."><value>..</value></custom_field>
        // (top level /custom_fields.xml lists use child elements, hence depth > 1)
        if ([] === $attrKeys && 'custom_field' === $name && $depth > 1 && \array_key_exists('value', $value)) {
            $attrKeys = ['id', 'name', 'multiple'];
        }
        if ([] !== $attrKeys) {
            $attrs = '';
            foreach ($attrKeys as $k) {
                if (\array_key_exists($k, $value)) {
                    $v = $value[$k];
                    $attrs .= ' '.$k.'="'.htmlspecialchars(\is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, \ENT_XML1 | \ENT_QUOTES).'"';
                    unset($value[$k]);
                }
            }
            if ([] === $value) {
                return '<'.$name.$attrs.'/>';
            }
            $s = '<'.$name.$attrs.'>';
        } else {
            $s = '<'.$name.'>';
        }
        foreach ($value as $k => $v) {
            $s .= $this->element((string) $k, $v, $depth + 1);
        }

        return $s.'</'.$name.'>';
    }

    public static function singular(string $plural): string
    {
        return match (true) {
            'custom_fields' === $plural => 'custom_field',
            'statuses' === $plural, 'issue_statuses' === $plural => substr($plural, 0, -2),
            'news' === $plural => 'news',
            'children' === $plural => 'issue',
            'watchers' === $plural => 'user',
            'allowed_statuses' === $plural => 'status',
            'changesets' === $plural => 'changeset',
            'possible_values' === $plural => 'possible_value',
            'enabled_standard_fields' === $plural => 'field',
            str_ends_with($plural, 'ies') => substr($plural, 0, -3).'y',
            str_ends_with($plural, 's') => substr($plural, 0, -1),
            default => $plural,
        };
    }

    /** ISO 8601 like Rails JSON (UTC "Z") */
    public static function time(?\DateTimeInterface $t): ?string
    {
        return $t?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function date(?\DateTimeInterface $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    /** @return array{id: int|null, name: string}|null */
    public static function ref(?object $o, ?string $name = null): ?array
    {
        if (null === $o) {
            return null;
        }

        return ['id' => $o->id ?? null, 'name' => $name ?? (method_exists($o, 'getName') ? $o->getName() : (string) $o)];
    }
}
