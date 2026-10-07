#!/usr/bin/env bash
# Runs the benchmark against two doctrine/dbal refs and compares them.
#
# Environment (all optional):
#   DBAL_REPO       repository to clone (default: the fork holding the change)
#   BASE_REF        ref without the change (default: 4.5.x)
#   HEAD_REF        ref with the change (default: claude/fsp-a-tolerant-reads)
#   WORK_DIR        where the checkouts go (default: ./var)
#   COMPOSER_FLAGS  extra flags for composer install, e.g. --prefer-install=source
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
repo=${DBAL_REPO:-https://github.com/borisceranic/doctrine-dbal.git}
base_ref=${BASE_REF:-4.5.x}
head_ref=${HEAD_REF:-claude/fsp-a-tolerant-reads}
work=${WORK_DIR:-$here/var}
composer_flags=${COMPOSER_FLAGS:-}

checkout() {
    local dir=$work/$1 ref=$2

    if [ ! -d "$dir" ]; then
        git clone --quiet --depth 1 --branch "$ref" "$repo" "$dir"
    fi

    # shellcheck disable=SC2086
    (cd "$dir" && composer install --no-dev --no-interaction --quiet $composer_flags)
}

mkdir -p "$work"
checkout base "$base_ref"
checkout head "$head_ref"

cd "$here"
# shellcheck disable=SC2086
composer install --no-interaction --quiet $composer_flags
rm -rf .phpbench

php --version | head -n 1

DBAL_DIR=$work/base vendor/bin/phpbench run --tag=base --store --progress=none --report=compare
DBAL_DIR=$work/head vendor/bin/phpbench run --tag=head --store --progress=none --report=compare --ref=base
