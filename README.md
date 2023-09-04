# `psql` in PHP

[![Latest Stable Version](https://img.shields.io/packagist/v/slam/psql-php.svg)](https://packagist.org/packages/slam/psql-php)
[![Downloads](https://img.shields.io/packagist/dt/slam/psql-php.svg)](https://packagist.org/packages/slam/psql-php)
[![Integrate](https://github.com/Slamdunk/psql-php/workflows/CI/badge.svg?branch=master)](https://github.com/Slamdunk/psql-php/actions)
[![Code Coverage](https://codecov.io/gh/Slamdunk/psql-php/coverage.svg?branch=master)](https://codecov.io/gh/Slamdunk/psql-php?branch=master)

PHP light version of `psql` that comes with PostgreSQL.

## Why

1. You are inside a PHP only environment, like a PHP Docker image
1. You need to import a large `pg_dump --inserts` dump
1. You don't have access to the native `psql` client

## Performance

Speed is exactly the **same** of the original `psql` binary thanks to streams usage.

## Supported formats

| Input type                                             | Example                                     |     Supported?     |
|--------------------------------------------------------|---------------------------------------------|:------------------:|
| `pg_dump` output (with `COPY` commands)                | *as is*                                     |        :x:         |
| `pg_dump --inserts` output                             | *as is*                                     | :heavy_check_mark: |
| Single query on single line                            | `SELECT NOW();`                             | :heavy_check_mark: |
| Single query on multiple lines                         | `SELECT`<br />`NOW();`                      | :heavy_check_mark: |
| Multiple queries on separated single or multiple lines | `SELECT NOW();`<br />`SELECT`<br />`NOW();` | :heavy_check_mark: |
| Multiple queries on single line                        | `SELECT NOW();SELECT NOW();`                |        :x:         |

When using `pg_dump --inserts` it is highly recommended to also set `--rows-per-insert=1000` to speed performances up.

## Usage

The library provides two usages, the binary and the `\SlamPsql\Psql` class.

### From CLI

```
$ ./psql -h
Usage: psql [OPTIONS]
  --host       Connect to host
  --port       Port number
  --username   User for login
  --password   Password to use
  --database   Database to use

$ printf "CREATE DATABASE foobar;\nSELECT datname FROM pg_database;" | ./psql
foobar

$ ./psql --database foobar < foobar_huge_dump.sql
```

### From PHP

```php
$psql = new \SlamPsql\Psql('localhost', 5432, 'my_username', 'my_password', 'my_database');
$return = $psql->run(\STDIN, \STDOUT, \STDERR);
exit((int) (true !== $return));
```

`\SlamPsql\Psql::run` accepts any type of resource consumable by `fgets/fwrite` functions.
