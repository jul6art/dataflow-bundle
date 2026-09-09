Tabular import, export and report engine for Symfony
====================================================

Everything a management application needs to move tabular data across its own boundary: writing a
CSV, an XLSX or a JSON stream, reading one back, and running a user-composed report over Doctrine
entities.

It is extracted from two production applications that between them wrote tabular output in
**eleven** places, in **four** different dialects, and guarded **none** of them against spreadsheet
formula injection. This layer exists so that those three numbers become one, one and one.

> This bundle deliberately does **not** depend on API Platform. A report is a `QueryBuilder`, not a
> resource; exposing saved reports over HTTP is the application's business, and the API side of
> synchronising data belongs to `jul6art/api-bundle`.

Requirements
------------

- PHP ^8.5
- Symfony ^7.4 || ^8.0

Installation
------------

```shell
composer require jul6art/dataflow-bundle
```

Then register it in `config/bundles.php` (Flex does this for you):

```php
Jul6Art\DataflowBundle\DataflowBundle::class => ['all' => true],
```

Configuration
-------------

```yaml
# config/packages/dataflow.yaml
dataflow:
    # Leaves the bundle installed and inert when false.
    enabled: true
```

`dataflow.enabled` is also exposed as a container parameter.

Usage
-----

### Writing tabular output

A writer takes a header, an **iterable** of rows, and a callback that receives chunks. Nothing is
buffered: the first bytes reach the client while the last row is still being produced.

```php
use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Writer\CsvWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

$writer = new CsvWriter(CsvDialect::excelFr());

$response = new StreamedResponse(static function () use ($writer, $invoices): void {
    $writer->write(
        ['number', 'customer', 'total'],
        (static function () use ($invoices): \Generator {
            foreach ($invoices as $invoice) {          // a Doctrine `toIterable()`, ideally
                yield [$invoice->getNumber(), $invoice->getCustomer()->getName(), $invoice->getTotal()];
            }
        })(),
        static function (string $chunk): void { echo $chunk; },
    );
});
$response->headers->set('Content-Type', $writer->contentType());
```

⚠️ **Pass a `Generator`, not an array.** The signature says `iterable` and the writers honour it,
but a caller that materialises the rows first defeats the whole layer — and no content assertion
will ever show it, because every row is correct. That exact defect shipped: a runner with a 50 000
row ceiling held two complete copies of its result set before emitting a byte. If you write a
regression test for this, assert the **type** (`assertInstanceOf(\Generator::class, …)`) or the
interleaving, never the content. `CsvWriterTest::testRowsAreConsumedLazily()` shows one way.

### Choosing a dialect

| | Separator | BOM | Line ending | Use it for |
| --- | --- | --- | --- | --- |
| `CsvDialect::excelFr()` | `;` | yes | CRLF | a file a French user will double-click |
| `CsvDialect::rfc4180()` | `,` | no | CRLF | a file another program parses |
| `CsvDialect::tabSeparated()` | TAB | no | LF | an imposed format, e.g. the French FEC |

⚠️ **`bom: true` is what makes Excel read UTF-8 at all.** Without it Excel FR falls back to
Latin-1 and « prénom » renders « prÃ©nom ». And conversely: a mark a *parser* does not expect
becomes part of the first column's name, which is why `rfc4180()` omits it. One export of this
ecosystem shipped without a mark while six others had one, and only a human eye caught it.

⚠️ **`escape` defaults to `''`, and that is not about silencing a PHP 8.4 deprecation.** PHP's own
default is the backslash, which is not CSV — the standard doubles a quote. With the default, a
field ending in a backslash escapes the closing quote and swallows the separator, so the reader
loses a column boundary.

Anything other than the three presets is a deliberate exception, and constructing a `CsvDialect`
by hand reads as one at the call site.

### Formula injection is handled for you

Every cell of every row **and of the header** goes through `Io\Guard\FormulaInjectionGuard` before
it is written. You do not call it. Two things are still worth knowing, because both cost this
ecosystem real debugging:

⚠️ **A negative amount is not a formula.** A Doctrine `decimal` column is hydrated as a *string*,
so `-100.00` starts with a forbidden character. The guard tests `is_numeric()` **before** looking
at the prefix — widened to the French separator, since `-100,00` is not numeric to PHP. Without
that, the guard text-marks every negative amount of an accounting file: a security fix that
corrupts the data it protects, noticed only by the accountant, while reconciling.

⚠️ **The header is not trusted input.** In a report builder a column label is typed by the user, so
guarding only the body is the mistake that looks harmless.

If you add your own writer, implement `Io\TabularWriterInterface` and call
`FormulaInjectionGuard::neutralizeRow()` on every row you emit. The guard is idempotent, so a row
that goes through it twice is unchanged the second time.

Quality assurance
-----------------

```shell
composer qa            # cs-check + rector-check + phpstan (level max) + phpunit
```

Run `composer qa`, not the single tool you have in mind: the CI's "Coding standards" job runs
Rector too, and its `lowest deps` job installs the minimum of every constraint — which is where
this ecosystem has repeatedly found what a local run could not.

`extra.symfony.require` states which Symfony line this bundle targets; the CI enforces it with
`SYMFONY_REQUIRE` on both the highest and the lowest job. A local `composer install` may still
resolve a newer Symfony, which broadens what you exercise rather than narrowing it — but it means
the toolchain can propose something that only makes sense on one branch. `rector.php` skips one
such rule already, with the reason written next to it.

Whatever you do, keep the code free of classes that exist on only one of the declared branches.
A bundle promising `^7.4 || ^8.0` has to hold both.

License
-------

This bundle is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

&copy; 2026 [Jul6Art](https://devinthehood.com/)
