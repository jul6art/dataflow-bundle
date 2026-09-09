<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Report\Catalog;

use Jul6Art\DataflowBundle\Report\Catalog\ReportField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The one contract that spans two languages: what the field catalogue serialises, and what the
 * shipped controller reads back.
 *
 * ## The defect this exists for
 *
 * ⚠️ In v1.0.0 the catalogue produced `traversed` and the controller read `relation` — a name
 * inherited from the application the controller was extracted from. So its field sort compared
 * `undefined` to `undefined`, the branch never fired, and every column behind a relation sorted in
 * among the root's own. **Nothing failed.** A missing property is not an error in JavaScript, no
 * test crossed the boundary, and the screen looked plausible: the columns were all there, just in
 * the wrong order — which is the kind of wrongness a user reports as "the picker is confusing"
 * rather than as a bug.
 */
#[CoversClass(ReportField::class)]
final class ReportFieldWireShapeTest extends TestCase
{
    /**
     * ⚠️ Listed by hand, on purpose. A test that derived this list from the JavaScript would agree
     * with whatever the JavaScript says, which is exactly how the two sides drifted. Written out,
     * a change on either side has to come here and be looked at.
     *
     * @var list<string>
     */
    private const array READ_BY_THE_CONTROLLER = ['path', 'label', 'traversed'];

    public function testTheWireShapeIsTheFourNamesTheCatalogueKnows(): void
    {
        $wire = new ReportField('customer.name', 'customer › name', 'string', true)->toArray();

        self::assertSame(['path', 'label', 'type', 'traversed'], array_keys($wire));
        self::assertSame('customer.name', $wire['path']);
        self::assertSame('customer › name', $wire['label']);
        self::assertSame('string', $wire['type']);
        self::assertTrue($wire['traversed']);
    }

    /**
     * ⚠️ Every property the shipped controller reads off a field has to exist in the wire shape.
     * This is the assertion that would have failed in v1.0.0.
     */
    public function testEveryPropertyTheControllerReadsExistsInTheWireShape(): void
    {
        $wire = new ReportField('number', 'number', 'string')->toArray();

        foreach (self::READ_BY_THE_CONTROLLER as $property) {
            self::assertArrayHasKey($property, $wire);
        }
    }

    /**
     * And the other direction: the controller really does read those names, so the list above is
     * not a wish.
     *
     * ⚠️ Two patterns, because the controller names a catalogue field `field` everywhere except in
     * the sort comparator, where the two operands are `a` and `b`. Scanning only the sort is what
     * this test did first, and it reported `label` as unread — which would have invited someone to
     * drop `label` from the wire shape and blank every column name.
     *
     * `type` is deliberately absent from the list: the catalogue offers it for a filter widget the
     * builder does not choose yet.
     */
    public function testTheControllerReadsExactlyThosePropertiesOffAField(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/report_builder_controller.js');
        self::assertIsString($source);

        $sort = preg_match('/_sortFields\(fields\) \{(.*?)\n    \}/s', $source, $matches);
        self::assertSame(1, $sort, 'The field sort has moved or changed shape.');

        preg_match_all('/\bfield\.([a-zA-Z]+)/', $source, $named);
        preg_match_all('/\b[ab]\.([a-zA-Z]+)/', $matches[1], $compared);

        $read = array_values(array_unique([...$named[1], ...$compared[1]]));
        sort($read);

        $expected = self::READ_BY_THE_CONTROLLER;
        sort($expected);

        self::assertSame($expected, $read);
    }
}
