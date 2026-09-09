<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Transformer;

/**
 * Applies the first transformer that recognises a value, and returns anything else untouched.
 *
 * ⚠️ **The order is the registration order, and the first match wins.** That is a contract, not an
 * implementation detail: a consumer adding a transformer for a type an earlier one already claims
 * will never see it run, and nothing reports that. Register the narrow ones first.
 *
 * ⚠️ **An unrecognised value passes through, it does not throw.** Scalars are the overwhelming
 * majority of a report and need no transformer at all; failing on them would make the chain
 * mandatory rather than additive. The cost is that an unhandled OBJECT reaches the writer, which
 * is exactly the defect the chain exists to prevent — so `transformRow()` is where the type is
 * finally narrowed, and it is the writer's `scalar|null` signature that makes the gap visible to
 * static analysis rather than at run time.
 */
final readonly class ValueTransformerChain
{
    /**
     * @param iterable<ValueTransformerInterface> $transformers
     */
    public function __construct(
        private iterable $transformers = [],
    ) {
    }

    public function transform(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($value)) {
                return $transformer->transform($value);
            }
        }

        return $value;
    }

    /**
     * Transforms a whole row and narrows it to what a writer accepts.
     *
     * ⚠️ Whatever survives the chain without becoming a scalar is stringified here rather than
     * handed to a writer that cannot type it. An object with `__toString()` renders; anything else
     * becomes an empty cell, because the alternative — `Object(App\Entity\Foo)` in a customer's
     * spreadsheet — is worse than a blank.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $row
     *
     * @return array<TKey, scalar|null>
     */
    public function transformRow(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $transformed = $this->transform($value);

            $out[$key] = match (true) {
                null === $transformed, \is_scalar($transformed) => $transformed,
                $transformed instanceof \Stringable => (string) $transformed,
                default => '',
            };
        }

        return $out;
    }
}
