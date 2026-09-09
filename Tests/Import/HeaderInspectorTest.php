<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import;

use Jul6Art\DataflowBundle\Exception\UnreadableFileException;
use Jul6Art\DataflowBundle\Import\HeaderInspector;
use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderInspector::class)]
final class HeaderInspectorTest extends TestCase
{
    /** @var list<string> */
    private const array FIELDS = ['firstName', 'lastName', 'email', 'jobTitle'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellings(): iterable
    {
        yield 'exact' => ['firstName', 'firstName'];
        yield 'lower' => ['firstname', 'firstName'];
        yield 'upper' => ['FIRSTNAME', 'firstName'];
        yield 'snake' => ['first_name', 'firstName'];
        yield 'kebab' => ['first-name', 'firstName'];
        yield 'spaced' => ['First Name', 'firstName'];
        yield 'padded' => ['  first name  ', 'firstName'];
        yield 'e-mail with punctuation' => ['E-Mail', 'email'];
        yield 'required marker' => ['Email *', 'email'];
        yield 'trailing colon' => ['Job title:', 'jobTitle'];
    }

    #[DataProvider('spellings')]
    public function testAHeaderIsMatchedThroughItsSpelling(string $header, string $field): void
    {
        $inspection = new HeaderInspector()->inspect([$header], self::FIELDS);

        self::assertSame([0 => $field], $inspection->mapping);
    }

    /**
     * ⚠️ The accent table, and both cases of it. A French export writes `Prénom`, and a header
     * folded through the process locale gives `'e` on glibc and `?` on musl — the same file mapping
     * on a laptop and failing in the container.
     */
    public function testAnAccentedHeaderMatchesItsUnaccentedField(): void
    {
        $inspection = new HeaderInspector()->inspect(['prénom', 'PRÉNOM', 'Nom'], ['prenom', 'nom']);

        self::assertSame([2 => 'nom'], $inspection->mapping, 'The unambiguous one maps…');
        self::assertSame([0, 1], $inspection->ambiguous, '…and the two spellings of prenom collide.');
    }

    /**
     * ⚠️ The defect a name-keyed mapping cannot even represent: both columns are kept out, and the
     * screen asks. Letting the later one win reads a plausible wrong column for every row.
     */
    public function testTwoColumnsClaimingOneFieldAreBothLeftUnmapped(): void
    {
        $inspection = new HeaderInspector()->inspect(['email', 'lastName', 'E-mail'], self::FIELDS);

        self::assertSame([1 => 'lastName'], $inspection->mapping);
        self::assertSame([0, 2], $inspection->ambiguous);
        self::assertFalse($inspection->isUnambiguous());
    }

    public function testABlankHeaderIsUnknownAndNeverMapped(): void
    {
        $inspection = new HeaderInspector()->inspect(['email', '', '   '], self::FIELDS);

        self::assertSame([0 => 'email'], $inspection->mapping);
        self::assertSame([1, 2], $inspection->unknown);
    }

    /**
     * ⚠️ An extra column is the normal case, not an error: a file exported from another system
     * carries its own identifiers. Treating it as blocking would refuse most real files.
     */
    public function testAnUnknownColumnDoesNotBlockTheImport(): void
    {
        $inspection = new HeaderInspector()->inspect(['email', 'salesforce_id'], self::FIELDS);

        self::assertSame([1], $inspection->unknown);
        self::assertTrue($inspection->isUnambiguous());
    }

    public function testTheFieldsNoColumnSuppliedAreListed(): void
    {
        $inspection = new HeaderInspector()->inspect(['email', 'First Name'], self::FIELDS);

        self::assertSame(['lastName', 'jobTitle'], $inspection->missing);
    }

    /**
     * Where the line is drawn, and why it is drawn there: punctuation around a header is decoration
     * and is ignored, an extra WORD is not.
     *
     * ⚠️ Matching `Job Title (optional)` would mean matching on substrings, and then `Email
     * (invalid)` suggests the e-mail column. A suggestion the user accepts without reading is worse
     * than no suggestion, so the fold normalises and then compares exactly.
     */
    public function testADecoratingWordIsNotMatchedAway(): void
    {
        $inspection = new HeaderInspector()->inspect(['Job Title (optional)'], self::FIELDS);

        self::assertSame([], $inspection->mapping);
        self::assertSame([0], $inspection->unknown);
    }

    public function testAFileWithNoUsableColumnIsNotUnambiguous(): void
    {
        self::assertFalse(new HeaderInspector()->inspect(['a', 'b'], self::FIELDS)->isUnambiguous());
    }

    /**
     * ⚠️ Peeking must read ONE record. The mapping screen runs on an upload of unknown size, and a
     * peek that consumes the file would read 50 MB to show twelve column names.
     */
    public function testPeekReadsExactlyOneRecord(): void
    {
        $reader = new class implements TabularReaderInterface {
            public int $recordsRead = 0;

            public function code(): string
            {
                return 'fake';
            }

            public function supports(string $filePath): bool
            {
                return true;
            }

            public function read(string $filePath): \Generator
            {
                foreach ([['email', 'name'], ['a@example.test', 'Ada'], ['b@example.test', 'Bob']] as $i => $cells) {
                    ++$this->recordsRead;

                    yield $i + 1 => $cells;
                }
            }
        };

        $headers = new HeaderInspector()->peek($reader, 'irrelevant.csv');

        self::assertSame(['email', 'name'], $headers);
        self::assertSame(1, $reader->recordsRead);
    }

    public function testPeekingAnEmptyFileIsRefusedByName(): void
    {
        $reader = new class implements TabularReaderInterface {
            public function code(): string
            {
                return 'fake';
            }

            public function supports(string $filePath): bool
            {
                return true;
            }

            public function read(string $filePath): \Generator
            {
                yield from [];
            }
        };

        $this->expectException(UnreadableFileException::class);
        $this->expectExceptionMessage('dataflow.import.error.empty_file');

        new HeaderInspector()->peek($reader, 'irrelevant.csv');
    }
}
