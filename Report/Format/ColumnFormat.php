<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Format;

/**
 * How ONE column of a report should be rendered — attached to a
 * {@see \Jul6Art\DataflowBundle\Report\Spec\ReportColumn}, applied by {@see ColumnFormatter}.
 *
 * ## Why this exists, and why it is opt-in
 *
 * ⚠️ **Without a format, a column renders exactly as it always has** — ISO for a date, the raw
 * value for a number, `1`/`0` for a boolean unless {@see \Jul6Art\DataflowBundle\Report\Transformer\BoolValueTransformer}
 * is registered. D-7 was never that the default was wrong: `DateTimeValueTransformer`'s own
 * docblock says ISO is the deliberate choice for a file a PROGRAM reads as often as a person. What
 * was missing was a way for the report a PERSON reads to ask for something else, one column at a
 * time, without dragging every export of every consumer into one hard-coded convention. This
 * object is that ask, and `null` on a column is silence, not a defect.
 *
 * ## Named constructors, not a public constructor
 *
 * `money()` needs a currency; `number()` and `percent()` do not. A single constructor accepting
 * every parameter for every kind would let `ColumnFormat::money(null, null)` compile with nothing
 * to say what it means; four named constructors say, at the call site, exactly which one applies.
 */
final readonly class ColumnFormat
{
    private function __construct(
        public Kind $kind,
        public ?string $currency = null,
        public ?int $decimals = null,
    ) {
    }

    public static function number(?int $decimals = null): self
    {
        return new self(Kind::Number, decimals: $decimals);
    }

    public static function money(string $currency, ?int $decimals = null): self
    {
        return new self(Kind::Money, currency: $currency, decimals: $decimals);
    }

    public static function percent(int $decimals = 0): self
    {
        return new self(Kind::Percent, decimals: $decimals);
    }

    /**
     * A date, rendered without its time — `Y-m-d 00:00:00` and `Y-m-d 14:30:00` alike become one
     * date, because a column an operator asked to see as a date is not the column to show them a
     * midnight that never happened.
     */
    public static function date(): self
    {
        return new self(Kind::Date);
    }

    public static function dateTime(): self
    {
        return new self(Kind::DateTime);
    }
}
