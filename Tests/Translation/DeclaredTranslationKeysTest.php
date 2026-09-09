<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Translation;

use Jul6Art\DataflowBundle\Report\Spec\FilterOperator;
use Jul6Art\DataflowBundle\Translation\DeclaredTranslationKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclaredTranslationKeys::class)]
final class DeclaredTranslationKeysTest extends TestCase
{
    /**
     * ⚠️ Derived from the enum, never typed out. A hand-written copy is a second source of truth:
     * add a case, forget the list, and the filter dropdown renders the raw key
     * `dataflow.filter.op.newthing` to every user — which is the defect this whole mechanism
     * exists to prevent, and which the reference application shipped thirty-four times on one
     * screen.
     */
    public function testEveryFilterOperatorHasItsLabelDeclared(): void
    {
        $declared = new DeclaredTranslationKeys()->keys();

        foreach (FilterOperator::cases() as $operator) {
            self::assertContains('dataflow.filter.op.'.$operator->value, $declared);
        }
    }

    /**
     * ⚠️ And the four step labels, read through `step.label|trans` in the builder partial — a
     * property access, so a Twig scanner sees no key either.
     */
    public function testTheStepLabelsReadThroughAVariableAreDeclaredToo(): void
    {
        $declared = new DeclaredTranslationKeys()->keys();

        foreach (['entity', 'columns', 'filters', 'export'] as $step) {
            self::assertContains('dataflow.builder.step.'.$step, $declared);
        }
    }

    /**
     * The whole list, so an addition or a removal shows up in a diff rather than in nobody's
     * notice. Twelve operators plus four step labels.
     */
    public function testItDeclaresNothingElse(): void
    {
        self::assertCount(
            \count(FilterOperator::cases()) + 4,
            new DeclaredTranslationKeys()->keys(),
        );
    }

    public function testTheKeysAreSortedSoADiffOfThemIsReadable(): void
    {
        $declared = new DeclaredTranslationKeys()->keys();
        $sorted = $declared;
        sort($sorted);

        self::assertSame($sorted, $declared);
    }

    public function testEveryDeclaredKeyLivesUnderTheBundleNamespace(): void
    {
        foreach (new DeclaredTranslationKeys()->keys() as $key) {
            self::assertStringStartsWith(DeclaredTranslationKeys::PREFIX, $key);
        }
    }

    /**
     * Empty, and asserted so: it says "this bundle has no optional-key families", which is
     * different from the method not existing.
     */
    public function testItDeclaresNoOptionalPrefixFamilies(): void
    {
        self::assertSame([], new DeclaredTranslationKeys()->prefixes());
    }
}
