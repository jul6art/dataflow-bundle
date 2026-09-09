<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Translation;

use Jul6Art\DataflowBundle\Report\Spec\FilterOperator;

/**
 * The keys this bundle's JavaScript reads without naming them where a scanner can see them.
 *
 * A consumer hands them to its own translation guard, so the guard knows they are alive:
 *
 * ```php
 * protected static function declaredKeys(): array
 * {
 *     return static::getContainer()->get(DeclaredTranslationKeys::class)->keys();
 * }
 * ```
 *
 * ## Why there is anything to declare at all
 *
 * ⚠️ The operator labels are built from a template literal —
 * `t(\`dataflow.filter.op.${operator.code}\`)` — because there are twelve of them and writing
 * twelve `if`s to keep a scanner happy would be worse code. So the scanner sees a dynamic call and
 * nothing more, and without this list a catalogue clean-up deletes twelve entries that the filter
 * dropdown renders on every report.
 *
 * ⚠️ **And the list is derived from {@see FilterOperator}, not typed out.** A hand-written copy is
 * a second source of truth: adding a case to the enum and forgetting the list would ship an
 * operator whose label is its own key, which is precisely the defect this whole mechanism exists
 * to prevent — the reference application put thirty-four raw keys on one screen that way.
 */
final readonly class DeclaredTranslationKeys
{
    /** Every key of this bundle sits under it, and a consumer's guard groups on it. */
    public const string PREFIX = 'dataflow.';

    /**
     * Read through a variable in Twig, not JavaScript: the builder partial walks a `steps` array
     * and renders `step.label|trans`, so a scanner sees a property access.
     *
     * ⚠️ Four labels, and the alternative is worse: unrolling the loop into four near-identical
     * blocks to satisfy a static pass would duplicate the whole tab markup four times.
     *
     * @var list<string>
     */
    private const array TEMPLATE_KEYS = [
        self::PREFIX.'builder.step.entity',
        self::PREFIX.'builder.step.columns',
        self::PREFIX.'builder.step.filters',
        self::PREFIX.'builder.step.export',
    ];

    /**
     * ⚠️ Not a `const`, because `FilterOperator::cases()` cannot be evaluated in a constant
     * expression. The derivation is the point; a literal list here would drift.
     *
     * @return list<string> sorted, so a diff of this list is readable
     */
    public function keys(): array
    {
        $keys = self::TEMPLATE_KEYS;

        foreach (FilterOperator::cases() as $operator) {
            $keys[] = self::PREFIX.'filter.op.'.$operator->value;
        }

        \sort($keys);

        return $keys;
    }

    /**
     * ⚠️ Empty, deliberately, and typed rather than omitted: a consumer's guard asks for prefixes
     * as well as keys, and returning nothing says "this bundle has no optional-key families" —
     * which is different from the method not existing.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return [];
    }
}
