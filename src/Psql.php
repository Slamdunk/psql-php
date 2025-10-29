<?php

declare(strict_types=1);

namespace SlamPsql;

use PgSql\Connection;

final readonly class Psql implements PsqlInterface
{
    private const string HELP = <<<'EOF'

        Usage: psql [OPTIONS]
          -h, --host              Connect to host
          -p, --port              Port number
          -U, --username          User for login
          -d, --dbname            Database to use
              --connect_timeout   Connect timeout to use, in seconds

          Use `PGPASSWORD` env variable to set the connection password.

        EOF;

    /**
     * @param non-empty-string      $host
     * @param positive-int          $port
     * @param non-empty-string      $username
     * @param non-empty-string      $password
     * @param null|non-empty-string $database
     * @param null|positive-int     $connectTimeout
     */
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private ?string $database,
        private ?int $connectTimeout,
    ) {}

    /**
     * @codeCoverageIgnore
     *
     * @return array<non-empty-string, string>
     */
    public static function getopt(): array
    {
        return getopt('h:p:U:d:', [
            'help',
            'host:',
            'port:',
            'username:',
            'dbname:',
            'connect_timeout:',
        ]);
    }

    /**
     * @param array<non-empty-string, string> $opts
     * @param array{PGPASSWORD: ?string}      $env
     * @param resource                        $stdout
     */
    public static function fromGetopt(array $opts, array $env, $stdout): ?self
    {
        if (\array_key_exists('help', $opts)) {
            fwrite($stdout, self::HELP);

            return null;
        }

        $host = $opts['host'] ?? $opts['h'] ?? null;
        if (null === $host || '' === $host) {
            fwrite($stdout, 'HOST missing'.PHP_EOL);
            fwrite($stdout, self::HELP);

            return null;
        }

        $port = (int) ($opts['port'] ?? $opts['p'] ?? 0);
        if (0 >= $port) {
            fwrite($stdout, 'PORT missing'.PHP_EOL);
            fwrite($stdout, self::HELP);

            return null;
        }

        $username = $opts['username'] ?? $opts['U'] ?? null;
        if (null === $username || '' === $username) {
            fwrite($stdout, 'USER missing'.PHP_EOL);
            fwrite($stdout, self::HELP);

            return null;
        }

        $database = $opts['dbname'] ?? $opts['d'] ?? null;
        if ('' === $database) {
            fwrite($stdout, 'DATABASE missing'.PHP_EOL);
            fwrite($stdout, self::HELP);

            return null;
        }

        $connectTimeout = $opts['connect_timeout'] ?? null;
        if (null !== $connectTimeout) {
            $connectTimeout = (int) $connectTimeout;
            if (1 > $connectTimeout) {
                fwrite($stdout, 'CONNECT_TIMEOUT must be greater than zero'.PHP_EOL);
                fwrite($stdout, self::HELP);

                return null;
            }
        }

        $password = $env['PGPASSWORD'] ?? null;
        if (null === $password || '' === $password) {
            fwrite($stdout, 'PASSWORD missing'.PHP_EOL);
            fwrite($stdout, self::HELP);

            return null;
        }

        return new self(
            $host,
            $port,
            $username,
            $password,
            $database,
            $connectTimeout,
        );
    }

    /**
     * @param resource $inputStream
     * @param resource $outputStream
     * @param resource $errorStream
     */
    public function run($inputStream, $outputStream, $errorStream): bool
    {
        $read = [$inputStream];
        $write = [];
        $except = [];
        $result = stream_select($read, $write, $except, 0);
        if (false === $result) {
            // @codeCoverageIgnoreStart
            fwrite($errorStream, 'stream_select failed'.PHP_EOL);

            return false;
            // @codeCoverageIgnoreEnd
        }
        if (0 === $result) {
            // @codeCoverageIgnoreStart
            fwrite($errorStream, 'Input stream is empty'.PHP_EOL);

            return false;
            // @codeCoverageIgnoreEnd
        }

        $pgConnectionParams = \sprintf(
            'host=%s port=%s user=%s password=%s',
            $this->host,
            $this->port,
            $this->username,
            $this->password,
        );
        if (null !== $this->database) {
            $pgConnectionParams .= ' dbname='.$this->database;
        }
        if (null !== $this->connectTimeout) {
            $pgConnectionParams .= \sprintf(' connect_timeout=%s', $this->connectTimeout);
        }

        set_error_handler(static function ($severity, $message, $file, $line): void {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }, E_WARNING);

        try {
            $pgConnection = pg_connect($pgConnectionParams);
        } catch (\ErrorException $errorException) {
            fwrite($errorStream, $errorException->getMessage());

            return false;
        } finally {
            restore_error_handler();
        }

        \assert(false !== $pgConnection);

        $query = '';
        while (false !== ($line = fgets($inputStream))) {
            if (
                str_starts_with($line, '--')
                || str_starts_with($line, '\restrict ')
                || str_starts_with($line, '\unrestrict ')
            ) {
                continue;
            }

            $query .= $line;
            if (1 !== preg_match('/;\s*$/', $query)) {
                continue;
            }
            $query = trim($query);
            $query = rtrim($query, ';');

            if (true !== $this->executeQuery($query, $pgConnection, $outputStream, $errorStream)) {
                return false;
            }

            $query = '';
        }

        if ('' !== trim($query)) {
            if (true !== $this->executeQuery($query, $pgConnection, $outputStream, $errorStream)) {
                return false;
            }
        }

        pg_close($pgConnection);

        return true;
    }

    /**
     * @param resource $outputStream
     * @param resource $errorStream
     */
    private function executeQuery(string $query, Connection $pgConnection, $outputStream, $errorStream): bool
    {
        $pgResult = @pg_query($pgConnection, $query);
        if (false === $pgResult) {
            fwrite($errorStream, pg_last_error($pgConnection));

            return false;
        }

        while ($row = pg_fetch_row($pgResult)) {
            fwrite($outputStream, \sprintf("%s\n", implode("\t", $row)));
        }
        pg_free_result($pgResult);

        return true;
    }
}
