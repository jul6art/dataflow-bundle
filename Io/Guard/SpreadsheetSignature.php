<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Guard;

/**
 * Recognises a binary workbook by its first bytes, where neither the file name nor the MIME type
 * can be trusted.
 *
 * ## Why the signature and not the MIME type
 *
 * An upload's MIME type is GUESSED from the content by `UploadedFile::getMimeType()`, and for a
 * real binary `.xls` it is `application/vnd.ms-excel` — which has to stay in a CSV import's allow
 * list, because browsers send it for a `.csv` produced by Excel too. So a `.xls` workbook walks
 * straight through the list, the CSV reader reads binary bytes, no header matches the column
 * mapping, and the user is left with "imported: 0, skipped: 4 812" and no way to know why.
 *
 * ⚠️ **An `.xlsx` did not reach that case**: its guessed MIME is not in the list, so it was already
 * refused — but with a generic "unrecognised format" that does not say what to do. Checking the
 * signature BEFORE the MIME therefore serves two ends: closing the `.xls` hole, and making the
 * message useful for both.
 *
 * ## What this class does not do
 *
 * It does not say "this is an xlsx": a ZIP is a ZIP, and a `.docx` carries the same signature. It
 * answers one question — "are these bytes a binary container rather than text?" — and that is all a
 * text reader needs in order to refuse usefully.
 */
final class SpreadsheetSignature
{
    /**
     * How many bytes to read to decide. The longest signature is eight; reading more says nothing
     * more.
     */
    public const int HEAD_BYTES = 8;

    /**
     * The two containers a user sends instead of a CSV.
     *
     * @var list<string>
     */
    private const array SIGNATURES = [
        "PK\x03\x04",                        // ZIP → xlsx, xlsm, ods
        "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1",  // OLE2 → xls
    ];

    /**
     * @param string $head the file's first bytes, as read — at least {@see self::HEAD_BYTES}, but a
     *                     shorter or empty string is accepted and answers false
     */
    public static function matches(string $head): bool
    {
        foreach (self::SIGNATURES as $signature) {
            if (\str_starts_with($head, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads just enough of a file to answer, without loading it.
     *
     * ⚠️ An unreadable file answers `false` rather than throwing: this is a *recognition* helper,
     * and whether a missing file is an error is the caller's question, not this one's. The reader
     * that follows will fail with a message about the file, which is the useful one.
     */
    public static function matchesFile(string $filePath): bool
    {
        $handle = @\fopen($filePath, 'rb');

        if (false === $handle) {
            return false;
        }

        try {
            $head = \fread($handle, self::HEAD_BYTES);
        } finally {
            \fclose($handle);
        }

        return false !== $head && self::matches($head);
    }
}
