# Issue texts, ready to post

Post in this order: 1, then 2. Wait for maintainer feedback on 1 before opening any PR.
Issues 3 to 5 are follow-ups for other projects; post them once the DBAL design is accepted, or
earlier as a heads-up if you prefer.

Before posting:

* Replace `<RFC link>` with a permanent link to `RFC.md` (e.g. the blob URL of this branch at a
  fixed commit), or paste the RFC as a comment.
* The quotes from #5961 are taken from the brief. Check them against the thread.
* Fill in the issue number of 1 where 2 to 5 say `#RFC`.

---

## 1. doctrine/dbal: RFC

**Title:** RFC: opt-in fractional-second (microsecond) support for date/time types

```markdown
### Feature Request

|        Q        |   A
|---------------- | -----
| New Feature     | yes
| RFC             | yes
| BC Break        | no (one narrow exception, see below)

#### Summary

DBAL cannot round-trip a `DateTime` with microseconds through any built-in type, on any platform except
SQL Server's `datetime`. This has been requested since #1020 and attempted in #2873 and #5961. #5961 was
closed because it changed behaviour for existing users (notably on Oracle), covered only MySQL and
PostgreSQL, put platform-specific code into the wrapper classes, and relied on unwrapping the driver
stack. #6631 (a custom `TIMESTAMP(6)` type producing the same migration forever) is a related
symptom.

Before writing a PR, I'd like agreement on a design that avoids all four problems. Full RFC with
measurements: <RFC link>

#### What happens today (4.5.x, measured)

* Writes cut the fraction in PHP (`getDateTimeFormatString()` is `Y-m-d H:i:s`), and declarations pin
  precision to 0 (`TIMESTAMP(0)`, `DATETIME`, `TIME(0)`). The `precision` column option is ignored.
* `time*` and `datetimetz*` throw `InvalidFormat` when the database returns a fraction (PostgreSQL
  `TIME` without a modifier, `NOW()` defaults, SQL Server `TIME(7)`, hand-widened columns).
  `datetime*` only survive through the `new DateTime()` fallback, which accepts any `strtotime()` string.
* Schema managers do not read the fractional precision. SQL Server even reports the column length in
  characters (26) as `precision`. So a `DATETIME(6)` column compares as `DATETIME`.
* Databases disagree on excess digits. Writing `2026-12-31 23:59:59.999999` into a precision-0 column
  gives `2027-01-01 00:00:00` on MySQL 8.4, PostgreSQL 17, SQL Server 2022 and Oracle 23, and
  `2026-12-31 23:59:59` on MariaDB 11.4. Simply sending fractions to existing columns is therefore a
  BC break.
* On Oracle, with the session formats of `InitializeSession`, a fraction on write fails with
  ORA-01830 and is silently dropped on read.

#### Proposal: three PRs, each mergeable on its own

**A. Tolerant reads (bug fix, 4.5.x).** Every temporal type tries the platform format exactly as
today, and only on failure retries with the fraction accounted for (truncated to 6 digits). No new
API, no change to writes.

**B. Fractional-second precision as a column property (4.6.x).**
* Schema managers and metadata providers report it as `Column::getPrecision()`. Sources:
  `DATETIME_PRECISION`, the PostgreSQL type modifier, `sys.columns.scale`, `DATA_SCALE`,
  `SYSCAT.COLUMNS.SCALE`.
* `getDateTimeTypeDeclarationSQL()`, `getDateTimeTzTypeDeclarationSQL()` and
  `getTimeTypeDeclarationSQL()` honour an explicit precision. `null` keeps today's SQL byte for byte.
* Comparator: an unspecified (`null`) precision accepts whatever precision the other temporal column
  has. A hand-widened `DATETIME(6)` mapped as plain `datetime` is never narrowed, and a custom type
  rendering `DATETIME(6)` stops producing a diff (#6631).

**C. Opt-in types (4.6.x).** `datetime_precise`, `datetimetz_precise`, `time_precise` and their
`_immutable` variants:
* write `.u` via new `getDateTimePreciseFormatString()`, `getDateTimeTzPreciseFormatString()` and
  `getTimePreciseFormatString()`;
* declare precision 6 unless the column says otherwise.

Existing types never change their write format. On Oracle, `new InitializeSession(fractionalSeconds:
true)` opts the session into `FF6`.

Why new type names instead of passing the column to the conversion methods: types are flyweights and
never see the column. Changing that signature would touch every custom type. New names are additive.

| Platform | `datetime_precise` | `datetimetz_precise` | `time_precise` | excess digits |
|---|---|---|---|---|
| MySQL / MariaDB | `DATETIME(p)` | `DATETIME(p)` | `TIME(p)` | rounds / truncates |
| PostgreSQL | `TIMESTAMP(p) WITHOUT TIME ZONE` | `TIMESTAMP(p) WITH TIME ZONE` | `TIME(p) WITHOUT TIME ZONE` | rounds |
| SQL Server | `DATETIME2(p)` | `DATETIMEOFFSET(p)` | `TIME(p)` | rounds |
| Oracle | `TIMESTAMP(p)` | `TIMESTAMP(p) WITH TIME ZONE` | `TIMESTAMP(p)` | rounds |
| Db2 | `TIMESTAMP(p)` | `TIMESTAMP(p)` | not supported (`TIME` has no fraction) | |
| SQLite | `DATETIME` | `DATETIME` | `TIME` | stored as text |

#### How this answers the objections in #5961

| Objection | Answer |
|---|---|
| BC break, must be opt-in | Fractions are only written by new type names. Existing declarations are unchanged unless `precision` is set explicitly. Oracle's session change is behind a constructor flag. |
| Only MySQL/PostgreSQL | All platforms in `src/Platforms`, both introspection paths. Unsupported cases (Db2 `TIME`) are documented and tested. |
| Platform code in wrappers | Only platforms, schema managers and metadata providers change. `Connection` and the wrappers are untouched. |
| Driver unwrapping | Nothing inspects the driver stack. |

#### The one BC caveat

B honours `precision` on the plain `datetime`/`datetimetz`/`time` types, where it is silently ignored
today. Mappings that already set it get one migration. That is widening on most platforms, but on SQL
Server `precision < 6` narrows `DATETIME2(6)`. DBAL's own `MySQLSchemaManagerTest::testColumnIntrospection`
sets `precision: 8` on every type and needs adjusting. The alternative is to deprecate in 4.x and honour
in 5.0, but then #6631 can only be fixed by switching to the new types.

#### Questions

1. Type names: `datetime_precise`, `datetime_us`, `datetime_micro`?
2. Honour `precision` on the plain types in 4.6 (proposed), or deprecate first?
3. An `@internal` marker interface for "has a default precision of 6": acceptable?
4. Oracle: a constructor flag on `InitializeSession`, or documentation only?
5. A as a bug fix on 4.5.x?

Working branches with tests (MySQL 8.4, MariaDB 11.4, PostgreSQL 17, SQL Server 2022, Oracle 23,
SQLite; Db2 unit tests only) are ready, and I'll open PRs once the direction is agreed.
```

