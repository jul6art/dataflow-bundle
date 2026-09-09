<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Transformer;

/**
 * Renders a date or a date-time in ISO 8601.
 *
 * ⚠️ **A date column and a date-time column come back as the same PHP class.** Doctrine hydrates
 * both `date_immutable` and `datetime_immutable` into `DateTimeImmutable`, so the value alone
 * cannot say which was meant. The heuristic — midnight exactly means a date — is pragmatic and it
 * is wrong for a timestamp that genuinely lands on midnight; the alternative would be to carry the
 * Doctrine field type all the way here, which couples the transformer chain to the schema for a
 * cosmetic gain.
 *
 * ⚠️ **ISO, not a localised format, and deliberately.** These files are read by programs as often
 * as by people, and `01/02/2026` means two different days on two sides of an ocean. A report
 * builder that wants a localised column formats it in its own layer.
 */
final readonly class DateTimeValueTransformer implements ValueTransformerInterface
{
    #[\Override]
    public function supports(mixed $value): bool
    {
        return $value instanceof \DateTimeInterface;
    }

    #[\Override]
    public function transform(mixed $value): string
    {
        \assert($value instanceof \DateTimeInterface);

        return '00:00:00' === $value->format('H:i:s')
            ? $value->format('Y-m-d')
            : $value->format(\DateTimeInterface::ATOM);
    }
}
