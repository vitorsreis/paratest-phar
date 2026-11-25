#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

box_phar="$tmp_dir/box.phar"
box_sha256="aedabb38bad96a90868603f118956c3faa662b545c06be66df1b145aba703c0a"
phpunit_url="https://phar.phpunit.de/phpunit-13.4.7.phar"
phpunit_sha256="da5986f40f1408a175421587fdbf9339713b29cf7c9abeef395ee0f78ebc1a49"

curl --fail --silent --show-error --location --output "$box_phar" https://github.com/box-project/box/releases/download/4.7.1/box.phar
echo "$box_sha256  $box_phar" | sha256sum --check

php "$box_phar" compile --config=box.json.dist

if [[ -n "${PARATEST_EXPECTED_VERSION:-}" ]]; then
    test "$(php build/paratest.phar --version)" = "ParaTest $PARATEST_EXPECTED_VERSION"
fi

php -r '$phar = new Phar($argv[1]); foreach (new RecursiveIteratorIterator($phar) as $file) { if (str_contains($file->getPathName(), "/vendor/phpunit/phpunit/")) { exit(1); } }' build/paratest.phar

php build/paratest.phar --no-configuration --processes=2 --no-coverage test/fixtures/common_results/SuccessTest.php
php build/paratest.phar "$repo_root/vendor/phpunit/phpunit/phpunit" --no-configuration --processes=2 --no-coverage test/fixtures/common_results/SuccessTest.php

smoke_dir="$tmp_dir/project"
mkdir "$smoke_dir"
cp test/fixtures/common_results/SuccessTest.php "$smoke_dir/SuccessTest.php"
curl --fail --silent --show-error --location --output "$smoke_dir/phpunit.phar" "$phpunit_url"
echo "$phpunit_sha256  $smoke_dir/phpunit.phar" | sha256sum --check

(cd "$smoke_dir" && php "$repo_root/build/paratest.phar" "$smoke_dir/phpunit.phar" --no-configuration --processes=2 --no-coverage "$smoke_dir/SuccessTest.php")
(cd "$smoke_dir" && php "$repo_root/build/paratest.phar" -dpcov.enabled=1 "$smoke_dir/phpunit.phar" --no-configuration --processes=2 --no-coverage "$smoke_dir/SuccessTest.php")
(cd "$smoke_dir" && php "$repo_root/build/paratest.phar" "$smoke_dir/phpunit.phar" --no-configuration --filter testSuccess "$smoke_dir/SuccessTest.php")

(cd "$smoke_dir" && php "$repo_root/build/paratest.phar" --version)
if php build/paratest.phar "$smoke_dir/missing/phpunit.phar" --no-configuration --processes=2 --no-coverage test/fixtures/common_results/SuccessTest.php; then
    echo "ParaTest accepted an unavailable PHPUnit PHAR despite the explicit path." >&2
    exit 1
fi
