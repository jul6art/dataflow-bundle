<p align="center">
    <a href="https://devinthehood.com"><img src="https://github.com/jul6art/symfony-skeleton-generator/blob/master/public/img/logo.png?raw=true" alt="logo dev in the hood" width="400"></a>
</p>

<p align="center">
    <a href="https://opensource.org/licenses/MIT" target="_blank"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License"></a>
    <img src="https://img.shields.io/static/v1?label=stable&message=v1&color=orange" alt="Version">
</p>

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

### The other two writers

`XlsxWriter` and `JsonWriter` implement the same contract, so a caller swaps one for another by
changing a single constructor call — that is what the `code()` / `contentType()` /
`fileExtension()` triplet is for.

⚠️ **A workbook cannot be streamed to the client.** A `.xlsx` is a ZIP archive whose central
directory is written last, so no prefix of the file is a valid document: `XlsxWriter` buffers to a
temporary file as rows arrive — the row source is still consumed lazily — then emits the finished
file **in 64 KiB chunks**. Emitting it as one string, which the version this replaces did, puts the
whole workbook back into a single PHP string and undoes the memory discipline of everything above.

⚠️ **`JsonWriter` deliberately does NOT apply the formula guard.** No spreadsheet opens its output,
so prefixing a value with an apostrophe would corrupt the payload of the only consumer there is. It
does use `JSON_THROW_ON_ERROR`: with `json_encode`'s default, a malformed UTF-8 byte out of a legacy
column returns `false`, emits the empty string, and produces a syntactically valid document
silently missing a row — the worst available outcome.

### Serving it as a download

```php
use Jul6Art\DataflowBundle\Io\Http\TabularResponseFactory;

return $this->responses->stream(
    $writer,
    ['number', 'customer', 'total'],
    $rows,
    TabularResponseFactory::basename(['invoices', $organization->getSlug()]),
);
```

`basename()` builds the conventional `<subject>_<tenant>_<date>`, drops empty parts rather than
leaving a double separator, and sanitises each one.

⚠️ **The filename is sanitised as a security control, not for tidiness.** A tenant slug or a report
name reaches the `Content-Disposition` header from the database; a newline in it splits the HTTP
response and everything after the split is attacker-controlled. The five endpoints this factory
replaces did not agree on the matter: three sanitised, one hard-coded its filename, one did
neither.

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

### Running a report

A report is a `ReportSpec` — a root entity, columns, filters — run against Doctrine and streamed.

```php
use Jul6Art\DataflowBundle\Report\Spec\ReportSpecInterpreter;

$spec   = new ReportSpecInterpreter()->interpret($savedDefinition);   // your stored payload
$result = $runner->run($spec, $actor, limit: 50_000, scope: $this->scopeToTenant(...));

return $this->responses->stream($writer, $result->header(), $result->rows(), 'invoices');
```

⚠️ **`rows()` is a `Generator`, single-use, and uncountable.** Iterating twice throws; counting
means a second query. Both are deliberate — a caller that needs the rows twice runs the report
twice, so the cost is a decision rather than a default. Do not wrap it in `iterator_to_array()`
without meaning to: that is precisely the defect this replaced, where a runner held two complete
copies of a 50 000-row result set before emitting a byte.

⚠️ **Tenant scoping is yours.** The bundle does not know what a tenant is, so `run()` takes a
`$scope` closure receiving the query builder and the root alias. Inventing an `organization`
column here would fit one consumer and silently return everything for the others.

### Declaring what may be reported

Two gates, and they answer different questions.

```php
final class CrmReportableEntityProvider implements ReportableEntityProviderInterface
{
    public function entities(): array
    {
        return [
            Contact::class => new ReportableEntity('report.entity.contact', 'crm:contact:read', 'crm.manage'),
        ];
    }
}
```

Tag it `dataflow.report.entity_provider` — or better, a `_instanceof` block in `services.yaml`.

⚠️ **`#[AsTaggedItem]` alone does NOT add the tag**; it only indexes an item in an iterator that
already exists. An application relying on it gets an empty iterator and a runtime failure.

⚠️ **The feature is nullable, and the checker is optional.** Feature flags say what a tenant
*bought*; permissions say what a person *may do*. A single-product application has no feature
system: it declares `null`, binds no `FeatureCheckerInterface`, and only the permission gate
applies. The bundle's compiler pass nulls the contract rather than letting the container fail to
compile — which is what an unconditional constructor argument would do, and what two bundles of
this ecosystem shipped before.

⚠️ **The permission gate is never optional.** A report engine without a per-entity gate is a
cross-tenant exfiltration tool.

### What the field catalogue refuses, and why

| Refused | Because |
| --- | --- |
| a `toMany` relation | one invoice with four lines returns four rows; an export of a thousand silently multiplies |
| a globally denied name (`password`, `apiToken`, …) | once in a spreadsheet is once too often; the list is code, not configuration |
| a catalogued target the actor may not read | otherwise `invoice.customer.email` is granted by `invoice:read` alone |
| whatever a `FieldPolicyInterface` narrows | a global name list cannot tell a name sensitive on one entity from the same name on another |

