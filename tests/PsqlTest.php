<?php

declare(strict_types=1);

namespace SlamPsql\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SlamPsql\Psql;

/**
 * @internal
 */
#[CoversClass(Psql::class)]
final class PsqlTest extends TestCase
{
    private Psql $psql;

    protected function setUp(): void
    {
        $this->psql = new Psql(
            '127.0.0.1',
            5432,
            'postgres',
            'root_password',
            'postgres',
        );
    }

    public function testErroneousConnectionParameters(): void
    {
        $user = uniqid('root_');
        $psql = new Psql(
            '127.0.0.1',
            5432,
            $user,
            uniqid(),
            null,
        );

        [$inputFile, $outputFile, $errorFile] = $this->createStreams('SELECT datname FROM pg_database');
        self::assertFalse($psql->run($inputFile, $outputFile, $errorFile));

        rewind($errorFile);
        self::assertStringContainsString($user, (string) stream_get_contents($errorFile));
    }

    public function testReadDataFromInputAndReturnOutput(): void
    {
        $databaseName = uniqid('db_');

        [$inputFile, $outputFile, $errorFile] = $this->createStreams(sprintf('CREATE DATABASE %s', $databaseName));
        self::assertTrue($this->psql->run($inputFile, $outputFile, $errorFile));

        rewind($outputFile);
        self::assertEmpty(stream_get_contents($outputFile));

        [$inputFile, $outputFile, $errorFile] = $this->createStreams('SELECT datname FROM pg_database');
        self::assertTrue($this->psql->run($inputFile, $outputFile, $errorFile));

        rewind($outputFile);
        self::assertStringContainsString($databaseName, (string) stream_get_contents($outputFile));
    }

    public function testHandleMultipleQueries(): void
    {
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(implode(PHP_EOL, [
            'SELECT 1;',
            'SELECT 2;',
        ]));
        self::assertTrue($this->psql->run($inputFile, $outputFile, $errorFile));
    }

    public function testSkipCommentLines(): void
    {
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(implode(PHP_EOL, [
            'SELECT 1;',
            '-- foo '.uniqid(),
            'SELECT 2;',
        ]));
        self::assertTrue($this->psql->run($inputFile, $outputFile, $errorFile));
    }

    public function testReportSpecificQueryOnError(): void
    {
        $wrongQuery = sprintf('SLEECT foooo_%s', uniqid());
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(implode(PHP_EOL, [
            'SELECT 1;',
            $wrongQuery.';',
        ]));
        self::assertFalse($this->psql->run($inputFile, $outputFile, $errorFile));

        rewind($errorFile);
        $output = (string) stream_get_contents($errorFile);
        self::assertStringContainsString($wrongQuery, $output);
        self::assertStringNotContainsString('SELECT 1', $output);
    }

    public function testReportSpecificQueryOnErrorInEndingFile(): void
    {
        $wrongQuery = sprintf('SLEECT foooo_%s', uniqid());
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(implode(PHP_EOL, [
            'SELECT 1;',
            $wrongQuery,
        ]));
        self::assertFalse($this->psql->run($inputFile, $outputFile, $errorFile));

        rewind($errorFile);
        $output = (string) stream_get_contents($errorFile);
        self::assertStringContainsString($wrongQuery, $output);
        self::assertStringNotContainsString('SHOW VARIABLES', $output);
    }

    /**
     * @return resource[]
     */
    private function createStreams(string $input): array
    {
        $inputFile = tmpfile();
        self::assertIsResource($inputFile);
        fwrite($inputFile, $input);
        rewind($inputFile);

        $outputFile = tmpfile();
        self::assertIsResource($outputFile);

        $errorFile = tmpfile();
        self::assertIsResource($errorFile);

        return [$inputFile, $outputFile, $errorFile];
    }
}
