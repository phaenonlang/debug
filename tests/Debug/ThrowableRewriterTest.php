<?php

declare(strict_types=1);

namespace Phaenon\Tests\Debug;

use Phaenon\Debug\ThrowableRewriter;
use Phaenon\Tests\Debug\Fixture\Greeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/build/Greeter.php';

/**
 * Throwables thrown from the hand-written compiled fixture
 * (Fixture/build/Greeter.php, its source Fixture/src/Greeter.phn), pointed
 * at the source in place.
 */
final class ThrowableRewriterTest extends TestCase
{
    private const SOURCE = __DIR__ . '/Fixture/src/Greeter.phn';

    private const COMPILED = __DIR__ . '/Fixture/build/Greeter.php';

    #[Test]
    public function pointsTheThrowablePositionAtTheSource(): void
    {
        $e = self::thrown(static fn () => (new Greeter())->greet(''));

        self::assertSame([self::COMPILED, 14], [$e->getFile(), $e->getLine()]);
        ThrowableRewriter::rewrite($e);

        self::assertSame([self::SOURCE, 7], [$e->getFile(), $e->getLine()]);
        self::assertSame([self::COMPILED, 14], ThrowableRewriter::compiledPosition($e));
        self::assertSame(__FILE__, $e->getTrace()[0]['file'] ?? null, 'a frame in a file without a map stays');
    }

    #[Test]
    public function pointsEveryFrameAndClosureNameAtTheSource(): void
    {
        $e = self::thrown(static fn () => (new Greeter())->greetAll(['Ann', '']));
        ThrowableRewriter::rewrite($e);
        $trace = $e->getTrace();

        self::assertSame(['greet', self::SOURCE, 13], [$trace[0]['function'], $trace[0]['file'] ?? null, $trace[0]['line'] ?? null]);
        self::assertSame('{closure:Phaenon\Tests\Debug\Fixture\Greeter::greetAll():13}', $trace[1]['function']);
        self::assertArrayNotHasKey('file', $trace[1], 'array_map called the closure');
        self::assertSame(['array_map', self::SOURCE, 13], [$trace[2]['function'], $trace[2]['file'] ?? null, $trace[2]['line'] ?? null]);
        self::assertSame(['greetAll', __FILE__], [$trace[3]['function'], $trace[3]['file'] ?? null]);
        self::assertStringContainsString('#0 ' . self::SOURCE . '(13): Phaenon\Tests\Debug\Fixture\Greeter->greet(', $e->getTraceAsString());
        self::assertStringContainsString('InvalidArgumentException: no name in ' . self::SOURCE . ':7', (string) $e);
        self::assertStringNotContainsString(self::COMPILED, (string) $e);
    }

    #[Test]
    public function pointsThePreviousChainAtTheSource(): void
    {
        $e = self::thrown(static fn () => (new Greeter())->greetOrFail(''));
        ThrowableRewriter::rewrite($e);
        $previous = $e->getPrevious();

        self::assertSame([self::SOURCE, 19], [$e->getFile(), $e->getLine()]);
        self::assertNotNull($previous);
        self::assertSame([self::SOURCE, 7], [$previous->getFile(), $previous->getLine()]);
        self::assertSame([self::SOURCE, 17], [$previous->getTrace()[0]['file'] ?? null, $previous->getTrace()[0]['line'] ?? null]);
        self::assertSame([self::COMPILED, 14], ThrowableRewriter::compiledPosition($previous));
    }

    #[Test]
    public function pointsAnErrorAtTheSource(): void
    {
        $e = self::thrown(static fn () => (new Greeter())->share(3, 0));
        ThrowableRewriter::rewrite($e);

        self::assertInstanceOf(\DivisionByZeroError::class, $e);
        self::assertSame([self::SOURCE, 23], [$e->getFile(), $e->getLine()]);
        self::assertSame(['intdiv', self::SOURCE, 23], [$e->getTrace()[0]['function'], $e->getTrace()[0]['file'] ?? null, $e->getTrace()[0]['line'] ?? null]);
    }

    #[Test]
    public function pointsATopLevelClosureNameAtTheSource(): void
    {
        $fail = require __DIR__ . '/Fixture/build/callback.php';
        self::assertInstanceOf(\Closure::class, $fail);
        $e = self::thrown(static fn () => $fail('stop'));
        ThrowableRewriter::rewrite($e);

        $source = __DIR__ . '/Fixture/src/callback.phn';
        self::assertSame([$source, 2], [$e->getFile(), $e->getLine()]);
        self::assertSame('{closure:' . $source . ':1}', $e->getTrace()[0]['function']);
    }

    #[Test]
    public function rewritesAThrowableOnce(): void
    {
        $e = self::thrown(static fn () => (new Greeter())->greetAll(['']));
        ThrowableRewriter::rewrite($e);
        $once = [$e->getFile(), $e->getLine(), $e->getTraceAsString()];
        ThrowableRewriter::rewrite($e);

        self::assertSame($once, [$e->getFile(), $e->getLine(), $e->getTraceAsString()]);
        self::assertSame([self::COMPILED, 14], ThrowableRewriter::compiledPosition($e));
    }

    #[Test]
    public function leavesAThrowableWithoutAMapAlone(): void
    {
        $e = new \RuntimeException('here');
        $before = [$e->getFile(), $e->getLine(), $e->getTraceAsString()];
        ThrowableRewriter::rewrite($e);

        self::assertSame($before, [$e->getFile(), $e->getLine(), $e->getTraceAsString()]);
        self::assertNull(ThrowableRewriter::compiledPosition($e));
        self::assertNull(ThrowableRewriter::compiledPosition(new \LogicException('never rewritten')));
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
