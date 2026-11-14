<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import\Web;

use Jul6Art\DataflowBundle\Import\Web\UploadedImportStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadedImportStoreTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/dataflow-store-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    public function testAStoredFileIsFoundAgainByItsNameOnly(): void
    {
        $store = new UploadedImportStore($this->directory);

        $name = $store->store($this->upload("name\napple\n"));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.csv$/', $name);
        self::assertSame($this->directory.'/'.$name, $store->pathOf($name));
        self::assertSame("name\napple\n", file_get_contents($store->pathOf($name)));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function forgedNames(): iterable
    {
        yield 'a path out of the directory' => ['../../.env'];
        yield 'an absolute path' => ['/etc/passwd'];
        yield 'a name with a directory' => ['sub/'.str_repeat('a', 32).'.csv'];
        yield 'another extension' => [str_repeat('a', 32).'.php'];
        yield 'upper case' => [str_repeat('A', 32).'.csv'];
        yield 'not a string' => [['../../.env']];
        yield 'nothing' => [null];
    }

    #[DataProvider('forgedNames')]
    public function testANameThatCannotBeOneOfTheStoresFilesDesignatesNothing(mixed $name): void
    {
        self::assertNull(new UploadedImportStore($this->directory)->pathOf($name));
    }

    public function testAWellFormedNameOfAFileThatIsNotThereDesignatesNothing(): void
    {
        self::assertNull(new UploadedImportStore($this->directory)->pathOf(str_repeat('a', 32).'.csv'));
    }

    public function testAFileIsDiscarded(): void
    {
        $store = new UploadedImportStore($this->directory);
        $path = (string) $store->pathOf($store->store($this->upload("name\n")));

        $store->discard($path);

        self::assertFileDoesNotExist($path);
    }

    public function testAnUploadSweepsTheFilesAbandonedForADayAndKeepsTheOthers(): void
    {
        $store = new UploadedImportStore($this->directory);
        $abandoned = $this->directory.'/'.str_repeat('a', 32).'.csv';
        $recent = $this->directory.'/'.str_repeat('b', 32).'.csv';
        $foreign = $this->directory.'/notes.csv';
        foreach ([$abandoned, $recent, $foreign] as $file) {
            file_put_contents($file, 'x');
        }
        touch($abandoned, time() - 2 * 86400);
        touch($foreign, time() - 2 * 86400);

        $store->store($this->upload("name\n"));

        self::assertFileDoesNotExist($abandoned);
        self::assertFileExists($recent, 'An import someone is still mapping.');
        self::assertFileExists($foreign, 'A file the store did not name is none of its business.');
    }

    private function upload(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-upload-');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'fruits.csv', 'text/csv', null, true);
    }
}
