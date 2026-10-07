# RFC: opt-in fractional-second (microsecond) support for date/time types

Status: draft, for discussion before any code PR is opened.
Branch context: `4.5.x` is current; features target `4.6.x`, bug fixes `4.5.x`.

## 1. Problem

DBAL cannot round-trip a `DateTime` with microseconds through any built-in type on any platform
except SQL Server's `datetime`/`datetimetz`. The behaviour differs per type and per platform. All
claims below were re-verified on `4.5.x` (commit `70c35c4`) with PHP 8.3, MySQL 8.4, MariaDB 11.4,
PostgreSQL 17, SQL Server 2022 and Oracle 23 Free.

### 1.1 Writes drop the fraction in PHP

`AbstractPlatform::getDateTimeFormatString()` is `'Y-m-d H:i:s'`, `getTimeFormatString()` is `'H:i:s'`,
and `getDateTimeTzFormatString()` is `'Y-m-d H:i:s'` (`O` on PostgreSQL, `P` on Oracle). The fraction is cut before the
database sees it. Only `SQLServerPlatform` uses `.u` (`'Y-m-d H:i:s.u'`, `'Y-m-d H:i:s.u P'`), and it
declares `DATETIME2(6)` and `DATETIMEOFFSET(6)`. SQL Server's `time` is still `TIME(0)` / `'H:i:s'`.

### 1.2 Declarations pin precision to 0 or omit it

| Platform    | `datetime`                       | `datetimetz`                   | `time`                     |
|-------------|----------------------------------|--------------------------------|----------------------------|
| MySQL/MariaDB | `DATETIME`                     | `DATETIME`                     | `TIME`                     |
| PostgreSQL  | `TIMESTAMP(0) WITHOUT TIME ZONE` | `TIMESTAMP(0) WITH TIME ZONE`  | `TIME(0) WITHOUT TIME ZONE`|
| SQL Server  | `DATETIME2(6)`                   | `DATETIMEOFFSET(6)`            | `TIME(0)`                  |
| Oracle      | `TIMESTAMP(0)`                   | `TIMESTAMP(0) WITH TIME ZONE`  | `DATE`                     |
| Db2         | `TIMESTAMP(0)`                   | `TIMESTAMP(0)`                 | `TIME`                     |
| SQLite      | `DATETIME`                       | `DATETIME`                     | `TIME`                     |

The `column['precision']` option is ignored by all of them.

### 1.3 Reads are inconsistent

* `datetime`, `datetime_immutable`, `datetime_utc*`: `createFromFormat()` fails on a fraction and
  falls back to `new DateTime($value)`. The fraction survives a read only through that fallback, which
  also accepts any `strtotime()` string. It is not slow on PHP 8.3, contrary to the `VarDateTimeType`
  docblock (see the benchmark).
