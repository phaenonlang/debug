<?php

declare(strict_types=1);

namespace Phaenon\Tests\Debug;

use Phaenon\Debug\LineMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The reader against the compiler repository's reference vectors (copied
 * here verbatim as vectors.json; the compiler's freshness test compares the
 * copies), then against files: the placements of design/line-map.md §3.1,
 * the per-process cache and the lexical resolution of the source path.
 */
final class LineMapTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixture';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phaenon-line-map-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @return array{
     *     valid: list<array{name: string, map: string, source: string, forward: list<array{int, int}>, reverse: list<array{int, int|null}>}>,
     *     unsupported: list<string>,
     *     invalid: list<string>
     * }
     */
    private static function vectors(): array
    {
        $json = file_get_contents(__DIR__ . '/vectors.json');
        self::assertIsString($json);
        /** @var array{valid: list<array{name: string, map: string, source: string, forward: list<array{int, int}>, reverse: list<array{int, int|null}>}>, unsupported: list<string>, invalid: list<string>} $vectors */
        $vectors = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $vectors;
    }

    /**
     * @return iterable<string, array{string, string, list<array{int, int}>, list<array{int, int|null}>}>
     */
    public static function validVectors(): iterable
    {
        foreach (self::vectors()['valid'] as $vector) {
            yield $vector['name'] => [$vector['map'], $vector['source'], $vector['forward'], $vector['reverse']];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedVectors(): iterable
    {
        foreach (self::vectors()['unsupported'] as $map) {
            yield 'unsupported: ' . $map => [$map];
        }
        foreach (self::vectors()['invalid'] as $map) {
            yield 'invalid: ' . $map => [$map];
        }
    }

    /**
     * @param list<array{int, int}> $forward
     * @param list<array{int, int|null}> $reverse
     */
    #[Test]
    #[DataProvider('validVectors')]
    public function readsTheReferenceVectors(string $line, string $source, array $forward, array $reverse): void
    {
        $map = LineMap::parse($line, '/app/.src/Domain/Foo.php');

        self::assertNotNull($map);
        self::assertSame($source, $map->sourcePath());
        foreach ($forward as [$compiled, $expected]) {
            self::assertSame($expected, $map->sourceLine($compiled), "forward $compiled");
        }
        foreach ($reverse as [$sourceLine, $expected]) {
            self::assertSame($expected, $map->compiledLine($sourceLine), "reverse $sourceLine");
        }
    }

    #[Test]
    #[DataProvider('rejectedVectors')]
    public function rejectsWhatTheVectorsReject(string $line): void
    {
        self::assertNull(LineMap::parse($line, '/app/.src/Foo.php'));
    }

    #[Test]
    public function rejectsAPercentSignThatEncodesNothing(): void
    {
        self::assertNull(LineMap::parse('// phaenon-map v1 100%.phn 1:1', '/app/.src/Foo.php'));
        self::assertNull(LineMap::parse('// phaenon-map v1 a%2Fb.phn 1:1', '/app/.src/Foo.php'));
        self::assertSame('100%20.phn', LineMap::parse('// phaenon-map v1 100%2520.phn 1:1', '/app/.src/Foo.php')?->sourcePath());
    }

    #[Test]
    public function readsACompiledFile(): void
    {
        $file = self::FIXTURE . '/build/Greeter.php';
        $map = LineMap::forFile($file);

        self::assertNotNull($map);
        self::assertSame($file, $map->compiledFile());
        self::assertSame('../src/Greeter.phn', $map->sourcePath());
        self::assertSame(self::FIXTURE . '/src/Greeter.phn', $map->sourceFile());
        self::assertSame(1, $map->sourceLine(3)); // the header holds the first source line
        self::assertSame(5, $map->sourceLine(12)); // the brace the compiler moved down
        self::assertSame(7, $map->sourceLine(14));
        self::assertSame(13, $map->sourceLine(21)); // the docblock the compiler wrote
        self::assertSame(13, $map->sourceLine(26)); // the expanded expression body
        self::assertSame(24, $map->sourceLine(42));
        self::assertSame(14, $map->compiledLine(7));
        self::assertSame(24, $map->compiledLine(13)); // the declaration, never its docblock
        self::assertSame([self::FIXTURE . '/src/Greeter.phn', 19], LineMap::locate($file, 34));
    }

    #[Test]
    public function aFileWithoutAMapHasNone(): void
    {
        self::assertNull(LineMap::forFile(__FILE__));
        self::assertNull(LineMap::forFile($this->dir . '/missing.php'));
        self::assertNull(LineMap::forFile($this->dir));
        self::assertNull(LineMap::forFile("class@anonymous\0/app/x.php"));
        self::assertNull(LineMap::forFile('[internal]'));
        self::assertNull(LineMap::locate(__FILE__, 10));
        self::assertNull(LineMap::forFile($this->write('v2.php', "<?php\necho 1;\n// phaenon-map v2 a.phn 1:1\n")));
        self::assertNull(LineMap::forFile($this->write('bad.php', "<?php\necho 1;\n// phaenon-map v1 a.phn 2:1\n")));
        self::assertNull(LineMap::forFile($this->write('empty.php', '')));
        self::assertNull(LineMap::forFile($this->write('last-line-empty.php', "<?php\n// phaenon-map v1 a.phn 1:1\n\n")));
    }

    #[Test]
    public function aMapIsReadOncePerFileAndProcess(): void
    {
        $file = $this->write('Foo.php', "<?php\necho 1;\n// phaenon-map v1 ../src/Foo.phn 1:1\n");
        $first = LineMap::forFile($file);
        file_put_contents($file, "<?php\n\necho 1;\n// phaenon-map v1 ../src/Foo.phn 1:2\n");

        self::assertNotNull($first);
        self::assertSame($first, LineMap::forFile($file));
        self::assertSame(2, $first->sourceLine(2));

        $none = $this->write('Bar.php', "<?php\necho 1;\n");
        self::assertNull(LineMap::forFile($none));
        file_put_contents($none, "<?php\necho 1;\n// phaenon-map v1 Bar.phn 1:1\n");
        self::assertNull(LineMap::forFile($none));
    }

    #[Test]
    public function readsTheSpellingOfAFileThatEndsInInlineHtml(): void
    {
        $file = $this->write('page.php', "<?php\n\n/* @generated by phaenon from page.phn */\n\n\$title = 'x';\n?>\n<h1><?= \$title ?></h1>\n<?php // phaenon-map v1 page.phn 1-4=1 5:1\n");

        self::assertSame(3, LineMap::forFile($file)?->sourceLine(7));
    }

    #[Test]
    public function readsTheLineBeforeHaltCompiler(): void
    {
        $data = "\x00\xFF\n// phaenon-map v1 wrong.phn 1:9\n\x01";
        $file = $this->write('tool.php', "<?php\n\n/* @generated by phaenon from tool.phn */\n\necho 1;\n// phaenon-map v1 tool.phn 1-4=1 5:1\n__HALT_COMPILER();" . $data);
        $map = LineMap::forFile($file);

        self::assertNotNull($map);
        self::assertSame('tool.phn', $map->sourcePath());
        self::assertSame(1, $map->sourceLine(5));
    }

    #[Test]
    public function aHandWrittenFileIsNeverSearchedForHaltCompiler(): void
    {
        $file = $this->write('tool.php', "<?php\necho 1;\n// phaenon-map v1 tool.phn 1:1\n__halt_compiler();data");

        self::assertNull(LineMap::forFile($file));
    }

    #[Test]
    public function readsCrLfLinesAndAMissingFinalLineBreak(): void
    {
        $crlf = $this->write('crlf.php', "<?php\r\necho 1;\r\n// phaenon-map v1 crlf.phn 1:1\r\n");
        $bare = $this->write('bare.php', "<?php\necho 1;\n// phaenon-map v1 bare.phn 1-2=1");

        self::assertSame('crlf.phn', LineMap::forFile($crlf)?->sourcePath());
        self::assertSame(1, LineMap::forFile($bare)?->sourceLine(2));
    }

    #[Test]
    public function readsAMapLongerThanTheFirstRead(): void
    {
        $entries = ['1:1'];
        for ($line = 2; $line <= 4000; $line += 2) {
            $entries[] = "$line-$line=" . ($line - 1);
            $entries[] = ($line + 1) . ':' . $line;
        }
        $map = '// phaenon-map v1 long.phn ' . implode(' ', $entries);
        self::assertGreaterThan(4096 * 4, \strlen($map));
        $file = $this->write('long.php', "<?php\n" . str_repeat("echo 1;\n", 4000) . $map . "\n");

        $read = LineMap::forFile($file);

        self::assertNotNull($read);
        self::assertSame(3998, $read->sourceLine(3999));
        self::assertSame(3999, $read->sourceLine(4000));
    }

    #[Test]
    public function resolvesTheSourcePathLexically(): void
    {
        $resolve = static fn (string $compiled, string $source): ?string => LineMap::parse("// phaenon-map v1 $source 1:1", $compiled)?->sourceFile();

        self::assertSame('/app/src/Domain/Foo.phn', $resolve('/app/.src/Domain/Foo.php', '../../src/Domain/Foo.phn'));
        self::assertSame('/app/.src/Foo.phn', $resolve('/app/.src/Foo.php', 'Foo.phn'));
        self::assertSame('/app/.src/a/Foo.phn', $resolve('/app/.src/./x/../Foo.php', './a//Foo.phn'));
        self::assertSame('/src/Foo.phn', $resolve('/app/Foo.php', '../../../src/Foo.phn'));
        self::assertSame('../src/Foo.phn', $resolve('.src/Foo.php', '../../src/Foo.phn'));
        self::assertSame('/srv/My Project/src/100%.phn', $resolve('/srv/My Project/.src/x.php', '../src/100%25.phn'));
        self::assertSame('C:/app/src/Foo.phn', $resolve('C:\\app\\.src\\Foo.php', '../src/Foo.phn'));
        self::assertSame('phar:///app/tool.phar/src/Foo.phn', $resolve('phar:///app/tool.phar/.src/Foo.php', '../src/Foo.phn'));
    }

    #[Test]
    public function translatesTheLinesInAClosureName(): void
    {
        $map = LineMap::parse('// phaenon-map v1 ../src/Foo.phn 1-6=1 7:1', '/app/.src/Foo.php');
        self::assertNotNull($map);

        self::assertSame('{closure:/app/src/Foo.phn:6}', $map->closureName('{closure:/app/.src/Foo.php:12}', '/app/.src/Foo.php', '/app/src/Foo.phn'));
        self::assertSame('App\Foo::{closure:App\Foo::bar():6}', $map->closureName('App\Foo::{closure:App\Foo::bar():12}', '/app/.src/Foo.php', '/app/src/Foo.phn'));
        self::assertSame('{closure:{closure:src/Foo.phn:2}:4}', $map->closureName('{closure:{closure:.src/Foo.php:8}:10}', '.src/Foo.php', 'src/Foo.phn'));
        self::assertSame('{closure}', $map->closureName('{closure}', '/app/.src/Foo.php', '/app/src/Foo.phn'));
        self::assertSame('App\Foo::bar', $map->closureName('App\Foo::bar', '/app/.src/Foo.php', '/app/src/Foo.phn'));
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
