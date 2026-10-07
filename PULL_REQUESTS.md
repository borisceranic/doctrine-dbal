# Pull request texts, ready to post after maintainer feedback on the RFC issue

Branches in the fork are stacked: B is based on A, and C on B. Upstream, A targets `4.5.x`, and B and C
target `4.6.x` (rebase them onto it first; the fork has `4.6.x`). The 3.x backport of A at the end targets `3.10.x`. Replace `#RFC` and `#BUG` with the issue numbers of
`ISSUES.md` sections 1 and 2.

---

## A: `claude/fsp-a-tolerant-reads` → `4.5.x`

**Title:** Tolerate fractional seconds when reading temporal types

```markdown
|      Q       |   A
|------------- | -----------
| Type         | bug
| Fixed issues | #BUG, part of #RFC; see also #1515, #2873, #5961

#### Summary

`time*` and `datetimetz*` threw `InvalidFormat` on database values with fractional seconds, and
`datetime*` only accepted them through the slow `new DateTime()` fallback. Columns return fractions
whenever they allow them: PostgreSQL `TIME`/`TIMESTAMP` without a modifier, `NOW()` defaults,
hand-widened `DATETIME(6)`, SQL Server `TIME(7)`/`DATETIMEOFFSET(7)`, Oracle `TIMESTAMP(9)`.

All temporal types (including `datetime_utc*`) now go through an internal `DateTimeParser`, which:

1. calls `createFromFormat()` with the platform format exactly as before. The fast path and its
   results are unchanged;
2. only if that fails, retries with `.u` added after the seconds (or removed, when the platform format
   has `.u` and the value has no fraction), after truncating the fraction to 6 digits.

Writes are untouched. No public API is added.

Tests:
* `FractionalSecondsConversionTest`: every temporal type × every platform, with values shaped like
  each database returns them. 60 of 172 cases fail before the change.
* `DateTimeParserTest`: edge cases (9 digits, missing fraction, offsets, escaped `s`, invalid input).
* `Functional\Types\FractionalSecondsReadTest`: reads from `DATETIME(6)`/`TIME(6)`/`TIMESTAMPTZ(6)`/
  `DATETIME2(7)`… columns. Run on MySQL 8.4, MariaDB 11.4, PostgreSQL 17, SQL Server 2022 and SQLite.
  Oracle is skipped because the default session format drops fractions; #RFC covers it.

How the #5961 objections apply: there is no behaviour change for writes, no platform-specific code
outside platform classes, and no driver-stack inspection.
```

---

## B: `claude/fsp-b-precision-introspection` → `4.6.x`

**Title:** Introspect and declare the fractional seconds precision of temporal columns

```markdown
|      Q       |   A
|------------- | -----------
| Type         | improvement
| Fixed issues | #6631, part of #RFC; see also #2873, #5961

#### Summary

**Introspection.** Schema managers and metadata providers (both paths) report the fractional seconds
precision of date/time columns as `Column::getPrecision()`:

| Platform | Source |
|---|---|
| MySQL / MariaDB | `information_schema.COLUMNS.DATETIME_PRECISION` |
| PostgreSQL | type modifier from `format_type()`; unconstrained = 6 |
| SQL Server | `sys.columns.scale` (precision used to be the length in characters, e.g. 26) |
| Oracle | `DATA_SCALE` of `TIMESTAMP(n)` |
| Db2 | `SYSCAT.COLUMNS.SCALE` of `TIMESTAMP` |
| SQLite | none |

**Declarations.** `getDateTimeTypeDeclarationSQL()`, `getDateTimeTzTypeDeclarationSQL()` and
`getTimeTypeDeclarationSQL()` honour an explicit `precision`. Without one, the SQL is byte-identical to
before. Oracle `time` becomes `TIMESTAMP(p)` for `p > 0`, since `DATE` has no fractions. Db2 `TIME` and
SQLite have no precision.

**Comparison.** In `AbstractPlatform::columnsEqual()`, an unspecified precision accepts the precision of
the other temporal column:
* a column widened to `DATETIME(6)` outside DBAL but mapped as plain `datetime` is **not** narrowed;
* a custom type rendering `DATETIME(6)` no longer produces the same diff forever (#6631).

**BC.** Explicit `precision` on `datetime`/`datetimetz`/`time` used to be silently ignored and is now
honoured. Such mappings get one migration (see `UPGRADE.md`). DBAL's own
`MySQLSchemaManagerTest::testColumnIntrospection` sets `precision: 8` on every type, and now uses 6 for
temporal types.

Tests: declaration matrix for all platforms, comparator semantics, and
`Functional\Schema\FractionalSecondsPrecisionTest` (introspection through both paths, no diff after
creation, no narrowing of unspecified precision, detected precision changes, #6631 custom type). Full
functional suite run on MySQL 8.4, MariaDB 11.4, PostgreSQL 17, SQL Server 2022, Oracle 23 and SQLite.
Db2 is unit-tested only.
```

