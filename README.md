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
    # Leaves the bundle installed and inert when false — no services at all, not merely no feature.
    enabled: true

    # The catalogue the bundle's own keys are looked up in. Never `messages`.
    translation_domain: dataflow

    # The Stimulus identifier the report builder answers to. It decides the data-attribute prefix
    # the shipped partial emits, so it has to match how you registered the controller.
    stimulus_identifier: dataflow--report-builder

    # Applied when no LimitsProviderInterface is bound. These are the defaults.
    limits:
        report_rows: 1000            # rows a run returns when the caller asks for no limit
        export_rows: 50000           # rows one export may contain
        exports_per_hour: 30         # exports one actor may run per hour
        export_rows_per_hour: 10000  # row budget one actor may export per hour
        import_rows: 10000           # rows one imported file may contain
        imports_per_hour: 5          # imports one actor may run per hour
        field_max_depth: 2           # toOne relations the field catalogue walks; 0 = root only
```

⚠️ **Every ceiling is also a container parameter** — `%dataflow.limits.export_rows_per_hour%` and
its six siblings. That is not a convenience: the reference application wrote its row budget
`10000` twice, once as a `rate_limiter.yaml` bucket size and once in the PHP that subtracted from
it, with nothing linking them. Changing the YAML made the arithmetic wrong in silence. Read the
parameter in both places and there is one number:

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        report_export_per_user:
            policy: sliding_window
            limit: '%dataflow.limits.export_rows_per_hour%'
            interval: '1 hour'
```

⚠️ **That works whatever order your `config/bundles.php` lists.** The parameters are published from
the extension's `prepend()`, which runs for every bundle before any `load()` — so they exist by the
time FrameworkBundle reads its own configuration. Published from `load()` (as v1.0.1 did) the
placeholder failed with *"You have requested a non-existent parameter … while loading extension
framework"*, and it would have *worked* for a consumer that happened to register this bundle first.

Ports
-----

Three seams where the bundle stops and the application starts. Two have a working default, so a
consumer that binds nothing still has a usable bundle; the third has none, on purpose.

| Port | Default | Bind your own when |
| --- | --- | --- |
| `LimitsProviderInterface` | `ConfiguredLimitsProvider` — the configuration above, the same for everyone | ceilings vary by tenant, plan or quota |
| `ExportAuditorInterface` | `NullExportAuditor` — exports are not journalled | you have `audit-bundle`, or any audit trail |
| `ReportDefinitionStoreInterface` | **none** — saved reports are ephemeral | reports must survive the session |

```yaml
# config/services.yaml
services:
    Jul6Art\DataflowBundle\Port\ReportDefinitionStoreInterface: '@App\Report\DoctrineReportStore'
```

⚠️ **The store has no default because there cannot be one.** A saved report is a row with an owner,
a tenant, a visibility and a lifecycle; shipping an entity for it would force one tenancy model on
every consumer. *A bundle interprets, a project persists.*

⚠️ **A store keeps the raw payload, never a `ReportSpec`.** A spec is valid against today's
catalogue; a saved report has to survive tomorrow's. The payload is re-interpreted on every load by
the same `ReportSpecInterpreter` a fresh screen uses — an unknown column is dropped, a missing
entity refused. That is a security property as much as a robustness one: a definition saved when its
author could read `customer.email` must not still expose it after the permission is revoked.

⚠️ **An `ExportAuditorInterface` must not throw.** An audit trail that can fail the thing it
observes turns a full log table into an outage of the export feature.

⚠️ **`ExportRecord::$rows` is known only AFTER the response has streamed** — that is what streaming
means. An auditor called before the first byte records every export as zero rows, and the row count
is the single most useful field in an export trail: it is what distinguishes a normal export from an
exfiltration.

### What needs Doctrine, and what does not

`ReportRunner`, `FieldCatalog` and `ImportRunner` need an `EntityManagerInterface`. This bundle
requires `doctrine/orm` — the library — and deliberately **not** `doctrine/doctrine-bundle` — the
integration, which is what registers that service. In an application without it those three
services are removed by a compiler pass rather than left dangling, so the `Io/` half still works:
writing a CSV from an array needs no ORM.

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

