<?php

declare(strict_types=1);

namespace trainhorn\soundlevel\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use trainhorn\soundlevel\services\SoundLevel;
use trainhorn\soundlevel\services\SoundLevelException;

/**
 * Vectors come from the tested Python core (trainhorn-calc 1.0.0, acoustics.py).
 */
final class SoundLevelTest extends TestCase
{
    private SoundLevel $s;

    protected function setUp(): void
    {
        $this->s = new SoundLevel();
    }

    /** @return array<string, array{array<int, mixed>, float}> */
    public static function coreVectors(): array
    {
        return [
            '150@10ft -> 100ft' => [[150, 10, 100], 130.0],
            '150@10ft -> 25ft' => [[150, 10, 25], 142.04119982655925],
            '150@10ft -> 50ft' => [[150, 10, 50], 136.02059991327963],
            '150@10ft -> 500ft' => [[150, 10, 500], 116.02059991327963],
            '150@10ft -> 1000ft' => [[150, 10, 1000], 110.0],
            '150@10ft -> 1 mile' => [[150, 10, 5280], 95.54732154932375],
            '130@1m -> 1m' => [[130, 1, 1, 'm'], 130.0],
            'closer than ref' => [[150, 10, 3], 160.45757490560675],
            'absorption ft' => [[150, 10, 1000, 'ft', 1.9], 109.4266712],
            'absorption m' => [[150, 10, 500, 'm', 3.7], 114.20759991327962],
        ];
    }

    /** @param array<int, mixed> $args */
    #[DataProvider('coreVectors')]
    public function testSplAtMatchesCore(array $args, float $expected): void
    {
        self::assertEqualsWithDelta($expected, $this->s->splAt(...$args), 1e-9);
    }

    public function testCoreHeadlineVector(): void
    {
        self::assertSame('130.0', number_format($this->s->splAt(150, 10, 100), 1));
    }

    public function testNumericStringsAndUnitCaseAccepted(): void
    {
        self::assertEqualsWithDelta(130.0, $this->s->splAt('150', ' 10 ', '1e2', ' FT '), 1e-12);
    }

    public function testSixDbPerDoubling(): void
    {
        self::assertEqualsWithDelta(20 * log10(2), $this->s->splAt(100, 10, 10) - $this->s->splAt(100, 10, 20), 1e-12);
    }

    public function testDistanceForMatchesCore(): void
    {
        self::assertEqualsWithDelta(100.0, $this->s->distanceFor(150, 10, 130), 1e-9);
        self::assertEqualsWithDelta(17782.794100389227, $this->s->distanceFor(150, 10, 85), 1e-6);
        self::assertEqualsWithDelta(6491.256148481227, $this->s->distanceFor(150, 10, 90, 'ft', 1.9), 1e-6);
    }

    public function testDistanceForIsInverseOfSplAt(): void
    {
        foreach ([[0.0, 'ft'], [1.9, 'ft'], [3.7, 'm'], [32.8, 'm']] as [$alpha, $unit]) {
            foreach ([140, 120, 95, 70] as $target) {
                $d = $this->s->distanceFor(150, 10, $target, $unit, $alpha);
                self::assertEqualsWithDelta($target, $this->s->splAt(150, 10, $d, $unit, $alpha), 1e-8, "alpha=$alpha unit=$unit target=$target");
            }
        }
    }

    public function testDistanceForCloserThanRefWithAbsorption(): void
    {
        $d = $this->s->distanceFor(150, 10, 155, 'm', 3.7);
        self::assertLessThan(10, $d);
        self::assertEqualsWithDelta(155.0, $this->s->splAt(150, 10, $d, 'm', 3.7), 1e-8);
    }

    public function testDistanceForRejectsAbsurdSpread(): void
    {
        $this->expectException(SoundLevelException::class);
        $this->s->distanceFor(1e6, 10, 0);
    }

    public function testCombine(): void
    {
        self::assertEqualsWithDelta(103.01029995663981, $this->s->combine([100, 100]), 1e-9);
        self::assertEqualsWithDelta(120.0, $this->s->combine([120]), 1e-12);
        self::assertEqualsWithDelta(1000.0 + 10 * log10(2), $this->s->combine([1000, 1000]), 1e-9);
    }

    public function testCombineRejectsEmpty(): void
    {
        $this->expectException(SoundLevelException::class);
        $this->s->combine([]);
    }