---

## C: `claude/fsp-c-precise-types` → `4.6.x`

**Title:** Add date/time types with fractional seconds

```markdown
|      Q       |   A
|------------- | -----------
| Type         | feature
| Fixed issues | #2873, part of #RFC; see also #1020, #2098, #5961

#### Summary

New types `datetime_precise`, `datetimetz_precise`, `time_precise` and their `_immutable` variants:
* write microseconds via new platform methods `getDateTimePreciseFormatString()`,
  `getDateTimeTzPreciseFormatString()` and `getTimePreciseFormatString()`;
* declare a fractional seconds precision of 6, or the column's `precision`;
* read with the tolerant parser of #A.

They extend the plain types, so they are `PhpDateTimeMappingType`/`PhpTimeMappingType` like them, and
implement an `@internal` marker `FractionalSecondsType`. The comparator uses the marker to know that an
unspecified precision means 6 for them, not "any".

Existing types are unchanged and keep writing whole seconds. Sending fractions to existing precision-0
columns would make MySQL, PostgreSQL, SQL Server and Oracle round them, e.g. `23:59:59.999999` on
Dec 31 into the next year.

| Platform | `datetime_precise` | `datetimetz_precise` | `time_precise` | excess digits (p < 6) |
|---|---|---|---|---|
| MySQL / MariaDB | `DATETIME(p)` | `DATETIME(p)` | `TIME(p)` | MySQL rounds, MariaDB truncates |
| PostgreSQL | `TIMESTAMP(p) WITHOUT TIME ZONE` | `TIMESTAMP(p) WITH TIME ZONE` | `TIME(p) WITHOUT TIME ZONE` | rounds |
| SQL Server | `DATETIME2(p)` | `DATETIMEOFFSET(p)` | `TIME(p)` | rounds |
| Oracle | `TIMESTAMP(p)` | `TIMESTAMP(p) WITH TIME ZONE` | `TIMESTAMP(p)` | rounds |
| Db2 | `TIMESTAMP(p)` | `TIMESTAMP(p)` | not supported | |
| SQLite | `DATETIME` | `DATETIME` | `TIME` | stored as text |

**Oracle.** The session must format timestamps with `FF6`, or writes fail with ORA-01830 and reads
drop the fraction. `InitializeSession` gains an opt-in `fractionalSeconds` argument. The Oracle test
suite now runs with it enabled, and the whole functional suite still passes thanks to #A.

How the #5961 objections are answered:

| Objection | Answer |
|---|---|
| BC break, must be opt-in | Only the new type names write fractions. The Oracle session change is an explicit constructor argument. |
| Only MySQL/PostgreSQL | Every platform: declarations, format strings, introspection (#B), functional tests. Db2 `TIME` is documented as unsupported. |
| Platform code in wrappers | Only types, platforms and the user-registered OCI8 session middleware change. |
| Driver unwrapping | None. |

Tests: unit tests for every type × platform (round trip, declarations, comparison), and
`Functional\Types\PreciseTypesTest`: round trip of `2026-10-07 23:59:59.999999`, no diff after creation
at precisions null/0/3/6, and migration from `datetime` to `datetime_precise`. Run on MySQL 8.4,
MariaDB 11.4, PostgreSQL 17, SQL Server 2022, Oracle 23 and SQLite. Docs: `types.rst`,
`known-vendor-issues.rst`, `UPGRADE.md`.
```

---

# 3.x backport → `3.10.x`

Only the bug fix is backported; see RFC section 10 for the reasoning.

## 3.x A: `claude/fsp-3x-a-tolerant-reads` → `3.10.x`

**Title:** [3.x] Tolerate fractional seconds when reading temporal types

```markdown
|      Q       |   A
|------------- | -----------
| Type         | bug
| Fixed issues | #BUG (backport of #A)

#### Summary

Backport of #A. `time*` and `datetimetz*` threw a `ConversionException` on database values with
fractional seconds, and `datetime*` only accepted them through the `new DateTime()` fallback. All
temporal types now go through an internal `DateTimeParser`. It tries the platform format exactly as
before, and only on failure retries with the fraction accounted for (truncated to 6 digits). Writes
are unchanged.

PHP 7.4 compatible. Tested against MySQL 8.4, MariaDB 11.4, PostgreSQL 17, SQL Server 2022 and SQLite.
Oracle is skipped as in #A.
```