### Resolving a format code to a writer

```php
public function __construct(
    #[AutowireIterator(tag: 'dataflow.tabular_writer')]
    private readonly iterable $writers,
) {}

private function writer(string $format): TabularWriterInterface
{
    foreach ($this->writers as $writer) {
        if ($writer->code() === $format) {
            return $writer;
        }
    }

    throw new \InvalidArgumentException(...);
}
```

The three writers carry `dataflow.tabular_writer`, `CsvReader` carries `dataflow.tabular_reader`,
and your own implementation of either interface is autoconfigured onto the same tag — a fixed-width
format a customer imposes joins the iterator by existing.

⚠️ v1.0.x shipped them untagged, so the first consumer had to hand-roll the list of three. That is
the duplication this bundle exists to remove.

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

### The fields endpoint

The screen asks your application for the columns it may offer, and the answer must be serialised
through `ReportField::toArray()`:

```php
return new JsonResponse([
    'fields' => array_map(
        static fn (ReportField $field): array => $field->toArray(),
        $fields->listFor($rootFqcn, $user, $limits->fieldMaxDepth)
    ),
]);
```

⚠️ **Do not hand-roll that array.** In v1.0.0 the catalogue produced `traversed` and the shipped
controller read `relation` — a name inherited from the application it was extracted from — so the
field sort compared `undefined` to `undefined`, the branch never fired, and every column behind a
relation sorted in among the root's own. Nothing failed: a missing property is not an error in
JavaScript, and no test crossed the boundary. `toArray()` and `ReportFieldWireShapeTest` are what
close it.

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
catalogue entry per look-up table would make the catalogue unusable. To refuse one of those anyway,
bind a `RelationPolicyInterface`:

```yaml
Jul6Art\DataflowBundle\Report\Catalog\RelationPolicyInterface: '@App\Report\UnreportableRelations'
```

⚠️ **A `FieldPolicyInterface` cannot do this.** It is asked about a scalar, so denying every field
of the target leaves the walk running and `organization.owner.email` is still offered. The first
consumer of this bundle had removed `organization` from its reportable relations deliberately, and
the extraction put it back — not a cross-tenant leak, the rows stay scoped, but a relation somebody
had decided not to expose, exposed again, in silence.

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

⚠️ **A report mixes three kinds of message**, and the shipped partial handles all three: this
bundle's keys, your mapper's keys (in YOUR domain), and a validator's already-rendered text. Each is
translated in the domain its own first segment names — translating them all in `dataflow` printed a
consumer's keys raw on the page, which is what v1.3.2 fixes. If you render the report yourself, do
the same.

⚠️ **A dry run is not a rollback.** Nothing is persisted, so no trigger fires and no sequence
advances — and a constraint only the database knows about is not caught. What it does catch is
every row the mapper or the validator would reject.

### A spreadsheet uploaded instead of a CSV

`CsvReader` refuses it by name — `dataflow.import.error.binary_spreadsheet` — instead of reading
its bytes as text. Reading XLSX is a later lot; until then the message tells the user what to do.

⚠️ Detection is by CONTENT, never by MIME type: a browser sends `application/vnd.ms-excel` for a
CSV saved out of Excel, so a MIME allow list that admits it admits real `.xls` workbooks too.

The screens
-----------

The bundle ships the **body** of the report builder and of an import's mapping and result panels —
not the pages. A page carries a layout, a title, a menu entry, a permission check and a breadcrumb,
every one of which is the application's.

### 1. Register the controller and the stylesheet

```js
// assets/bootstrap.js — or however your build registers controllers
import ReportBuilder from '@jul6art/dataflow-bundle/controllers/report_builder_controller';

app.register('dataflow--report-builder', ReportBuilder);
```