    /** @return array<string, array{array<int, mixed>, string}> */
    public static function invalidInputs(): array
    {
        return [
            'zero distance' => [[150, 10, 0], 'distance must be greater than 0'],
            'negative distance' => [[150, 10, -5], 'distance must be greater than 0'],
            'zero ref' => [[150, 0, 100], 'refDistance must be greater than 0'],
            'text source' => [[ 'loud', 10, 100], 'sourceDb must be a number'],
            'bool source' => [[true, 10, 100], 'sourceDb must be a number, got a bool'],
            'null source' => [[null, 10, 100], 'sourceDb must be a number, got null'],
            'array source' => [[[150], 10, 100], 'sourceDb must be a number'],
            'infinite' => [[INF, 10, 100], 'sourceDb must be finite'],
            'nan distance' => [[150, 10, NAN], 'distance must be finite'],
            'bad unit' => [[150, 10, 100, 'yd'], 'unit must be one of ft, m'],
            'unit not string' => [[150, 10, 100, 3], 'unit must be one of ft, m'],
            'negative absorption' => [[150, 10, 100, 'ft', -1], 'absorptionDbPerKm must be 0 or greater'],
        ];
    }

    /** @param array<int, mixed> $args */
    #[DataProvider('invalidInputs')]
    public function testValidation(array $args, string $message): void
    {
        $this->expectException(SoundLevelException::class);
        $this->expectExceptionMessage($message);
        $this->s->splAt(...$args);
    }

    public function testExceptionIsInvalidArgument(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, new SoundLevelException('x'));
    }

    public function testRowsDefaults(): void
    {
        $rows = $this->s->rows(150, 10);
        self::assertCount(count(SoundLevel::DEFAULT_DISTANCES['ft']), $rows);
        self::assertSame(10.0, $rows[0]['distance']);
        self::assertEqualsWithDelta(150.0, $rows[0]['level'], 1e-12);
        self::assertEqualsWithDelta(0.0, $rows[0]['change'], 1e-12);
        self::assertEqualsWithDelta(130.0, $rows[3]['level'], 1e-12);
        self::assertEqualsWithDelta(-20.0, $rows[3]['change'], 1e-12);
        self::assertCount(count(SoundLevel::DEFAULT_DISTANCES['m']), $this->s->rows(120, 1, null, 'm'));
    }

    public function testRowsValidation(): void
    {
        foreach ([[[]], [array_fill(0, 51, 10)], [[10, 'x']], [[10, -1]]] as [$distances]) {
            try {
                $this->s->rows(150, 10, $distances);
                self::fail('expected exception for ' . json_encode($distances));
            } catch (SoundLevelException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRenderTableStructure(): void
    {
        $html = $this->s->renderTable(150, 10, ['distances' => [10, 100, 5280]]);
        self::assertStringStartsWith('<table class="sound-level-table">', $html);
        self::assertStringContainsString('<caption>Estimated sound level at distance for a source of 150.0 dB at 10 ft (free field, no air absorption)</caption>', $html);
        self::assertSame(3, substr_count($html, '<th scope="col">'));
        self::assertSame(3, substr_count($html, '<th scope="row">'));
        self::assertStringContainsString('<tr><th scope="row">10 ft</th><td>150.0</td><td>0.0</td></tr>', $html);
        self::assertStringContainsString('<tr><th scope="row">100 ft</th><td>130.0</td><td>-20.0</td></tr>', $html);
        self::assertStringContainsString('<tr><th scope="row">5,280 ft</th><td>95.5</td><td>-54.5</td></tr>', $html);
        self::assertStringEndsWith('</table>', $html);
    }

    public function testRenderTableOptions(): void
    {
        $html = $this->s->renderTable(120, 1, ['unit' => 'm', 'distances' => [0.5, 2], 'precision' => 2, 'absorption' => 3.7, 'class' => 'spl']);
        self::assertStringContainsString('<table class="spl">', $html);
        self::assertStringContainsString('air absorption 3.7 dB/km', $html);
        self::assertStringContainsString('<th scope="row">0.5 m</th><td>126.02</td><td>+6.02</td>', $html);
        self::assertStringContainsString('<th scope="row">2 m</th><td>113.98</td><td>-6.02</td>', $html);
    }

    public function testRenderTableEscapesCaptionAndClass(): void
    {
        $html = $this->s->renderTable(150, 10, ['caption' => '<script>alert(1)</script> & "x"', 'class' => '"><img src=x>']);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;', $html);
    }

    public function testRenderTableRejectsBadOptions(): void
    {
        $cases = [
            ['colour' => 'red'],
            ['precision' => 7],
            ['precision' => '1'],
            ['distances' => '10,20'],
            ['unit' => 'km'],
            ['caption' => 5],
            ['class' => []],
            ['absorption' => -0.1],
        ];
        foreach ($cases as $options) {
            try {
                $this->s->renderTable(150, 10, $options);
                self::fail('expected exception for ' . json_encode($options));
            } catch (SoundLevelException) {
                self::assertTrue(true);
            }
        }
    }
}
