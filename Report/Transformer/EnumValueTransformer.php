<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Transformer;

/**
 * Renders a backed enum as its backing value.
 *
 * ⚠️ Without it a `BackedEnum` reaches the writer as an object, and a JSON export shows
 * `{"name":"Paid","value":"paid"}` — the case's PHP identity rather than the value the rest of the
 * system stores. A consumer re-importing that file would have to know PHP.
 *
 * ⚠️ **The backing value, not a label.** Translating an enum here would make the export depend on
 * the reader's locale, so the same report run twice would produce two files that no longer
 * reconcile. A human-readable label is a column formatting concern; this is the interchange value.
 */
final readonly class EnumValueTransformer implements ValueTransformerInterface
{
    #[\Override]
    public function supports(mixed $value): bool
    {
        return $value instanceof \BackedEnum;
    }

    #[\Override]
    public function transform(mixed $value): string|int
    {
        \assert($value instanceof \BackedEnum);

        return $value->value;
    }
}
