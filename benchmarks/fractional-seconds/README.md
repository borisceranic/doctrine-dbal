# Benchmark: date/time conversion with fractional seconds

Measures `Type::convertToPHPValue()` of the date/time types, i.e. the per-value cost of hydration, before and
after the tolerant-reads change (branch `claude/fsp-a-tolerant-reads`, PR A of the RFC).

## Why it is not part of the PR

doctrine/dbal has no benchmark infrastructure: no phpbench dependency, no `tests/Performance`, no CI job.
doctrine/orm does (`tests/Performance`, `phpbench.json`, a `phpbench.yml` workflow), but adding the same to DBAL
for one change would add a dev dependency and a new pattern. So the benchmark lives here as a standalone phpbench
project, which runs the same subjects against two DBAL checkouts.

## Running it

Requirements: PHP 8.2+, Composer, git.

```bash
cd benchmarks/fractional-seconds
./run.sh
```

`run.sh` clones the base and head refs into `./var`, installs their dependencies without dev packages, installs
phpbench, and runs the subjects first against the base and then against the head, reporting the head relative to
the base (`--ref`). Configuration is done through environment variables (see the top of `run.sh`):

| Variable | Default |
|---|---|
| `DBAL_REPO` | `https://github.com/borisceranic/doctrine-dbal.git` |
| `BASE_REF` | `4.5.x` |
| `HEAD_REF` | `claude/fsp-a-tolerant-reads` |
| `WORK_DIR` | `./var` |
| `COMPOSER_FLAGS` | none; e.g. `--prefer-install=source` if GitHub API downloads are rate limited |

To benchmark any other checkout directly:

```bash
DBAL_DIR=/path/to/dbal vendor/bin/phpbench run --report=compare
```

## Method

* One subject, `benchConvertToPHPValue`, with ten parameter sets: a type, a platform and a database value. Throwing
  cases are measured including the exception, because that is what the caller pays.
* 20,000 revolutions × 10 iterations (2 warm-up), the mode is reported. Iterations deviating more than 5% are
  retried. opcache is enabled for the CLI and assertions are compiled out (`zend.assertions=-1`), as in production.
* **A/A control:** the base is run twice, the second time last. The difference between the two base runs is the
  noise floor. It was up to about ±9% per case across several sessions; differences below that mean nothing.
* Other load on the machine (e.g. database containers) visibly increases the noise. Stop it while measuring.

## Results

PHP 8.3.6 (CLI, NTS, opcache), 4 vCPU Linux container, no other load. Times are the mode per conversion.

| Value | base (4.5.x) | head | head vs base | A/A (base vs base) |
|---|---|---|---|---|
| datetime, MySQL, whole seconds | 0.912 µs | 0.944 µs | +3.5% | −0.2% |
| datetimetz, PostgreSQL, whole seconds | 0.806 µs | 0.887 µs | +10.0% | +4.9% |
| time, PostgreSQL, whole seconds | 0.743 µs | 0.774 µs | +4.2% | −3.2% |
| datetime, SQL Server, platform format has `.u` | 1.005 µs | 1.037 µs | +3.2% | −1.5% |
| datetime, MySQL, microseconds | 1.542 µs | 1.392 µs | −9.8% | −4.1% |
| datetimetz, PostgreSQL, microseconds | 8.448 µs ¹ | 1.270 µs | −85.0% | +0.8% |
| time, PostgreSQL, microseconds | 1.055 µs ¹ | 1.284 µs | +21.7% ² | +6.8% |
| time, SQL Server, 7 digits | 1.112 µs ¹ | 1.322 µs | +18.9% ² | +2.9% |
| vardatetime, MySQL, microseconds (unchanged code) | 0.951 µs | 0.963 µs | +1.2% | +9.2% |
| datetimetz, PostgreSQL, invalid value ¹ | 1.094 µs | 1.283 µs | +17.3% | −7.9% |

¹ Throws a `ConversionException` (base: every value with a fraction for `time*` and `datetimetz*`; both: the
invalid value).
² Not a slowdown of working code: the base throws, the head returns the value.

Reading:

* Values in the platform format, i.e. everything that worked before, are within the noise floor.
* Values with fractions are converted where the base threw, and `datetimetz` with an offset is about 6× faster than
  the base was at throwing.
* `datetime` with fractions is slightly faster than the base's `new DateTime()` fallback.
* Values that fail anyway cost about 0.2 µs more before the exception.

## What the measurements changed in the implementation

The first version of PR A routed every value through `DateTimeParser::parse()`, which tried the platform format
and, on failure, retried with a fraction. Benchmarked, it was:

* **10–20% slower for values in the platform format.** The extra userland call per value (frame, typed arguments,
  union return type) costs about 0.1–0.17 µs, against a native `createFromFormat()` of about 0.7–0.9 µs.
* **Slow for fractions with a timezone offset.** `createFromFormat('Y-m-d H:i:sO', '… 23:59:59.123456+02')` takes
  about 7.7 µs to fail, because `O` then tries to parse the fraction as a timezone name. Failing first and retrying
  paid that every time.
* Replacing `$className::createFromFormat()` (dynamic class) with a hard-coded `DateTimeImmutable::`/`DateTime::`
  dispatch saved about 100 ns in an isolated micro-benchmark, but made no measurable difference in this benchmark,
  so it was not kept.

The current version therefore tries the platform format inline in each type, skips it when the value has a fraction
the format does not account for, and calls the parser only as a fallback.

The benchmark also refutes an old assumption: `VarDateTimeType`'s docblock says `new DateTime()` "runs twice as
long" as `createFromFormat()`. On PHP 8.3 the constructor (0.95 µs above) is as fast as the formatted parse.
