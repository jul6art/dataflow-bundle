# Security Policy

## Supported versions

`jul6art/dataflow-bundle` is installed by other applications through Composer, so a fix here
reaches them the moment they update. Only the current major line gets one.

| Version | Supported |
| --- | --- |
| `1.x` | ✅ |
| any older tag or fork | ❌ |

Support means security fixes on the latest release of that line — upgrade to it before
reporting, in case the problem is already gone.

## What is in scope

This bundle reads files a user uploaded and writes files another user opens, and it lets a
user compose a query. All three are attack surfaces:

* **Spreadsheet formula injection** — any path that writes a cell beginning with `=`, `+`,
  `-`, `@`, a tab or a carriage return without neutralising it. This is the bundle's
  founding defect; a regression here is a vulnerability, not a bug.
* **Injection through a user-composed report** — an entity, property, join, aggregate,
  ordering or filter taken from request input and reflected into DQL or SQL without going
  through the allow-list.
* **A report or export returning rows the caller may not read** — the access decision
  skipped because the read goes through a `QueryBuilder` rather than a controller.
* **Hostile input at import** — XML external entities or remote references in an XLSX, a
  compressed sheet that expands without bound, an unbounded row or column count, a formula
  evaluated while reading.
* **An import that writes outside its declared scope** — the parsed file choosing the target
  entity, the target row, or a field the port never declared.
* **Path traversal or file disclosure** through an import or export path, a temporary file
  left readable, or a generated file served without a check.

Out of scope: vulnerabilities in Symfony, Doctrine, API Platform or any other third-party
package — report those to the project that owns the code, and they will reach you through
your own `composer update`. Also out of scope: an application that misconfigures this bundle
in a way the README warns against, though a warning that turns out to be easy to miss is
worth an issue of its own.

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Use [GitHub's private vulnerability reporting](https://github.com/jul6art/dataflow-bundle/security/advisories/new)
(the **Security** tab → *Report a vulnerability*). It opens a draft advisory only
you and the maintainers can read, and it is the channel this project prefers —
no email address needs to be published for it to work.

Please include:

* the version of `jul6art/dataflow-bundle` and of Symfony you are running,
* the relevant part of your bundle configuration,
* the shortest reproduction you have — ideally a failing test against this
  repository, since that is what a fix will be built on,
* what an attacker gains: which check is bypassed, which data is read or
  written, and whether authentication is required.

## What to expect

* An acknowledgement within **7 days**.
* An assessment — accepted, out of scope, or needing more detail — within
  **14 days**.
* For an accepted report: a fix released on the supported line, a
  [security advisory](https://github.com/jul6art/dataflow-bundle/security/advisories)
  describing the impact and the version to upgrade to, and credit in it unless
  you ask otherwise.

Please give the maintainers a reasonable window to ship a release before disclosing
publicly. This project runs no bug-bounty programme and offers no payment.