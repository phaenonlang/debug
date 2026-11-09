<?php

declare(strict_types=1);

namespace Phaenon\Tests\Debug\Sentry;

use Phaenon\Debug\Sentry\LineMapIntegration;
use Phaenon\Debug\ThrowableRewriter;
use Phaenon\Tests\Debug\Fixture\Greeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Frame;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\SentrySdk;
use Sentry\Stacktrace;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

require_once __DIR__ . '/../Fixture/build/Greeter.php';

/**
 * The integration in a real Sentry SDK 4 client, beside the default
 * FrameContextifierIntegration registered before it (the order a list in
 * the `integrations` option gives), with a transport that records events.
 */
final class LineMapIntegrationTest extends TestCase
{
    private RecordingTransport $transport;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub(new Hub());
        if ($this->dir !== '') {
            foreach (['build/callback.php', 'build', ''] as $path) {
                $path === 'build/callback.php' ? unlink($this->dir . '/' . $path) : rmdir($this->dir . '/' . $path);
            }
        }
    }

    #[Test]
    public function framesOfAClientWithoutTheIntegrationStayCompiled(): void
    {
        $this->hub(withIntegration: false)->captureException(self::thrown(static fn () => (new Greeter())->greet('')));
        $top = self::topFrame($this->event(), 0);

        self::assertSame('/tests/Debug/Fixture/build/Greeter.php', $top->getFile());
        self::assertSame(14, $top->getLine());
    }

    #[Test]
    public function pointsTheFramesOfEveryExceptionAtTheSource(): void
    {
        $this->hub()->captureException(self::thrown(static fn () => (new Greeter())->greetOrFail('')));
        $event = $this->event();
        $outer = self::topFrame($event, 0);
        $inner = self::topFrame($event, 1);

        self::assertSame('/tests/Debug/Fixture/src/Greeter.phn', $outer->getFile());
        self::assertSame(self::root() . '/tests/Debug/Fixture/src/Greeter.phn', $outer->getAbsoluteFilePath());
        self::assertSame(19, $outer->getLine());
        self::assertSame('Phaenon\Tests\Debug\Fixture\Greeter::greetOrFail', $outer->getFunctionName());
        self::assertSame([7, 'Phaenon\Tests\Debug\Fixture\Greeter::greet'], [$inner->getLine(), $inner->getFunctionName()]);
        $frames = self::stacktrace($event, 1)->getFrames();
        $caller = $frames[\count($frames) - 2];
        self::assertSame(['/tests/Debug/Fixture/src/Greeter.phn', 17], [$caller->getFile(), $caller->getLine()]);
    }

    #[Test]
    public function readsTheContextLinesFromTheSource(): void
    {
        $this->hub()->captureException(self::thrown(static fn () => (new Greeter())->greetOrFail('')));
        $top = self::topFrame($this->event(), 0);

        self::assertSame("            throw new \\RuntimeException('greeting failed', 0, \$e);", $top->getContextLine());
        self::assertSame(['        try {', '            return $this->greet($name);', '        } catch (\InvalidArgumentException $e) {'], \array_slice($top->getPreContext(), -3));
        self::assertCount(5, $top->getPreContext());
        self::assertSame(['        }', '    }', '', '    share(int $cakes, int $guests): int => intdiv($cakes, $guests);', '}'], $top->getPostContext());
    }

    #[Test]
    public function translatesClosureNames(): void
    {
        $this->hub()->captureException(self::thrown(static fn () => (new Greeter())->greetAll([''])));
        $closures = array_values(array_filter(
            self::stacktrace($this->event(), 0)->getFrames(),
            static fn (Frame $frame): bool => str_contains((string) $frame->getFunctionName(), 'greetAll():'),
        ));

        self::assertCount(1, $closures);
        self::assertSame('Phaenon\Tests\Debug\Fixture\Greeter::{closure:Phaenon\Tests\Debug\Fixture\Greeter::greetAll():13}', $closures[0]->getFunctionName());
        self::assertSame('Phaenon\Tests\Debug\Fixture\Greeter::{closure:Phaenon\Tests\Debug\Fixture\Greeter::greetAll():13}', $closures[0]->getRawFunctionName());
        self::assertSame(['/tests/Debug/Fixture/src/Greeter.phn', 13], [$closures[0]->getFile(), $closures[0]->getLine()]);
    }

    #[Test]
    public function keepsInAppAsSentryComputedIt(): void
    {
        $this->hub(['in_app_exclude' => [self::root() . '/tests/Debug/Fixture/build']])->captureException(self::thrown(static fn () => (new Greeter())->greetOrFail('')));
        $frames = self::stacktrace($this->event(), 0)->getFrames();
        $top = $frames[\count($frames) - 1];
        $test = $frames[\count($frames) - 2];

        self::assertSame(['/tests/Debug/Fixture/src/Greeter.phn', false], [$top->getFile(), $top->isInApp()]);
        self::assertSame(['/tests/Debug/Sentry/LineMapIntegrationTest.php', true], [$test->getFile(), $test->isInApp()]);
    }

    #[Test]
    public function keepsTheCompiledExcerptWhenTheSourceIsNotDeployed(): void
    {
        $this->dir = realpath(sys_get_temp_dir()) . '/phaenon-sentry-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/build', recursive: true);
        copy(__DIR__ . '/../Fixture/build/callback.php', $this->dir . '/build/callback.php');
        $fail = require $this->dir . '/build/callback.php';
        self::assertInstanceOf(\Closure::class, $fail);
        $this->hub()->captureException(self::thrown(static fn () => $fail('stop')));
        $top = self::topFrame($this->event(), 0);

        self::assertSame($this->dir . '/src/callback.phn', $top->getAbsoluteFilePath());
        self::assertSame(2, $top->getLine());
        self::assertSame('    throw new \LogicException($reason);', $top->getContextLine());
        self::assertSame(['', 'declare(strict_types=1);', '', 'return static function (string $reason): never {'], \array_slice($top->getPreContext(), -4));
    }

    #[Test]
    public function leavesAThrowableTheMonologProcessorRewroteAsItIs(): void
    {
        $e = self::thrown(static fn () => (new Greeter())->greet(''));
        ThrowableRewriter::rewrite($e);
        $this->hub()->captureException($e);
        $top = self::topFrame($this->event(), 0);

        self::assertSame(['/tests/Debug/Fixture/src/Greeter.phn', 7], [$top->getFile(), $top->getLine()]);
        self::assertSame("            throw new \\InvalidArgumentException('no name');", $top->getContextLine());
    }

    #[Test]
    public function pointsTheEventsOwnStacktraceAtTheSource(): void
    {
        $compiled = self::root() . '/tests/Debug/Fixture/build/Greeter.php';
        $event = Event::createEvent();
        $event->setStacktrace(new Stacktrace([new Frame('greet', '/tests/Debug/Fixture/build/Greeter.php', 14, null, $compiled)]));
        $this->hub()->captureEvent($event);
        $frames = $this->event()->getStacktrace()?->getFrames() ?? [];

        self::assertCount(1, $frames);
        self::assertSame(['/tests/Debug/Fixture/src/Greeter.phn', 7], [$frames[0]->getFile(), $frames[0]->getLine()]);
        self::assertSame("            throw new \\InvalidArgumentException('no name');", $frames[0]->getContextLine());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function hub(array $options = [], bool $withIntegration = true): HubInterface
    {
        $integrations = [new FrameContextifierIntegration()];
        if ($withIntegration) {
            $integrations[] = new LineMapIntegration();
        }
        $client = ClientBuilder::create($options + [
            'dsn' => 'http://public@example.com/1',
            'default_integrations' => false,
            'integrations' => $integrations,
            'prefixes' => [self::root()],
        ])->setTransport($this->transport)->getClient();
        $hub = new Hub($client);
        SentrySdk::setCurrentHub($hub);

        return $hub;
    }

    private function event(): Event
    {
        self::assertCount(1, $this->transport->events);

        return $this->transport->events[0];
    }

    private static function stacktrace(Event $event, int $exception): Stacktrace
    {
        $stacktrace = $event->getExceptions()[$exception]->getStacktrace();
        self::assertNotNull($stacktrace);

        return $stacktrace;
    }

    /** The frame that threw: Sentry orders frames from caller to callee. */
    private static function topFrame(Event $event, int $exception): Frame
    {
        $frames = self::stacktrace($event, $exception)->getFrames();

        return $frames[\count($frames) - 1];
    }

    /** The repository root, the `prefixes` option: Sentry strips it from the paths it shows, leaving the slash. */
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
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