* `datetimetz*`, `time*`: no fallback. A value with a fraction throws `InvalidFormat`. This is a plain
  bug: PostgreSQL returns `10:00:00.25` from any `TIME` column without a precision modifier, and
  `NOW()`/`CURRENT_TIMESTAMP` defaults produce fractions in `TIMESTAMPTZ` columns (#1515).
* `createFromFormat()` cannot parse more than 6 digits (SQL Server `TIME(7)`/`DATETIMEOFFSET(7)`,
  Oracle `TIMESTAMP(9)`). Only the `datetime*` fallback accepts them.
* `VarDateTimeType`/`VarDateTimeImmutableType` are the documented workaround. They are not registered,
  only change reads, and the docs even suggest overriding `time` with them, which yields a full datetime.

### 1.4 Introspection loses precision, and the comparator then loops

| Platform | Fractional precision source | What DBAL does today |
|---|---|---|
| MySQL/MariaDB | `information_schema.COLUMNS.DATETIME_PRECISION` | not selected; precision `null` |
| PostgreSQL | `pg_attribute.atttypmod` (`-1` = unconstrained = 6) | not read |
| SQL Server | `sys.columns.scale` | stored in `Column::$scale`; `Column::$precision` gets the *character length* (19/23/26/33) |
| Oracle | `ALL_TAB_COLUMNS.DATA_SCALE` | stored in `Column::$scale`; `DATA_PRECISION` is `NULL` |
| Db2 | `SYSCAT.COLUMNS.SCALE` | not read for `TIMESTAMP` |
| SQLite | none | n/a |

Both introspection paths are affected: the schema managers (`_getPortableTableColumnDefinition()`) and
the 4.5 `MetadataProvider` implementations.

Since 4.0 the comparator compares columns by generated declaration SQL
(`AbstractPlatform::columnsEqual()`). A custom type declaring `DATETIME(6)`/`TIMESTAMP(6)` is therefore
compared against an introspected plain `datetime` that renders `DATETIME`, and every `migrations:diff`
emits the same `ALTER` again (#6631).

The reverse accident is load-bearing: many applications have hand-widened columns to `DATETIME(6)`
while still mapping `datetime`. They see no diff today *only because* introspection forgets the
precision. Any fix that starts reading precision must not turn that into `ALTER ... DATETIME`, which
would silently destroy the fractions.

### 1.5 Databases disagree on excess digits (measured)

Value written: `'2026-12-31 23:59:59.999999'`.

| Database | into precision 0 | into precision 3 | rule |
|---|---|---|---|
| MySQL 8.4 (default `sql_mode`) | `2027-01-01 00:00:00` | `2027-01-01 00:00:00.000` | rounds (truncates with `TIME_TRUNCATE_FRACTIONAL`) |
| MariaDB 11.4 (strict) | `2026-12-31 23:59:59` | `2026-12-31 23:59:59.999` | truncates, no warning |
| PostgreSQL 17 | `2027-01-01 00:00:00` | `2027-01-01 00:00:00` (`.9995`) | rounds |
| SQL Server 2022 | `2027-01-01 00:00:00` | `2027-01-01 00:00:00.000` (`.9995`) | rounds |
| Oracle 23 | `2027-01-01 00:00:00` | `2027-01-01 00:00:00.000` (`.9995`) | rounds |
| SQLite | stores the string | stores the string | none |

So simply starting to send `.u` to existing precision-0 columns changes stored values (including the
year) on MySQL, PostgreSQL, SQL Server and Oracle. That is the core reason this must be opt-in.

Oracle has an extra trap. With the session formats set by `Driver\OCI8\Middleware\InitializeSession`
(`NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS'`):

* writing `'2026-01-01 10:00:00.5'` fails with `ORA-01830: date format picture ends before converting
  entire input string`;
* reading a `TIMESTAMP(6)` column **silently drops** the fraction;
* with `...SS.FF6` in the session format, both work, plain `'...10:00:00'` is still accepted on write,
  and `TIMESTAMP(9)` is shown truncated (not rounded) to 6 digits.

This is the "BC break, notably for Oracle" from #5961.

### 1.6 PHP gotchas (PHP 8.2+)

* `createFromFormat('Y-m-d H:i:s', …)` already zeroes microseconds (unlike older PHP), so no `|` is
  needed for correctness; `!` is still required for `time` so the date part is `1970-01-01`.
* `u` accepts 1–6 digits; 7+ digits are "Trailing data". Values must be cut to 6 digits before parsing.
* `'Y-m-d H:i:s.u'` rejects a value *without* a fraction ("Not enough data"). PostgreSQL omits the
  fraction when it is zero and strips trailing zeros (`.25`), so both shapes must be accepted.
* `O` parses `+02`, `+0200` and `+05:30`.

## 2. Prior attempts

* **#2873** "Add microseconds support on datetime/time types" (open since 2017). It changes the format
  strings of the existing types, which is the MySQL/Oracle BC break of 1.5.
* **#5961** "Add support for variable precision TIMEs and DATETIMEs" (closed unmerged, 2023).
  @derrabus' objections:
  1. BC break, notably for Oracle. "We need to make this change of behavior opt-in."
  2. Only MySQL/PostgreSQL were covered.
  3. "Platform-specific code inside the generic wrapper classes is a no-go."
  4. It relied on unwrapping the driver middleware stack.
* **#6631**: a custom `TIMESTAMP(6)` type generates the same migration forever (DBAL 4).
* Older reports: #1020 (MySQL 5.6.4 fractional seconds), #1515 (PostgreSQL `NOW()` breaks hydration),
  #2098 (SQL Server microseconds), doctrine/orm#6510, doctrine/orm#6305.

How this RFC answers each objection:

| Objection | Answer |
|---|---|
| 1. BC break / opt-in | Existing types never change their write format. Fractions are written only by **new type names**. Existing declarations are byte-identical unless `precision` is explicitly set. Oracle's session format change is behind an explicit constructor flag. |
| 2. Only two drivers | Every platform in `src/Platforms` gets declaration, introspection (both paths), format strings and tests. Unsupported cases (Db2 `TIME`) are documented and tested. |
| 3. Platform code in wrappers | Everything lives in `AbstractPlatform` subclasses, schema managers and metadata providers. `Connection`, `Statement` and the wrappers are untouched. |
| 4. Driver unwrapping | Nothing inspects the driver stack. The only driver-level piece is the existing, user-registered OCI8 `InitializeSession` middleware gaining an opt-in argument. |

## 3. Proposal

The work splits into three PRs, each mergeable on its own (section 9).

### 3.1 Tolerant reads for all temporal types (PR A, bug fix, `4.5.x`)

Every `datetime*`, `datetimetz*`, `datetime_utc*` and `time*` type:

1. tries the platform format exactly as today, unless the value has a fraction the format does not
   account for (a `.` in the value, no `.u` in the format), because the format is bound to fail;
2. if that failed or was skipped, calls an internal `DateTimeParser`. It cuts the fraction to 6 digits
   (truncation, like Oracle's own `FF6` display) and parses with `.u` inserted after the seconds of the
   platform format (or removed, if the platform format has `.u` and the value has none);
3. otherwise keeps today's behaviour (`new DateTime()` fallback for `datetime*`, `InvalidFormat` for
   the rest).

There is no new public API.

The shape is driven by measurements (`benchmarks/fractional-seconds`, phpbench, 4.5.x vs. the branch):

* Routing every value through the parser made values in the platform format 10-20% slower (one extra
  function call against a ~0.8 µs native parse). Trying the format inline keeps them within the
  benchmark's noise floor (about ±9%).
* Failing first on a fraction is expensive with a timezone offset: `createFromFormat('Y-m-d H:i:sO')`
  takes about 7.7 µs to reject `…59.123456+02`, because `O` tries to read the fraction as a timezone
  name. Skipping the doomed attempt makes such values convert in about 1.3 µs (they threw before, in
  about 8.4 µs).
* `datetime` values with fractions convert about 10% faster than through the `new DateTime()` fallback.

`VarDateTime*`: **keep, not deprecated**. After PR A they are unnecessary for fractions, but they still
accept arbitrary `strtotime()` formats some users rely on. The docs stop recommending them for
fractional columns. A deprecation can be discussed for 5.0 separately.

### 3.2 Fractional-second precision as a column property (PR B, `4.6.x`)

**Which property:** `Column::$precision`.

* It is the SQL standard's term ("fractional seconds precision"), MySQL's and PostgreSQL's
  (`DATETIME_PRECISION`), and what users write in Laravel, Rails and Hibernate (`precision: 6`).
* It is the option the ORM already forwards for any type (`SchemaTool::gatherColumn()` passes
  `precision` when the mapping sets it; ORM 2.20 and 3.x default it to `null`).
* It is nullable. `Column::$scale` is a non-nullable `int` defaulting to `0`, so it **cannot express
  "unspecified"**, which the comparator semantics below depend on. SQL Server and Oracle call it
  scale in their catalogues; their schema managers map it.

**Introspection:** every schema manager and metadata provider fills `precision` for temporal columns
from the sources in 1.4. PostgreSQL's unconstrained `TIMESTAMP`/`TIME` (`atttypmod = -1`) is reported
as `6`, the effective precision. SQLite reports `null`.

**Declarations** honour an explicit precision. `null` keeps today's SQL, byte for byte.

| Platform | `precision: null` | `precision: p` |
|---|---|---|
| MySQL/MariaDB | `DATETIME` / `TIME` (unchanged) | `DATETIME(p)` / `TIME(p)`; `p = 0` renders without `(0)` |
| PostgreSQL | `TIMESTAMP(0) …` / `TIME(0) …` (unchanged) | `TIMESTAMP(p) …` / `TIME(p) …` |
| SQL Server | `DATETIME2(6)`, `DATETIMEOFFSET(6)`, `TIME(0)` (unchanged) | `DATETIME2(p)`, `DATETIMEOFFSET(p)`, `TIME(p)` |
| Oracle | `TIMESTAMP(0)`, `TIMESTAMP(0) WITH TIME ZONE`, `DATE` (unchanged) | `TIMESTAMP(p)`, `TIMESTAMP(p) WITH TIME ZONE`; `time`: `DATE` for `p = 0`, `TIMESTAMP(p)` for `p > 0` |
| Db2 | `TIMESTAMP(0)`, `TIME` (unchanged) | `TIMESTAMP(p)`; `TIME` has no fractional seconds, precision ignored |
| SQLite | `DATETIME` / `TIME` | unchanged (no precision) |

There is no validation of the range: the database rejects precisions it does not support
(e.g. MySQL above 6), and an introspected Oracle `TIMESTAMP(9)` must still render as `TIMESTAMP(9)`
so that it compares equal to itself. PHP cannot represent more than 6 digits; reads truncate.

**Comparator semantics**, implemented in `AbstractPlatform::columnsEqual()` before the declarations are
rendered:

> If one column has an *unspecified* (`null`) precision and the other column is a temporal column
> (its type implements `PhpDateTimeMappingType` or `PhpTimeMappingType`), the unspecified side takes
> the other side's precision, unless its type carries its own default precision (the precise types
> of 3.3, marked by the `@internal` interface `FractionalSecondsType`).

| Desired (mapping) | Actual (introspected) | Today | With this RFC |
|---|---|---|---|
| `datetime` | `DATETIME` | equal | equal |
| `datetime` | `DATETIME(6)` (hand-widened) | equal (by accident) | **equal (by design, no narrowing)** |
| `datetime`, `precision: 6` | `DATETIME` | equal (option ignored) | `ALTER … DATETIME(6)` |
| `datetime`, `precision: 6` | `DATETIME(6)` | **diff forever** | equal |
| custom type rendering `DATETIME(6)` (#6631) | `DATETIME(6)` | **diff forever** | equal |
| `datetime_precise` | `DATETIME` | n/a | `ALTER … DATETIME(6)` |
| `datetime_precise` | `DATETIME(6)` | n/a | equal |
| `datetime_precise`, `precision: 3` | `DATETIME(6)` | n/a | `ALTER … DATETIME(3)` (explicitly requested) |
| `decimal` (any) | any | unchanged | unchanged (rule applies to temporal columns only) |

"Unspecified" means "platform default / don't care". Narrowing happens only when someone writes a
smaller number.

### 3.3 Opt-in precise types (PR C, `4.6.x`)

New type names. Names are open for bikeshedding; alternatives are `datetime_us` and `datetime_micro`.

| Constant | Name | PHP class |
|---|---|---|
| `Types::DATETIME_PRECISE_MUTABLE` | `datetime_precise` | `DateTimePreciseType` |
| `Types::DATETIME_PRECISE_IMMUTABLE` | `datetime_precise_immutable` | `DateTimePreciseImmutableType` |
| `Types::DATETIMETZ_PRECISE_MUTABLE` | `datetimetz_precise` | `DateTimeTzPreciseType` |
| `Types::DATETIMETZ_PRECISE_IMMUTABLE` | `datetimetz_precise_immutable` | `DateTimeTzPreciseImmutableType` |
| `Types::TIME_PRECISE_MUTABLE` | `time_precise` | `TimePreciseType` |
| `Types::TIME_PRECISE_IMMUTABLE` | `time_precise_immutable` | `TimePreciseImmutableType` |

They:

* write with new platform methods `getDateTimePreciseFormatString()`,
  `getDateTimeTzPreciseFormatString()` and `getTimePreciseFormatString()`;
* declare via the existing declaration methods with `precision` defaulting to **6**;
* read with the tolerant parser of PR A (precise format first, then the plain one);
* extend their plain counterparts (`DateTimePreciseType extends DateTimeType`, …), overriding only
  `getSQLDeclaration()`, `convertToDatabaseValue()` and `convertToPHPValue()`. They therefore implement
  `PhpDateTimeMappingType` / `PhpTimeMappingType` like the plain types do;
* implement the `@internal` marker `FractionalSecondsType` (with `DEFAULT_PRECISION = 6`), so the
  comparator knows an unspecified precision means 6 for them and not "any";
* are not mapped from any database type, so introspection still yields `datetime`/`time` columns, and
  comparison works on the rendered SQL (3.2).

Why new type names instead of a Type API change: types are flyweights. `convertToDatabaseValue()`
receives `$value` and `$platform`, never the column, so a type cannot format "according to the
column's precision". Changing that signature touches every custom type in the ecosystem. New names
are additive.

**Precision below 6:** the type writes 6 digits; the database applies its own rule (1.5). MySQL users
who want truncation set `TIME_TRUNCATE_FRACTIONAL`. Truncating in PHP would need the column, which a
flyweight type does not have. This is documented, not hidden.

**`datetime_utc` + precise:** not proposed now (combinatorial). It can follow if asked for.

### 3.4 New platform API (PR C)

```php
// AbstractPlatform (defaults), overridden where the platform differs
public function getDateTimePreciseFormatString(): string   // 'Y-m-d H:i:s.u'
public function getDateTimeTzPreciseFormatString(): string // 'Y-m-d H:i:s.u'
public function getTimePreciseFormatString(): string       // 'H:i:s.u'
```

```php
// Driver\OCI8\Middleware\InitializeSession (opt-in, default false = today's session)
public function __construct(bool $fractionalSeconds = false)
// true: NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF6',
//       NLS_TIMESTAMP_TZ_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF6 TZH:TZM'
```

`getCurrentTimestampSQL(?int $precision = null)` (for ORM version columns and DQL) is **not** in this
RFC's PRs. It is listed as a follow-up (section 8) to keep the PRs small.

## 4. Per-platform matrix for the precise types

| Platform | `datetime_precise` | `datetimetz_precise` | `time_precise` | Write formats | Read | Introspection source | Excess digits (p < 6) |
|---|---|---|---|---|---|---|---|
| MySQL ≥ 5.7 / MariaDB | `DATETIME(p)` | `DATETIME(p)` (no zone stored, as today) | `TIME(p)` | `Y-m-d H:i:s.u`, same, `H:i:s.u` | tolerant | `DATETIME_PRECISION` | MySQL rounds; MariaDB truncates |
| PostgreSQL | `TIMESTAMP(p) WITHOUT TIME ZONE` | `TIMESTAMP(p) WITH TIME ZONE` | `TIME(p) WITHOUT TIME ZONE` | `Y-m-d H:i:s.u`, `Y-m-d H:i:s.uO`, `H:i:s.u` | tolerant (0–6 digits, optional fraction) | `atttypmod` | rounds |
| SQL Server | `DATETIME2(p)` | `DATETIMEOFFSET(p)` | `TIME(p)` | `Y-m-d H:i:s.u`, `Y-m-d H:i:s.u P`, `H:i:s.u` | tolerant (7 digits cut to 6) | `sys.columns.scale` | rounds |
| Oracle | `TIMESTAMP(p)` | `TIMESTAMP(p) WITH TIME ZONE` | `TIMESTAMP(p)` (date part `1900-01-01`) | `Y-m-d H:i:s.u`, `Y-m-d H:i:s.uP`, `1900-01-01 H:i:s.u` | tolerant (9 digits cut to 6) | `DATA_SCALE` | rounds; **requires `InitializeSession(fractionalSeconds: true)`** or an equivalent session `NLS_TIMESTAMP*_FORMAT` with `FF` |
| Db2 | `TIMESTAMP(p)` | `TIMESTAMP(p)` (no zone, as today) | **not supported**: `TIME` has no fractional seconds, the declaration stays `TIME` (unit-tested, documented; the functional test skips it) | `Y-m-d H:i:s.u` | tolerant | `SYSCAT.COLUMNS.SCALE` | Db2 truncates (to be confirmed in CI) |
| SQLite | `DATETIME` | `DATETIME` | `TIME` | `Y-m-d H:i:s.u`, same, `H:i:s.u` | tolerant | none (`null`) | none: stored as text, compared as text |

SQLite note: comparing `'… 10:00:00'` with `'… 10:00:00.000000'` as text is unequal. Users mixing
plain and precise writes on the same column get inconsistent text. Documented.

## 5. BC analysis

| Change | Who is affected | Impact |
|---|---|---|
| A: tolerant reads | values that used to throw `InvalidFormat` (`time*`, `datetimetz*`) or hit `new DateTime()` | they now parse. `datetime*` results are identical (same default time zone), just faster |
| B: introspected `Column::getPrecision()` is set for temporal columns | code reading it | SQL Server reported the character length (e.g. 26), now the fractional precision (e.g. 6). Oracle reported `null`, now the scale |
| B: declarations honour explicit `precision` on `datetime`/`datetimetz`/`time` | apps that set `precision` on these types today, where it is silently ignored. DBAL's own `MySQLSchemaManagerTest::testColumnIntrospection` is one: it sets `precision: 8` on every type, and B changes it to 6 for temporal types | **one migration**: widening on MySQL/PostgreSQL/Oracle/Db2; on SQL Server it can *narrow* (`precision: 0` → `DATETIME2(0)`) and on Oracle `time` becomes `TIMESTAMP(p)`. Called out in `UPGRADE.md`. Alternative: deprecate-then-honour in 5.0 (see 6.2) |
| B: comparator rule | apps with hand-widened columns and plain mappings | none: still no diff, now by design |
| B: comparator rule | #6631 custom types | the endless diff disappears |
| C: new types, new platform methods, `InitializeSession` argument | nobody unless used | additive. Custom platforms extending `AbstractPlatform` inherit sane defaults |
| Write format of existing types | — | **unchanged** on every platform |

"Upgrade DBAL, change no mapping" produces no diff and no change to written values, with one
exception: mappings that already set `precision` on a plain temporal type.

## 6. Open questions for maintainers

1. Type names: `datetime_precise` vs `datetime_us` vs `datetime_micro`.
2. Explicit `precision` on plain types: honour in 4.6 (proposed; it is the only way to fix #6631
   without type-switching introspection), or emit a deprecation in 4.x and honour in 5.0?
3. Marker interface `FractionalSecondsType`: acceptable, or should "has a default precision" be
   expressed differently?
4. Oracle: an opt-in `InitializeSession` argument, or only documentation of the required
   `NLS_TIMESTAMP*_FORMAT`? PR C enables the argument for the whole Oracle test suite, which
   passes thanks to PR A. That suggests it could become the default in 5.0.
5. Should A target `4.5.x` (bug fix) as proposed?

## 7. Verification so far

All three branches were run locally against real servers in Docker:

| Database | Driver(s) | A | B | C |
|---|---|---|---|---|
| MySQL 8.4 | pdo_mysql, mysqli | ✓ | ✓ | ✓ |
| MariaDB 11.4 | pdo_mysql | ✓ | ✓ | ✓ |
| PostgreSQL 17 | pdo_pgsql, pgsql | ✓ | ✓ | ✓ |
| SQL Server 2022 | pdo_sqlsrv, sqlsrv | ✓ | ✓ | ✓ |
| Oracle 23 Free | oci8 | n/a (skipped: session format) | ✓ | ✓ |
| SQLite | pdo_sqlite | ✓ | ✓ | ✓ |
| Db2 | — | not run | not run | not run |

"✓" means the full `tests/Functional` suite passes, apart from
`LengthExpressionTest` "4-byte" on SQL Server, which fails identically on `4.5.x` (server collation).
Unit tests, `phpcs` and `phpstan` pass on every branch. Db2 is covered by unit tests only; its CI job
is the first real run.

## 8. Follow-ups outside DBAL (separate issues, after DBAL merges)

* **doctrine/orm**
  * `ParameterTypeInferer` infers `datetime`/`datetime_immutable` for every `DateTimeInterface`, so
    `setParameter('x', $dt)` truncates fractions. Recommend documenting explicit types rather than
    changing inference, because changing it alters the SQL parameters existing apps send.
  * Optimistic locking: allow `datetime_precise(_immutable)` (and `datetime_immutable`) as version
    types and bump with a precision-aware current timestamp. Needs
    `AbstractPlatform::getCurrentTimestampSQL(?int $precision)` in DBAL first; reconcile with
    dbal#7195.
  * DQL `CURRENT_TIMESTAMP()` / `CURRENT_TIME()`: same platform method.
  * Already fine: `SchemaTool::gatherColumn()` forwards `precision`, and `UnitOfWork` compares date
    objects by identity. Add tests only.
* **doctrine/migrations**: `TableMetadataStorage` writes `executed_at` with a hard-coded `'Y-m-d H:i:s'`
  and reads with `createFromFormat()` without a fallback. A widened column silently yields `null`. Use
  the DBAL type's conversion.
* **symfony/doctrine-bridge**: `DoctrineExtractor` hard-codes date type names. It already misses
  `datetime_utc`/`datetime_utc_immutable` from DBAL 4.5. Detect `PhpDateTimeMappingType` /
  `PhpTimeMappingType` / `PhpDateMappingType` instead of matching names.

## 9. PR split

| PR | Branch (fork) | Target | Content |
|---|---|---|---|
| A | `claude/fsp-a-tolerant-reads` | `4.5.x` | tolerant parsing in all temporal types; tests (unit plus functional round-trip of fractional strings on every platform) |
| B | `claude/fsp-b-precision-introspection` | `4.6.x` | precision introspection (schema managers and metadata providers), declarations honour precision, comparator rule; tests, `UPGRADE.md` |
| C | `claude/fsp-c-precise-types` | `4.6.x` | six precise types, platform format methods, `FractionalSecondsType`, OCI8 session flag, docs (`types.rst`, `known-vendor-issues.rst`, `UPGRADE.md`) |

A is a bug fix and could also go to `4.5.x` without B and C. All 4.x branches are based on `4.5.x`;
B and C need a rebase onto `4.6.x` (which exists in the fork too) before opening their PRs.

The branches are stacked: B is based on A, and C on B. Each PR links #2873, #5961 and #6631, restates
the objection table of section 2, and includes the matrix of section 4.

## 10. 3.x backport: A only

Only the bug fix is backported: `claude/fsp-3x-a-tolerant-reads` → `3.10.x`. It is the same internal
`DateTimeParser` in PHP 7.4 syntax, used by the same six types; there is no new API.

Why A, and only A (Packagist, `stats/major/{2,3,4}.json`, average downloads per day):

| Month | 2.x | 3.x | 4.x | 3.x share |
|---|---|---|---|---|
| 2024-09 | 58k | 174k | 23k | 68% |
| 2025-09 | 39k | 144k | 54k | 61% |
| 2026-03 | 39k | 145k | 88k | 53% |
| 2026-09 | 41k | 175k | 178k | 44% |

* 3.x is still about half of all DBAL downloads, and flat in absolute terms. 4.x only overtook it in
  September 2026.
* 72% of 3.x downloads are 3.10 (2026-09), so a 3.10.x patch release reaches most 3.x users.
* B and C are features. 3.x is in maintenance, and much of its traffic likely comes from stacks that are
  themselves in maintenance (e.g. Laravel 10, ORM 2). On 3.x they would also need workarounds: `Column`
  defaults to precision 10 instead of `null`, `Comparator::diffColumn()` must report precision, and the
  new types need type comments. Not proposed unless maintainers ask.

Verification: full `tests/Functional` on MySQL 8.4, MariaDB 11.4, PostgreSQL 17, SQL Server 2022,
Oracle 23 (the read test skips there, as on 4.x) and SQLite; unit tests, `phpcs` and `phpstan` pass. It
ran on PHP 8.3 only. PHP 7.4 compatibility rests on phpcs (`php_version` 70400) and on avoiding 8.x
syntax.

Pre-existing 3.x issues noticed while prototyping B and C (unrelated to A, not fixed):

* On Oracle, a plain `time` column (`DATE`) is introspected as `date` and always produces a diff.
* On Oracle, altering a column to a commented type (e.g. `datetime` → `datetime_immutable`) does not
  update the type comment, so the change is proposed again forever.
