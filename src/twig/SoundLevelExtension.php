<?php

declare(strict_types=1);

namespace trainhorn\soundlevel\twig;

use trainhorn\soundlevel\services\SoundLevel;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig functions and filters:
 *
 *     {{ soundLevelAt(150, 10, 100) }}            {# 130.0 #}
 *     {{ 150|soundLevelAt(10, 100) }}             {# same, as a filter #}
 *     {{ soundLevelDistance(150, 10, 90) }}       {# 10000.0 (ft) #}
 *     {{ soundLevelTable(150, 10) }}              {# accessible <table> #}
 *     {{ soundLevelTable(150, 10, { unit: 'ft', distances: [50, 100, 500], absorption: 1.9 }) }}
 */
class SoundLevelExtension extends AbstractExtension
{
    public function __construct(private readonly SoundLevel $service = new SoundLevel())
    {
    }

    public function getName(): string
    {
        return 'sound-level';
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('soundLevelAt', [$this, 'at']),
            new TwigFunction('soundLevelDistance', [$this, 'distance']),
            new TwigFunction('soundLevelTable', [$this, 'table'], ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('soundLevelAt', [$this, 'at']),
        ];
    }

    /**
     * Level in dB at $distance, rounded to $precision decimals (default 1).
     */
    public function at(mixed $sourceDb, mixed $refDistance, mixed $distance, mixed $unit = 'ft', mixed $absorption = 0.0, mixed $precision = 1): float
    {
        return round($this->service->splAt($sourceDb, $refDistance, $distance, $unit, $absorption), self::precision($precision));
    }

    /**
     * Distance (in $unit) at which the level falls to $targetDb, rounded to $precision decimals (default 1).
     */
    public function distance(mixed $sourceDb, mixed $refDistance, mixed $targetDb, mixed $unit = 'ft', mixed $absorption = 0.0, mixed $precision = 1): float
    {
        return round($this->service->distanceFor($sourceDb, $refDistance, $targetDb, $unit, $absorption), self::precision($precision));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function table(mixed $sourceDb, mixed $refDistance = 10, array $options = []): string
    {
        return $this->service->renderTable($sourceDb, $refDistance, $options);
    }

    private static function precision(mixed $precision): int
    {
        if (!is_int($precision) || $precision < 0 || $precision > 6) {
            throw new \trainhorn\soundlevel\services\SoundLevelException('precision must be an integer from 0 to 6');
        }

        return $precision;
    }
}