⚠️ **If your build derives identifiers from a PATH instead — `@symfony/stimulus-bundle` and
`startStimulusApp()` do — name the relay file with a DASH.** The derivation replaces `/` with `--`
and strips `_controller.js`; it does **not** turn underscores into dashes. So
`assets/controllers/dataflow/report_builder_controller.js` registers
`dataflow--report_builder`, while this bundle's partial emits `dataflow--report-builder`. The
controller then loads, registers, and attaches to nothing: no console error, no 404, inert buttons,
and a green test suite — the third consumer of this bundle lost an hour to it. Either name the file
`report-builder_controller.js`, or set `stimulus_identifier` to whatever your build actually
produces. A test worth having reads the `dataflow.stimulus_identifier` parameter and asserts a relay
of that name exists.

```css
@import '@jul6art/dataflow-bundle/styles/dataflow.css';
```

> ⚠️ **Add this bundle's `assets/` to Tailwind's `content`.** A class used only in the bundle's
> JavaScript is otherwise purged from the production stylesheet — and only from that one, which is
> the worst place to find out.

⚠️ **The stylesheet uses Tailwind's default palette only — `sky` is its accent.** A bundle cannot
apply `bg-primary-50`: that name exists in one application's theme and nowhere else, and Tailwind
does not warn — it fails **your** build with *"The `bg-primary-50` class does not exist"*, pointing
at a file in `vendor/` you did not write. (v1.1.0 shipped exactly that.) If you have a brand colour,
re-declare the three rules marked `ACCENT` after the import:

```css
@import '@jul6art/dataflow-bundle/styles/dataflow.css';

.step-tab.is-current { @apply bg-primary-50 dark:bg-primary-900/20 border-primary-300 dark:border-primary-700 text-primary-700 dark:text-primary-300; }
.step-tab.is-current .step-tab-bullet,
.step-tab.is-done .step-tab-bullet { @apply bg-primary-500 text-white; }
.dataflow-modal-icon { @apply bg-accent-100 dark:bg-accent-900/40 rounded-full p-2.5 flex-shrink-0 text-xl text-accent-600; }
```

The markup also uses `jul6art/ui-bundle`'s utility classes: `form-panel`, `form-section-title`,
`form-fieldset`, `form-control`, `btn-primary`, `btn-secondary`, `panel`.

### 2. Hand the controller your translator

```js
// assets/app.js
import { trans } from './translator';
import { registerTranslator } from '@jul6art/core-bundle/i18n/registry';

registerTranslator((key, parameters) => trans(key, parameters, 'javascript'));
```

⚠️ **A controller shipped inside `vendor/` cannot import your `assets/translator.js`** — the
relative path out of `vendor/` does not exist, and hard-coding one would tie the bundle to one
application's layout. So the application hands its translator over once, at boot, and every bundle
reads through the registry.

⚠️ **Never through an HTML attribute.** Posting a translation tree into `data-…-translations-value`
is how labels used to reach JavaScript here; it measured 8.7 kB of escaped HTML per page and was
removed.

### 3. Include the partial

```twig
{{ include('@Dataflow/report/_builder.html.twig', {
    entities:   report_entities,
    fields_url: path('app_report_builder_fields'),
    run_url:    path('app_report_builder_run'),
    export_url: path('app_report_builder_export'),
    save_url:   path('app_report_builder_save'),
    load_url:   path('app_report_builder_load', {id: '__ID__'})|replace({__ID__: '{id}'}),
    can_share:  is_granted('report:builder:share'),
}) }}
```

⚠️ **Every endpoint is a parameter, and `load_url` must contain the literal `{id}`.** The
implementation this is extracted from hard-coded four URLs, so the controller could only ever live
in one application mounted under one prefix — and it patched an id into the fifth with a regular
expression, because the route had been generated with `id: 0`.

⚠️ **`can_share` is a boolean you compute.** The bundle must not call `is_granted()` with a
permission code it invented: the codes are yours, and a guessed one answers false everywhere, which
hides the control on every screen and looks like a broken feature. Hiding it is not the guard
either — the payload carries `shareScope`, so the server has to refuse it too.

#### What the five endpoints exchange

The shipped controller posts `multipart/form-data` and reads JSON. Getting a field name wrong here
fails **silently**, so the wire is worth writing down:

