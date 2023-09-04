<?php

declare(strict_types=1);

namespace SlamPgsql;

use PgSql\Connection;

final class Psql implements PsqlInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $database,
    ) {
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

        $pgConnectionParams = sprintf(
            'host=%s port=%s user=%s password=%s',
            $this->host,
            $this->port,
            $this->username,
            $this->password,
        );
        if (null !== $this->database) {
            $pgConnectionParams .= ' dbname='.$this->database;
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
            if (str_starts_with($line, '--')) {
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
            fwrite($outputStream, sprintf("%s\n", implode("\t", $row)));
        }
        pg_free_result($pgResult);

        return true;
    }
}
