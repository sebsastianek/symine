<?php

declare(strict_types=1);

namespace App\Plugin\FieldFormat;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Custom field formats registered by plugins (Redmine::FieldFormat.add) */
final class FieldFormatRegistry
{
    /** @var array<string, FieldFormatInterface>|null */
    private ?array $formats = null;

    /** @param iterable<FieldFormatInterface> $tagged */
    public function __construct(
        #[AutowireIterator(FieldFormatInterface::TAG)] private readonly iterable $tagged = [],
    ) {
    }

    /** @return array<string, FieldFormatInterface> */
    public function all(): array
    {
        if (null === $this->formats) {
            $this->formats = [];
            foreach ($this->tagged as $f) {
                $this->formats[$f->getName()] = $f;
            }
        }

        return $this->formats;
    }

    public function get(string $name): ?FieldFormatInterface
    {
        return $this->all()[$name] ?? null;
    }

    public function register(FieldFormatInterface $format): void
    {
        $this->all();
        $this->formats[$format->getName()] = $format;
    }

    /**
     * Metadata in the FieldFormats::FORMATS structure.
     *
     * @return array{label: string, multiple: bool, searchable: bool, filter: bool, classes: ?list<string>, partial: string}
     */
    public static function describe(FieldFormatInterface $f): array
    {
        return ['label' => $f->getLabel(), 'multiple' => $f->isMultipleSupported(), 'searchable' => $f->isSearchable(), 'filter' => $f->isFilterable(), 'classes' => $f->getCustomizedClasses(), 'partial' => 'string'];
    }
}