| Endpoint | It sends | It reads back |
| --- | --- | --- |
| `fields_url` | `?entity=<FQCN>` on a GET | `{fields: [ReportField::toArray(), …]}` |
| `run_url` | `entity`, `columns`, `filters` (the last two JSON-encoded), optional `limit`/`offset` | `{columns: [{label, path}], rows: [...]}` |
| `export_url` | the same, plus `format` (a writer's `code()`) | the file itself |
| `save_url` | the same, plus `name`, `shareScope`, and `id` when updating | `{id: <the stored id>}` |
| `load_url` | nothing — the id is in the path | `{id, name, entity, columns, filters, shareScope}` |

⚠️ **`shareScope` is the string `private` or `organization`, in BOTH directions.** It is not a
boolean, and the name is inherited from the first consumer, which had three scopes. An application
storing a boolean converts at those two points and nowhere else — and reading `shared` off the
request instead makes every save private, with no error, because an absent boolean is `false`.

⚠️ **The auto-load entry point is `?load=<id>` on the page itself.** The controller reads it at
connect and fetches `load_url`, which is how a saved-reports table hands a report over — and it
makes the link shareable. The bundle ships no list of saved reports: that is a screen, and screens
are the application's.

⚠️ **The controller reads only `id` from the save response.** Anything else you return is dead
payload.

⚠️ **No CSRF token is sent, and you should know it before you mount these routes.** The three POST
endpoints carry no token today. `run` and `export` leak nothing across origins — a cross-site POST
cannot read a JSON body or a download — so what is actually exposed is `save`: a forged request can
create a report in the victim's account, or overwrite one by guessing a sequential id. That is a
nuisance rather than a disclosure, which is why it has not held up a release; it is written here so
that nobody assumes a protection that is not there. Until the bundle carries a token the way
`datatable-bundle` does (the application names the token id, the shipped controller sends it), an
application whose policy requires one should not mount `save_url` — the other four endpoints are
usable without it.

### 4. Wire the busy state, if you have an overlay

```js
document.addEventListener('dataflow:busy', (e) => { /* your loader, on e.detail.element */ });
document.addEventListener('dataflow:idle', (e) => { /* take it down */ });
```

⚠️ The controller dispatches events rather than calling a loader mixin. The version it replaces
imported one from `datatable-bundle` — which would have made a report builder depend on a datatable
library for a spinner. The application decides what busy *looks* like; the controller only says
when it is.

### The import panels

```twig
{{ include('@Dataflow/import/_mapper.html.twig', {inspection: inspection, fields: mapper.fields()}) }}
{{ include('@Dataflow/import/_report.html.twig', {report: report}) }}
```

The mapper emits no `<form>`, no CSRF token and no submit button: the action, the token name and the
route are yours.

⚠️ **Its fields are named `mapping[<index>]`, by column index.** A name-keyed mapping loses one of
two columns called `email`, and the import then reads a plausible wrong column for every row.

### Translation keys

Every key starts with `dataflow.` and the bundle ships the English catalogue. Two families are read
through a variable and so are invisible to a scanner — the twelve filter operator labels and the
four step labels — and `DeclaredTranslationKeys` names them for your guard:

```php
protected static function declaredKeys(): array          // the BROWSER's catalogue
{
    return static::getContainer()->get(DeclaredTranslationKeys::class)->keys();
}
```

⚠️ **`keys()` is what the browser reads; `templateKeys()` is what the server renders.** They are
different catalogues — this ecosystem exposes exactly one domain to JavaScript, so a key the browser
needs is *moved* into it — and one list conflated them until v1.3.0: the first consumer's JavaScript
guard reported the four step labels as missing from its browser catalogue, which they legitimately
are. They come from a Twig partial.

⚠️ **And point your JavaScript guard at this bundle's `assets/` too.** The controller lives in
`vendor/`, so a guard that scans only the project's own `assets/` sees forty `dataflow.*` keys
translated by the project and read by nothing — and reports them dead.

⚠️ **A ternary goes outside the lookup, never inside it.** A condition in the argument position
hides both keys from a scanner, so a catalogue clean-up deletes entries the screen renders. The
screen this is extracted from displayed **thirty-four raw keys** across its whole surface, in
production, with every test green — because each half was only ever asserted against itself.

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
