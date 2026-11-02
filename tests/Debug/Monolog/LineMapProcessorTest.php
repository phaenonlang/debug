<?php

declare(strict_types=1);

namespace Phaenon\Tests\Debug\Monolog;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Phaenon\Debug\Monolog\LineMapProcessor;
use Phaenon\Tests\Debug\Fixture\Greeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixture/build/Greeter.php';

/**
 * The processor in a real Monolog 3 logger, written by the real line and
 * JSON formatters: what they print must be source positions.
 */
final class LineMapProcessorTest extends TestCase
{
    #[Test]
    public function theLineFormatterWritesSourcePositions(): void
    {
        $formatter = new LineFormatter(null, null, true);
        $formatter->includeStacktraces();
        $output = self::log($formatter, 'greeting failed', self::thrown(static fn () => (new Greeter())->greetOrFail('')));

        self::assertStringContainsString('(RuntimeException(code: 0): greeting failed at ' . self::source() . ':19)', $output);
        self::assertStringContainsString('(InvalidArgumentException(code: 0): no name at ' . self::source() . ':7)', $output);
        self::assertStringContainsString('#0 ' . self::source() . '(17): ', $output);
        self::assertStringNotContainsString('build/Greeter.php', $output);
    }

    #[Test]
    public function theJsonFormatterWritesSourcePositions(): void
    {
        $formatter = new JsonFormatter(includeStacktraces: true);
        $output = self::log($formatter, 'greeting failed', self::thrown(static fn () => (new Greeter())->greetAll([''])));
        /** @var array{context: array{exception: array{file: string, trace: list<string>}}} $record */
        $record = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $exception = $record['context']['exception'];

        self::assertSame(self::source() . ':7', $exception['file']);
        self::assertSame(self::source() . ':13', $exception['trace'][0]);
        self::assertStringNotContainsString('build/Greeter.php', $output);
    }

    #[Test]
    public function quotesTheSourcePositionWhereTheMessageQuotesTheCompiledOne(): void
    {
        $e = self::thrown(static fn () => (new Greeter())->greetOrFail(''));
        $processor = new LineMapProcessor();

        $symfony = $processor(self::record('Uncaught PHP Exception RuntimeException: "greeting failed" at Greeter.php line 34', $e));
        $monolog = $processor(self::record('Uncaught Exception RuntimeException: "greeting failed" at ' . self::compiled() . ' line 34', $e));
        $trace = $processor(self::record('failed in Greeter.php(34), from Greeter.php:14', $e));
        $other = $processor(self::record('see Greeter.php line 340, XGreeter.php line 34, Greeter.php line 35', $e));

        self::assertSame('Uncaught PHP Exception RuntimeException: "greeting failed" at Greeter.phn line 19', $symfony->message);
        self::assertSame('Uncaught Exception RuntimeException: "greeting failed" at ' . self::source() . ' line 19', $monolog->message);
        self::assertSame('failed in Greeter.phn(19), from Greeter.phn:7', $trace->message, 'the previous exception\'s position too');
        self::assertSame('see Greeter.php line 340, XGreeter.php line 34, Greeter.php line 35', $other->message);
    }

    #[Test]
    public function leavesARecordWithoutAnExceptionAlone(): void
    {
        $processor = new LineMapProcessor();
        $plain = self::record('nothing failed', null);
        $notThrowable = new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'at Greeter.php line 34', ['exception' => 'a string']);

        self::assertSame($plain, $processor($plain));
        self::assertSame($notThrowable, $processor($notThrowable));
    }

    #[Test]
    public function leavesTheRecordAloneWhenItsExceptionHasNoMap(): void
    {
        $record = self::record('failed', new \RuntimeException('here'));

        self::assertSame($record, (new LineMapProcessor())($record));
    }

    private static function log(LineFormatter|JsonFormatter $formatter, string $message, \Throwable $e): string
    {
        $handler = new TestHandler();
        $handler->setFormatter($formatter);
        $logger = new Logger('app', [$handler], [new LineMapProcessor()]);
        $logger->error($message, ['exception' => $e]);
        $formatted = $handler->getRecords()[0]->formatted;
        self::assertIsString($formatted);

        return $formatted;
    }

    private static function record(string $message, ?\Throwable $e): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, $message, $e === null ? [] : ['exception' => $e]);
    }

    private static function source(): string
    {
        return \dirname(__DIR__) . '/Fixture/src/Greeter.phn';
    }

    private static function compiled(): string
    {
        return \dirname(__DIR__) . '/Fixture/build/Greeter.php';
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function thrown(\Closure $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }
        self::fail('nothing was thrown');
    }
}
