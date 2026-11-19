<?php

declare(strict_types=1);

namespace trainhorn\soundlevel\services;

/**
 * Sound pressure level (SPL) versus distance for a small source in open air.
 *
 * Model (free field, point source):
 *
 *     L(d) = Lref - 20 * log10(d / dref) - alpha * (d - dref) / 1000
 *
 * where Lref is the level at the reference distance dref, and alpha is an
 * optional atmospheric absorption coefficient in dB per kilometre applied to
 * the path beyond dref (distances converted to metres for that term).
 *
 * These are the same formulas as the tested Python core used by
 * https://train-horn.com/ (trainhorn-calc, acoustics.py). Results are
 * estimates, not measurements, and not a legal noise assessment.
 *
 * The class has no Craft dependency, so it can be unit-tested on its own.
 */
class SoundLevel
{
    public const METERS_PER_FOOT = 0.3048;

    /** Units accepted for distances. */
    public const UNITS = ['ft', 'm'];

    /** Maximum number of rows in a table. */
    public const MAX_ROWS = 50;

    /**
     * Approximate air absorption in dB/km at 10 degC and 70 % RH (ISO 9613-1 / 9613-2),
     * keyed by octave-band centre frequency in Hz. Rough guide only.
     *
     * @var array<int, float>
     */
    public const AIR_ABSORPTION_DB_PER_KM = [
        63 => 0.1,
        125 => 0.4,
        250 => 1.0,
        500 => 1.9,
        1000 => 3.7,
        2000 => 9.7,
        4000 => 32.8,
        8000 => 117.0,
    ];

    /** Default table distances per unit. */
    public const DEFAULT_DISTANCES = [
        'ft' => [10, 25, 50, 100, 250, 500, 1000, 2640, 5280],
        'm' => [1, 3, 10, 30, 100, 300, 1000, 3000],
    ];

    /**
     * Estimated level at $distance from a source of $sourceDb measured at $refDistance.
     *
     * Example: splAt(150, 10, 100) === 130.0 (150 dB at 10 ft gives 130 dB at 100 ft).
     */
    public function splAt(
        mixed $sourceDb,
        mixed $refDistance,
        mixed $distance,
        mixed $unit = 'ft',
        mixed $absorptionDbPerKm = 0.0,
    ): float {
        $sourceDb = self::number('sourceDb', $sourceDb);
        $refDistance = self::positive('refDistance', $refDistance);
        $distance = self::positive('distance', $distance);
        $unit = self::unit($unit);
        $absorptionDbPerKm = self::nonNegative('absorptionDbPerKm', $absorptionDbPerKm);

        return self::level($sourceDb, $refDistance, $distance, $unit, $absorptionDbPerKm);
    }

    /**
     * Distance (in $unit) at which the level falls to $targetDb. Inverse of splAt().
     *
     * Closed form without absorption: d = dref * 10 ** ((Lref - target) / 20).
     * With absorption the root is found by bisection (level decreases strictly with distance).
     */
    public function distanceFor(
        mixed $sourceDb,
        mixed $refDistance,
        mixed $targetDb,
        mixed $unit = 'ft',
        mixed $absorptionDbPerKm = 0.0,
    ): float {
        $sourceDb = self::number('sourceDb', $sourceDb);
        $refDistance = self::positive('refDistance', $refDistance);
        $targetDb = self::number('targetDb', $targetDb);
        $unit = self::unit($unit);
        $absorptionDbPerKm = self::nonNegative('absorptionDbPerKm', $absorptionDbPerKm);

        $freeField = $refDistance * 10.0 ** (($sourceDb - $targetDb) / 20.0);
        if ($freeField == 0.0 || is_infinite($freeField) || is_nan($freeField)) {
            throw new SoundLevelException('sourceDb and targetDb are too far apart to give a usable distance');
        }
        if ($absorptionDbPerKm == 0.0 || $freeField == $refDistance) {
            return $freeField;
        }

        $lo = min($freeField, $refDistance);
        $hi = max($freeField, $refDistance);
        for ($i = 0; $i < 200; $i++) {
            $mid = 0.5 * ($lo + $hi);
            if (self::level($sourceDb, $refDistance, $mid, $unit, $absorptionDbPerKm) - $targetDb > 0) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
            if ($hi - $lo <= 1e-12 * $hi) {
                break;
            }
        }

        return 0.5 * ($lo + $hi);
    }