---

## 2. doctrine/dbal: bug for PR A

**Title:** `time` and `datetimetz` types throw `InvalidFormat` on values with fractional seconds

```markdown
### Bug Report

|    Q        |   A
|------------ | ------
| BC Break    | no
| Version     | 4.5.x

#### Summary

`TimeType`, `TimeImmutableType`, `DateTimeTzType` and `DateTimeTzImmutableType` convert database values
with `createFromFormat()` and the platform's format only, without the fallback that `DateTimeType` has.
Any value with fractional seconds throws.

Databases return fractions whenever the column allows them, regardless of what DBAL wrote:
* PostgreSQL `TIME`/`TIMESTAMPTZ` without a precision modifier, or filled by `NOW()`/`CURRENT_TIMESTAMP`
  (see #1515);
* MySQL/MariaDB columns widened to `TIME(6)`/`DATETIME(6)`;
* SQL Server `TIME(7)`/`DATETIMEOFFSET(7)`, and `DATETIMEOFFSET` values *without* a fraction;
* Oracle `TIMESTAMP(9)` (9 digits, more than `u` can parse).

#### Current behaviour

PostgreSQL:

    CREATE TABLE t (tm TIME, ts TIMESTAMPTZ);
    INSERT INTO t VALUES ('10:00:00.25', '2026-10-07 23:59:59.123456+00');

`Type::getType('time')->convertToPHPValue('10:00:00.25', $platform)` throws
`Could not convert database value "10:00:00.25" to Doctrine Type Doctrine\DBAL\Types\TimeType. Expected format "H:i:s".`
The same happens for `datetimetz` with `2026-10-07 23:59:59.123456+00`.

`datetime` accepts such values only through the `new DateTime($value)` fallback, which also accepts any
`strtotime()` string. `createFromFormat()` cannot parse more than 6 fractional digits, which SQL Server
`TIME(7)`/`DATETIMEOFFSET(7)` and Oracle `TIMESTAMP(9)` return.

#### Expected behaviour

All date/time types accept values with or without fractional seconds, truncating digits beyond
microseconds. Writes are unchanged. Part of #RFC. A fix with unit and functional tests is ready.
```

