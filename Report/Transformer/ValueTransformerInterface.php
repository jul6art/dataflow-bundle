<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Transformer;

/**
 * Turns a raw value out of a Doctrine scalar result into one a person can read.
 *
 * ## Why this exists at all
 *
 * `getArrayResult()` hands back PHP objects, not strings: a `DateTimeImmutable` for every date
 * column, a `BackedEnum` for every enum column. A writer that does not know about them serialises
 * a date as
 * `{"date":"2026-05-27 00:00:00.000000","timezone_type":3,"timezone":"UTC"}` — which is not an
 * export, it is a leak of PHP's internals into a file someone opens.
 *
 * ⚠️ **A new non-scalar type gets its own transformer**, rather than a patch in each writer. That
 * is the whole point of the chain: three writers times N types is N patches too many, and the one
 * that gets forgotten is always the one nobody exports until a customer does.
 */
interface ValueTransformerInterface
{
    public function supports(mixed $value): bool;

    public function transform(mixed $value): string|int|float|bool|null;
}