    /**
     * Combine incoherent sources: L = 10 * log10(sum(10 ** (Li / 10))). Two equal sources add about 3.01 dB.
     *
     * @param iterable<mixed> $levels
     */
    public function combine(iterable $levels): float
    {
        $values = [];
        foreach ($levels as $i => $level) {
            $values[] = self::number("levels[$i]", $level);
        }
        if ($values === []) {
            throw new SoundLevelException('combine() needs at least one level');
        }
        $peak = max($values);
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += 10.0 ** (($v - $peak) / 10.0);
        }

        return $peak + 10.0 * log10($sum);
    }

    /**
     * Rows for a level-versus-distance table.
     *
     * @param array<mixed>|null $distances Distances in $unit; null uses DEFAULT_DISTANCES[$unit].
     * @return list<array{distance: float, level: float, change: float}>
     */
    public function rows(
        mixed $sourceDb,
        mixed $refDistance,
        ?array $distances = null,
        mixed $unit = 'ft',
        mixed $absorptionDbPerKm = 0.0,
    ): array {
        $sourceDb = self::number('sourceDb', $sourceDb);
        $refDistance = self::positive('refDistance', $refDistance);
        $unit = self::unit($unit);
        $absorptionDbPerKm = self::nonNegative('absorptionDbPerKm', $absorptionDbPerKm);

        $distances ??= self::DEFAULT_DISTANCES[$unit];
        if ($distances === []) {
            throw new SoundLevelException('distances must contain at least one value');
        }
        if (count($distances) > self::MAX_ROWS) {
            throw new SoundLevelException(sprintf('distances may contain at most %d values, got %d', self::MAX_ROWS, count($distances)));
        }

        $rows = [];
        foreach (array_values($distances) as $i => $d) {
            $d = self::positive("distances[$i]", $d);
            $level = self::level($sourceDb, $refDistance, $d, $unit, $absorptionDbPerKm);
            $rows[] = ['distance' => $d, 'level' => $level, 'change' => $level - $sourceDb];
        }

        return $rows;
    }

    /**
     * Accessible HTML table of estimated levels. All output is escaped.
     *
     * Options:
     *  - distances (array|null): distances in `unit` (default: DEFAULT_DISTANCES)
     *  - unit ('ft'|'m', default 'ft')
     *  - absorption (float dB/km >= 0, default 0)
     *  - precision (int 0..3, default 1)
     *  - caption (string|null): custom caption; default describes the inputs
     *  - class (string, default 'sound-level-table')
     *
     * @param array<string, mixed> $options
     */
    public function renderTable(mixed $sourceDb, mixed $refDistance, array $options = []): string
    {
        $known = ['distances', 'unit', 'absorption', 'precision', 'caption', 'class'];
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new SoundLevelException('unknown option(s): ' . implode(', ', $unknown) . '; allowed: ' . implode(', ', $known));
        }

        $unit = self::unit($options['unit'] ?? 'ft');
        $absorption = self::nonNegative('absorption', $options['absorption'] ?? 0.0);
        $precision = self::precision($options['precision'] ?? 1);
        $distances = $options['distances'] ?? null;
        if ($distances !== null && !is_array($distances)) {
            throw new SoundLevelException('distances must be a list of numbers');
        }
        $class = $options['class'] ?? 'sound-level-table';
        if (!is_string($class)) {
            throw new SoundLevelException('class must be a string');
        }

        $rows = $this->rows($sourceDb, $refDistance, $distances, $unit, $absorption);
        $source = self::number('sourceDb', $sourceDb);
        $ref = self::positive('refDistance', $refDistance);

        $caption = $options['caption'] ?? null;
        if ($caption === null) {
            $caption = sprintf(
                'Estimated sound level at distance for a source of %s dB at %s %s (free field%s)',
                self::fmt($source, $precision),
                self::fmtDistance($ref),
                $unit,
                $absorption > 0 ? ', air absorption ' . self::fmtDistance($absorption) . ' dB/km' : ', no air absorption',
            );
        } elseif (!is_string($caption)) {
            throw new SoundLevelException('caption must be a string');
        }

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        $html = '<table class="' . $e($class) . '">' . "\n";
        $html .= '<caption>' . $e($caption) . "</caption>\n";
        $html .= "<thead>\n<tr>"
            . '<th scope="col">Distance</th>'
            . '<th scope="col">Estimated level (dB)</th>'
            . '<th scope="col">Change from reference (dB)</th>'
            . "</tr>\n</thead>\n<tbody>\n";
        foreach ($rows as $row) {
            $change = round($row['change'], $precision);
            $html .= '<tr>'
                . '<th scope="row">' . $e(self::fmtDistance($row['distance']) . ' ' . $unit) . '</th>'
                . '<td>' . $e(self::fmt($row['level'], $precision)) . '</td>'
                . '<td>' . $e(($change > 0 ? '+' : '') . self::fmt($row['change'], $precision)) . '</td>'
                . "</tr>\n";
        }
        $html .= "</tbody>\n</table>";

        return $html;
    }

    // ------------------------------------------------------------------ internals

    private static function level(float $sourceDb, float $ref, float $d, string $unit, float $alpha): float
    {
        $level = $sourceDb - 20.0 * log10($d / $ref);
        if ($alpha > 0) {
            $level -= $alpha * (self::toMeters($d, $unit) - self::toMeters($ref, $unit)) / 1000.0;
        }

        return $level;
    }

    private static function toMeters(float $value, string $unit): float
    {
        return $unit === 'ft' ? $value * self::METERS_PER_FOOT : $value;
    }

    private static function fmt(float $value, int $precision): string
    {
        $s = number_format($value, $precision, '.', '');
        // Avoid "-0.0".
        if ((float) $s == 0.0) {
            $s = number_format(0.0, $precision, '.', '');
        }

        return $s;
    }

    private static function fmtDistance(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 3, '.', ','), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    public static function number(string $name, mixed $value): float
    {
        if (is_bool($value) || $value === null) {
            throw new SoundLevelException(sprintf('%s must be a number, got %s', $name, $value === null ? 'null' : 'a bool'));
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new SoundLevelException(sprintf('%s must be a number, got %s', $name, is_scalar($value) ? var_export($value, true) : get_debug_type($value)));
        }
        $result = (float) $value;
        if (!is_finite($result)) {
            throw new SoundLevelException(sprintf('%s must be finite', $name));
        }

        return $result;
    }

    public static function positive(string $name, mixed $value): float
    {
        $result = self::number($name, $value);
        if ($result <= 0) {
            throw new SoundLevelException(sprintf('%s must be greater than 0, got %s', $name, self::fmtDistance($result)));
        }

        return $result;
    }

    public static function nonNegative(string $name, mixed $value): float
    {
        $result = self::number($name, $value);
        if ($result < 0) {
            throw new SoundLevelException(sprintf('%s must be 0 or greater, got %s', $name, (string) $result));
        }

        return $result;
    }

    public static function unit(mixed $unit): string
    {
        $u = is_string($unit) ? strtolower(trim($unit)) : null;
        if (!in_array($u, self::UNITS, true)) {
            throw new SoundLevelException(sprintf('unit must be one of %s; got %s', implode(', ', self::UNITS), is_scalar($unit) ? var_export($unit, true) : get_debug_type($unit)));
        }

        return $u;
    }

    private static function precision(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 3) {
            throw new SoundLevelException('precision must be an integer from 0 to 3');
        }

        return $value;
    }
}