---

## 3. doctrine/orm: follow-up

**Title:** Support fractional-second date/time types (follow-up to doctrine/dbal#RFC)

```markdown
### Feature Request

|        Q        |   A
|---------------- | -----
| New Feature     | yes
| RFC             | no
| BC Break        | no

#### Summary

doctrine/dbal#RFC adds `datetime_precise`, `datetimetz_precise`, `time_precise` (and immutable variants)
and makes the `precision` column option meaningful for date/time columns. Most of the ORM already works
with them, because `SchemaTool::gatherColumn()` forwards `precision` for any type and `UnitOfWork`
compares date objects by identity. Three places need attention:

1. **Parameter type inference.** `ParameterTypeInferer::inferType()` maps every `DateTimeInterface` to
   `datetime`/`datetime_immutable`. So `setParameter('at', $dt)` without an explicit type truncates the
   fraction, and equality or range conditions on `(6)` columns silently miss rows. Changing the
   inference would change the SQL that existing applications send, so I propose to document passing the
   type explicitly (`setParameter('at', $dt, Types::DATETIME_PRECISE_IMMUTABLE)`) rather than infer it.
   Opinions welcome.

2. **Optimistic locking.** `ClassMetadata::setVersionMapping()` only accepts
   `integer|bigint|smallint|datetime`. `BasicEntityPersister` bumps a `datetime` version with
   `CURRENT_TIMESTAMP` at precision 0, so two updates in the same second produce the same version, and a
   concurrent writer holding the stale version still matches the `WHERE`. The lock fails silently.
   Allow the precise types (and `datetime_immutable`) as version types, and bump them with a
   precision-aware current timestamp. This needs
   `AbstractPlatform::getCurrentTimestampSQL(?int $precision = null)` in DBAL first (MySQL needs
   `CURRENT_TIMESTAMP(6)`), and has to be reconciled with doctrine/dbal#7195.

3. **DQL `CURRENT_TIMESTAMP()`/`CURRENT_TIME()`** emit `getCurrentTimestampSQL()`, which is fsp 0 on
   MySQL/MariaDB. The same platform method would allow an optional precision argument.

I'd add functional tests confirming that `#[Column(type: 'datetime_precise', precision: 3)]` reaches DBAL
unchanged and round-trips.
```

---

## 4. doctrine/migrations: follow-up

**Title:** `executed_at` becomes `null` when the metadata column has fractional seconds

```markdown
### Bug Report

|    Q        |   A
|------------ | ------
| BC Break    | no
| Version     | 3.x

#### Summary

`TableMetadataStorage` writes `executed_at` with a hard-coded `'Y-m-d H:i:s'` and reads it with
`DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), $value)`, without a fallback.
If the column returns fractional seconds, the read yields `false`, so `executed_at` silently becomes
`null` (`migrations:status`, `migrations:list`). That happens when the column is widened to
`DATETIME(6)`, PostgreSQL returns them from an unconstrained `TIMESTAMP`, or the SQL Server format
contains `.u`.

#### Expected behaviour

Convert with the DBAL type that declared the column (`Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue()`),
which tolerates fractional seconds since doctrine/dbal#A, and format the written value with the platform
format instead of a literal. Related: doctrine/dbal#RFC.
```

---

## 5. symfony/symfony (Doctrine bridge): follow-up

**Title:** [DoctrineBridge] `DoctrineExtractor` does not recognize date/time types by interface

```markdown
| Q             | A
| ------------- | ---
| Bug fix?      | yes
| New feature?  | no
| Deprecations? | no
| Issues        |
| License       | MIT

`Symfony\Bridge\Doctrine\PropertyInfo\DoctrineExtractor` hard-codes the DBAL date type names (`date`,
`datetime`, `datetimetz`, `vardatetime`, `time` and the immutable variants). Types outside that list fall
through to a generic object/mixed type, which breaks serializer, form and validator type guessing:

* `datetime_utc` and `datetime_utc_immutable`, added in DBAL 4.5, are already missed;
* doctrine/dbal#RFC adds `datetime_precise`, `datetimetz_precise`, `time_precise` and immutable variants;
* any custom date type is missed.

DBAL marks these types with `PhpDateMappingType`, `PhpDateTimeMappingType` and `PhpTimeMappingType`. Checking
the type instance against those interfaces, and picking `\DateTime` vs `\DateTimeImmutable` from the
`convertToPHPValue()` return type, would cover all of them without a name list.
```