An *uncatalogued* target — a referential, a country, a unit — is traversed freely: demanding a
catalogue entry per look-up table would make the catalogue unusable.

⚠️ **The catalogue lists selectable SCALARS.** `customer` alone is not a path; a null check on a
relation goes through its identifier (`customer.id IS NULL`).

Importing
---------

### Reading a file

```php
use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Reader\CsvReader;

$reader = new CsvReader(CsvDialect::excelFr());

foreach ($reader->read($path) as $record => $cells) {
    // $record is 1-based and the header is record 1
}
```

A reader carries its dialect in its constructor, exactly as a writer does, and yields a
`Generator` — reading a 40 MB file costs one record of memory.

⚠️ **The key is a RECORD number, not a line number.** A quoted field may contain newlines, so one
record can span several lines of the file. A blank line yields nothing and still consumes its
number, so every later number keeps pointing at the right row.

### The mapping screen

```php
use Jul6Art\DataflowBundle\Import\HeaderInspector;

$inspector = new HeaderInspector();
$headers = $inspector->peek($reader, $path);          // reads ONE record
$inspection = $inspector->inspect($headers, $mapper->fields());

$inspection->mapping;     // [0 => 'firstName', 2 => 'email'] — column INDEX → field
$inspection->ambiguous;   // indices whose header collides with another's
$inspection->unknown;     // indices no field matched — normal, not an error
$inspection->missing;     // fields no column supplied
```

`First Name`, `first_name`, `FIRSTNAME`, `Prénom` and `Email *` all match: headers and field keys
are reduced to lower-case alphanumerics, accents folded through an explicit table, before being
compared.

⚠️ **The mapping is keyed by column INDEX, not by header name.** A file with two columns both
called `email` collapses into one entry in a name-keyed map and the second silently wins — the
import then reads the wrong column and every row is subtly wrong rather than obviously broken.

⚠️ **A collision is reported, never resolved.** Both columns are left out of the suggestion so the
screen can ask. And matching stops at exact-after-normalisation: `Email *` matches, `Job Title
(optional)` does not, because substring matching would suggest the e-mail column for `Email
(invalid)` — and a suggestion the user accepts without reading is worse than no suggestion.

### Running an import

```php
use Jul6Art\DataflowBundle\Import\ImportRunner;
use Jul6Art\DataflowBundle\Import\Spec\ImportSpec;

$spec = new ImportSpec($path, $inspection->mapping, dryRun: true);
$report = $runner->run($spec, $mapper, $reader, $resolver);

$report->imported();            // rows that would land — see isDryRun() before wording this
$report->skipped();
$report->errorCount();          // exact
$report->errors();              // the first 100
$report->errorsWereTruncated();
```

The application supplies two things and the engine does the rest:

| You write | Why it cannot be configuration |
| --- | --- |
| `RowMapperInterface` | "what does a row of this file mean for this entity" is business: a tenant to attach, a default status, a referential to look up |
| `DuplicateResolverInterface` *(optional)* | only your schema knows what makes two records the same |

### The five traps the runner handles for you

| Trap | What it does |
| --- | --- |
| the unit of work growing with the file | `detach()`es what it persisted after each flush — **not** `clear()`, which would detach the caller's own tenant and make the next flush raise *"A new entity was found through the relationship"* |
| one `SELECT` per row | `findExisting()` takes the whole batch and is expected to answer in one query |
| two identical rows in one file | neither is in the database when the batch is queried, so both would be persisted and the flush would die on the unique index — `keyOf()` lets the runner skip the second |
| a dry run that disagrees with the run | the in-file duplicate is counted the same way in both, so the preview does not lie |
| a batch failing halfway | `atomic: true` (the default) wraps the run in one transaction; `ImportFailedException` carries the partial report and says whether it was rolled back |

⚠️ **A mapper throws `\DomainException` with a TRANSLATION KEY as its message.** The runner catches
it and puts it in the report next to the record number; an uncaught exception would end the import
on one bad row.

⚠️ **A validation error is already-rendered text, not a key** — the validator translates its own
messages. Pass every report message through the translator, which returns an unknown key unchanged;
that one call is right for both cases.

⚠️ **A dry run is not a rollback.** Nothing is persisted, so no trigger fires and no sequence
advances — and a constraint only the database knows about is not caught. What it does catch is
every row the mapper or the validator would reject.

### A spreadsheet uploaded instead of a CSV

`CsvReader` refuses it by name — `dataflow.import.error.binary_spreadsheet` — instead of reading
its bytes as text. Reading XLSX is a later lot; until then the message tells the user what to do.

⚠️ Detection is by CONTENT, never by MIME type: a browser sends `application/vnd.ms-excel` for a
CSV saved out of Excel, so a MIME allow list that admits it admits real `.xls` workbooks too.

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
