<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where an uploaded file waits between the screens: a directory of the application, and a random name.
 *
 * ⚠️ **Only the NAME travels in the session, and it is checked before use.** Copies of the import screens kept the
 * file's PATH in the session and checked it was still under the temporary directory once canonicalised; a name of
 * thirty-two hexadecimal characters followed by `.csv` cannot leave this directory, so a forged session that
 * carries `../../.env` or an absolute path is refused as "no file" — there is nothing left to canonicalise.
 *
 * ⚠️ **A file is kept until the run succeeds**, not until it starts: a refused mapping comes back to its screen
 * without uploading five thousand rows again. What an operator abandons is swept on the next upload once it is a
 * day old, so the directory does not grow with every attempt nobody finished.
 */
final readonly class UploadedImportStore
{
    private const string NAME = '/^[0-9a-f]{32}\.csv$/';

    private const int ABANDONED_AFTER = 86400;

    public function __construct(
        private string $directory,
    ) {
    }

    /** Moves the uploaded file under its random name, and returns that name. */
    public function store(UploadedFile $file): string
    {
        $this->sweep();

        $name = bin2hex(random_bytes(16)).'.csv';
        $file->move($this->directory, $name);

        return $name;
    }

    /** The file's path, or `null` when the name cannot designate one of this store's files. */
    public function pathOf(mixed $name): ?string
    {
        if (!\is_string($name) || 1 !== preg_match(self::NAME, $name)) {
            return null;
        }

        $path = $this->directory.'/'.$name;

        return is_file($path) ? $path : null;
    }

    public function discard(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function sweep(): void
    {
        foreach (glob($this->directory.'/*.csv') ?: [] as $file) {
            if (1 === preg_match(self::NAME, basename($file)) && (int) @filemtime($file) < time() - self::ABANDONED_AFTER) {
                @unlink($file);
            }
        }
    }
}
