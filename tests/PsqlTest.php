<?php

declare(strict_types=1);

namespace SlamPsql\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SlamPsql\Psql;

/**
 * @internal
 */
#[CoversClass(Psql::class)]
final class PsqlTest extends TestCase
{
    /** @var non-empty-string */
    private string $databaseHostname;
    private Psql $psql;

    protected function setUp(): void
    {
        $this->databaseHostname = false !== getenv('CI') ? '127.0.0.1' : 'database';

        $this->psql = new Psql(
            $this->databaseHostname,
            5432,
            'postgres',
            'root_password',
            'postgres',
            5,
        );
    }

    /**
     * @param non-empty-string                $extectedError
     * @param array<non-empty-string, string> $opts
     * @param array<non-empty-string, string> $env
     */
    #[DataProvider('provideCliHelpCases')]
    public function testCliHelp(string $extectedError, array $opts, array $env = []): void
    {
        $outputFile = tmpfile();
        self::assertIsResource($outputFile);

        self::assertNull(Psql::fromGetopt($opts, $env, $outputFile));

        rewind($outputFile);
        $contents = stream_get_contents($outputFile);
        self::assertNotFalse($contents);
        self::assertStringContainsString('Usage:', $contents);
        self::assertStringContainsString($extectedError, $contents);
    }

    /**
     * @return list<list<array<non-empty-string, false|string>|non-empty-string>>
     */
    public static function provideCliHelpCases(): iterable
    {
        return [
            ['Usage:', ['help' => false]],
            ['HOST missing', ['host' => '']],
            ['HOST missing', ['h' => '']],
            ['PORT missing', ['host' => 'foo', 'port' => '']],
            ['PORT missing', ['h' => 'foo', 'p' => '']],
            ['USER missing', ['host' => 'foo', 'port' => '123', 'username' => '']],
            ['USER missing', ['h' => 'foo', 'p' => '123', 'U' => '']],
            ['DATABASE missing', ['host' => 'foo', 'port' => '123', 'username' => 'bar', 'dbname' => '']],
            ['DATABASE missing', ['h' => 'foo', 'p' => '123', 'U' => 'bar', 'd' => '']],
            ['CONNECT_TIMEOUT', ['host' => 'foo', 'port' => '123', 'username' => 'bar', 'connect_timeout' => '-1']],
            ['PASSWORD missing', ['host' => 'foo', 'port' => '123', 'username' => 'bar', 'password' => 'l33t']],
            ['PASSWORD missing', ['host' => 'foo', 'port' => '123', 'username' => 'bar'], ['password' => 'l33t']],
            ['PASSWORD missing', ['host' => 'foo', 'port' => '123', 'username' => 'bar'], ['PGPASSWORD' => '']],
        ];
    }

    public function testCliFactory(): void
    {
        $outputFileForFactory = tmpfile();
        self::assertIsResource($outputFileForFactory);

        $psql = Psql::fromGetopt([
            'host' => $this->databaseHostname,
            'port' => '5432',
            'username' => 'postgres',
            'dbname' => 'postgres',
            'connect_timeout' => '5',
        ], [
            'PGPASSWORD' => 'root_password',
        ], $outputFileForFactory);

        self::assertNotNull($psql);
    }

    public function testErroneousConnectionParameters(): void
    {
        $user = uniqid('root_');
        $psql = new Psql(
            $this->databaseHostname,
            5432,
            $user,
            uniqid(),
            null,
            null,
        );

        [$inputFile, $outputFile, $errorFile] = $this->createStreams('SELECT datname FROM pg_database');
        self::assertFalse($psql->run($inputFile, $outputFile, $errorFile));

        rewind($errorFile);
        self::assertStringContainsString($user, stream_get_contents($errorFile));
    }

    public function testReadDataFromInputAndReturnOutput(): void
    {
        $databaseName = uniqid('db_');

        [$inputFile, $outputFile, $errorFile] = $this->createStreams(\sprintf('CREATE DATABASE %s', $databaseName));
        self::assertTrue($this->psql->run($inputFile, $outputFile, $errorFile));

        rewind($outputFile);
        self::assertEmpty(stream_get_contents($outputFile));

        [$inputFile, $outputFile, $errorFile] = $this->createStreams('SELECT datname FROM pg_database');
        self::assertTrue($this->psql->run($inputFile, $outputFile, $errorFile));

        rewind($outputFile);
        self::assertStringContainsString($databaseName, stream_get_contents($outputFile));
    }

    public function testHandleMultipleQueries(): void
    {
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(implode(PHP_EOL, [
            'SELECT 1;',
            'SELECT 2;',
        ]));
        self::assertTrue($this->psql->run($inputFile, $outputFile, $errorFile));
    }

    public function testHandleSemicolonWithinText(): void
    {
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(
            'SELECT \'Hel\'\'lo
            ;
            \';',
        );
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

    public function testSkipRestrictLines(): void
    {
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(implode(PHP_EOL, [
            '\restrict 5UEGLb9HU6QA63x35MgWqzbmletmlvUzXxHZpV9OjQoTPDK2KmSZIGhFUkGJG5U',
            'SELECT 1;',
            '\unrestrict 5UEGLb9HU6QA63x35MgWqzbmletmlvUzXxHZpV9OjQoTPDK2KmSZIGhFUkGJG5U',
        ]));
        self::assertTrue($this->psql->run($inputFile, $outputFile, $errorFile));
    }

    public function testReportSpecificQueryOnError(): void
    {
        $wrongQuery = \sprintf('SLEECT foooo_%s', uniqid());
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(implode(PHP_EOL, [
            'SELECT 1;',
            $wrongQuery.';',
        ]));
        self::assertFalse($this->psql->run($inputFile, $outputFile, $errorFile));

        rewind($errorFile);
        $output = stream_get_contents($errorFile);
        self::assertStringContainsString($wrongQuery, $output);
        self::assertStringNotContainsString('SELECT 1', $output);
    }

    public function testReportSpecificQueryOnErrorInEndingFile(): void
    {
        $wrongQuery = \sprintf('SLEECT foooo_%s', uniqid());
        [$inputFile, $outputFile, $errorFile] = $this->createStreams(implode(PHP_EOL, [
            'SELECT 1;',
            $wrongQuery,
        ]));
        self::assertFalse($this->psql->run($inputFile, $outputFile, $errorFile));

        rewind($errorFile);
        $output = stream_get_contents($errorFile);
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
