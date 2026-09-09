<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Reader;

use Jul6Art\DataflowBundle\Exception\UnreadableFileException;
use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Reader\CsvReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvReader::class)]
final class CsvReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tearDown();
    }

    public function testRecordsAreNumberedFromOneWithTheHeaderIncluded(): void
    {
        $records = $this->read("email,name\na@example.test,Ada\nb@example.test,Bob\n");

        self::assertSame([
            1 => ['email', 'name'],
            2 => ['a@example.test', 'Ada'],
            3 => ['b@example.test', 'Bob'],
        ], $records);
    }

    /**
     * ⚠️ Three invisible bytes. Without this the first header is `\xEF\xBB\xBFemail`, it matches no
     * field, and the user is asked to map a column whose name looks exactly like the one they
     * expected.
     */
    public function testALeadingByteOrderMarkIsStrippedFromTheFirstCell(): void
    {
        $records = $this->read("\xEF\xBB\xBFemail,name\na@example.test,Ada\n");

        self::assertSame('email', $records[1][0]);
    }

    /**
     * ⚠️ And only from the first cell: a mark appearing anywhere else is data, not an encoding
     * marker, and silently deleting a byte from a value would be worse than keeping it.
     */
    public function testAMarkInsideTheFileIsLeftAlone(): void
    {
        $records = $this->read("email,name\n\xEF\xBB\xBFa@example.test,Ada\n");

        self::assertSame("\xEF\xBB\xBFa@example.test", $records[2][0]);
    }

    /**
     * ⚠️ The proof that the dialect's `escape` reaches `fgetcsv`. With PHP's default backslash, the
     * trailing backslash escapes the closing quote, the field swallows the separator, and `Ada` and
     * `x` merge into one cell — every remaining column of that record shifts by one.
     */
    public function testAValueEndingInABackslashDoesNotShiftTheNextColumn(): void
    {
        $records = $this->read("a,b,c\n\"path\\\",Ada,x\n");

        self::assertSame(['path\\', 'Ada', 'x'], $records[2]);
    }

    public function testAQuotedValueMayContainTheDelimiter(): void
    {
        $records = $this->read("a,b\n\"Doe, Ada\",x\n");

        self::assertSame(['Doe, Ada', 'x'], $records[2]);
    }

    /**
     * ⚠️ A blank line yields nothing and still consumes its number, so record 4 is the fourth line
     * of the file. Skipping it silently would point every later error at the wrong row.
     */
    public function testABlankLineConsumesItsRecordNumberAndYieldsNothing(): void
    {
        $records = $this->read("a,b\nx,y\n\nz,w\n");

        self::assertSame([1, 2, 4], array_keys($records));
    }

    public function testTheDialectDecidesTheDelimiter(): void
    {
        $records = $this->read("a;b\nx;y\n", CsvDialect::excelFr());

        self::assertSame(['x', 'y'], $records[2]);
    }

    public function testABinaryWorkbookIsRefusedByName(): void
    {
        $path = $this->file("PK\x03\x04".str_repeat("\x00", 40));

        $this->expectException(UnreadableFileException::class);
        $this->expectExceptionMessage('dataflow.import.error.binary_spreadsheet');

        iterator_to_array(new CsvReader()->read($path));
    }

    public function testSupportsRefusesAWorkbookAndAcceptsText(): void
    {
        $reader = new CsvReader();

        self::assertFalse($reader->supports($this->file("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1 rest")));
        self::assertTrue($reader->supports($this->file("a,b\n")));
    }

    public function testAMissingFileIsRefusedByName(): void
    {
        $this->expectException(UnreadableFileException::class);
        $this->expectExceptionMessage('dataflow.import.error.file_unreadable');

        iterator_to_array(new CsvReader()->read(sys_get_temp_dir().'/dataflow-absent-'.uniqid()));
    }

    /**
     * The streaming proof — a MEASUREMENT, because a type assertion cannot see the difference: a
     * body that builds the whole array and then `yield from`s it is still a generator function.
     *
     * ⚠️ **And the measurement has to be taken DURING the iteration.** Sampling after the loop
     * proves nothing either: finishing the `foreach` destroys the generator's frame, so the array
     * it had built is freed before the sample is taken. Measured on 1.6 MB, after the loop, the
     * streaming reader shows 728 bytes and the materialising one shows **0** — the defect looks
     * better than the fix. Sampled at record 10 the two are 10 KB against 2.9 MB.
     */
    public function testReadingDoesNotGrowWithTheFile(): void
    {
        $line = str_repeat('x', 200);
        $path = $this->file("a,b\n".str_repeat($line.','.$line."\n", 4000));

        gc_collect_cycles();
        $before = memory_get_usage();
        $seen = 0;
        $cost = 0;

        foreach (new CsvReader()->read($path) as $cells) {
            self::assertCount(2, $cells);

            if (10 === ++$seen) {
                $cost = memory_get_usage() - $before;
            }
        }

        self::assertSame(4001, $seen);
        self::assertLessThan(
            256 * 1024,
            $cost,
            \sprintf('Ten records into a 1.6 MB file the reader held %d bytes; it is buffering.', $cost),
        );
    }

    /**
     * A caller that stops early — a header peek, a `break` on the first bad row — must not leak the
     * handle, and this pins that it does not.
     *
     * ⚠️ **It does not prove the `finally` does the closing.** Removing `fclose` leaves this test
     * green: dropping the generator drops its frame, the handle's last reference goes with it, and
     * PHP closes the stream on refcount zero. The `finally` earns its place elsewhere — it closes
     * at the point of abandonment rather than whenever the last reference happens to fall, which
     * is not the same instant once a generator is held in a property or caught in a cycle waiting
     * for the collector. Said out loud here because a green test that names the wrong cause is how
     * the next person concludes the `finally` is redundant.
     */
    public function testAbandoningTheGeneratorDoesNotLeakTheHandle(): void
    {
        $path = $this->file("a,b\nx,y\nz,w\n");
        $before = \count(get_resources('stream'));

        $rows = new CsvReader()->read($path);

        foreach ($rows as $cells) {
            self::assertSame(['a', 'b'], $cells);

            break;
        }

        unset($rows);

        self::assertCount($before, get_resources('stream'));
    }

    /**
     * @return array<int, list<string>>
     */
    private function read(string $contents, ?CsvDialect $dialect = null): array
    {
        return iterator_to_array(new CsvReader($dialect ?? new CsvDialect())->read($this->file($contents)));
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-reader-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }
}
