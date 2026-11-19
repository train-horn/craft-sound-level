# Sound Level for Craft CMS

Twig helpers for **Craft CMS 5** that estimate how loud a sound source is at a given distance, and render the result as an accessible HTML table. Useful for product pages (horns, sirens, alarms, speakers, generators), safety notes and buying guides where a spec says "150 dB at 10 ft" and readers want to know what that means at 100 ft or a mile away.

Made by [Train Horn](https://train-horn.com/), an independent reference on train horns. The formulas are the same as the tested Python core behind the site's [decibel distance calculator](https://train-horn.com/tools/decibel-distance/).

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

## Installation

From the Plugin Store: search for "Sound Level" and click Install.

With Composer:

```bash
composer require train-horn/craft-sound-level
php craft plugin/install sound-level
```

## Usage

### Level at a distance

```twig
{# 150 dB measured at 10 ft: how loud at 100 ft? #}
{{ soundLevelAt(150, 10, 100) }}            {# 130 #}
{{ soundLevelAt(150, 10, 100)|number_format(1) }} dB  {# 130.0 dB #}

{# Filter form, handy with a field value #}
{{ entry.ratedDb|soundLevelAt(10, 500) }}   {# 116 for a 150 dB rating #}

{# Metres, with air absorption (dB/km) and 2 decimals #}
{{ soundLevelAt(120, 1, 100, 'm', 1.9, 2) }}
```

Signature: `soundLevelAt(sourceDb, refDistance, distance, unit = 'ft', absorption = 0, precision = 1)`

### Distance for a target level

```twig
{# How far until a 150 dB @ 10 ft horn drops to 90 dB? #}
{{ soundLevelDistance(150, 10, 90) }} ft    {# 10000 ft #}
```

Signature: `soundLevelDistance(sourceDb, refDistance, targetDb, unit = 'ft', absorption = 0, precision = 1)`

### Table

```twig
{{ soundLevelTable(150, 10) }}

{{ soundLevelTable(150, 10, {
    distances: [25, 50, 100, 500, 1000, 5280],
    unit: 'ft',
    absorption: 1.9,
    precision: 1,
    caption: 'How loud is a 150 dB horn at a distance?',
    class: 'spl-table'
}) }}
```

Output (default options, shortened):

```html
<table class="sound-level-table">
<caption>Estimated sound level at distance for a source of 150.0 dB at 10 ft (free field, no air absorption)</caption>
<thead>
<tr><th scope="col">Distance</th><th scope="col">Estimated level (dB)</th><th scope="col">Change from reference (dB)</th></tr>
</thead>
<tbody>
<tr><th scope="row">10 ft</th><td>150.0</td><td>0.0</td></tr>
<tr><th scope="row">100 ft</th><td>130.0</td><td>-20.0</td></tr>
<tr><th scope="row">5,280 ft</th><td>95.5</td><td>-54.5</td></tr>
</tbody>
</table>
```

The table has a `<caption>`, column headers with `scope="col"` and row headers with `scope="row"`, so screen readers announce each value with its distance. All text is HTML-escaped. The plugin ships no CSS; style `.sound-level-table` (or your own `class`) in your theme.

| Option | Default | Notes |
|---|---|---|
| `distances` | ft: 10, 25, 50, 100, 250, 500, 1000, 2640, 5280; m: 1, 3, 10, 30, 100, 300, 1000, 3000 | 1 to 50 values, each > 0 |
| `unit` | `'ft'` | `'ft'` or `'m'`, used for every distance |
| `absorption` | `0` | Air absorption in dB/km, >= 0 |
| `precision` | `1` | Decimals, 0 to 3 |
| `caption` | describes the inputs | Any string |
| `class` | `'sound-level-table'` | CSS class on `<table>` |

### From PHP

```php
use trainhorn\soundlevel\Plugin;

$service = Plugin::getInstance()->soundLevel;
$service->splAt(150, 10, 100);          // 130.0
$service->distanceFor(150, 10, 90);     // 10000.0
$service->combine([100, 100]);          // 103.0103 (two equal sources)
$service->rows(150, 10, [50, 100]);     // [['distance' => 50.0, 'level' => 136.02, 'change' => -13.98], ...]
```

## The model

```
L(d) = Lref - 20 * log10(d / dref) - alpha * (d - dref) / 1000
```

- `Lref` is the level at the reference distance `dref` (ratings are usually quoted "at 10 ft" or "at 1 m").
- Each doubling of distance lowers the level by about 6.02 dB; ten times the distance lowers it by 20 dB.
- `alpha` is optional atmospheric absorption in dB per kilometre over the path beyond `dref` (distances converted to metres for this term). Typical values at 10 °C and 70 % RH: 250 Hz 1.0, 500 Hz 1.9, 1 kHz 3.7, 2 kHz 9.7 dB/km (`SoundLevel::AIR_ABSORPTION_DB_PER_KM`).

Limits: this is a free-field estimate for a small source. Ground reflection, buildings, wind, temperature gradients and barriers can change real levels by several dB either way, and the law does not hold very close to the source. The numbers are estimates, not measurements, and not a legal noise assessment.

## Validation

Every input is checked. Non-numeric values, booleans, `null`, `NaN`/`INF`, distances <= 0, negative absorption, unknown units, unknown table options and more than 50 rows throw `trainhorn\soundlevel\services\SoundLevelException` (an `InvalidArgumentException`) with a clear message, for example `distance must be greater than 0, got 0`. In templates Twig reports it as a runtime error on the line that called the helper. Numeric strings such as `'150'` are accepted, so field values work directly.

## Tests

```bash
composer install
vendor/bin/phpunit
```

45 PHPUnit tests cover the service (vectors from the Python core, for example 150 dB @ 10 ft gives 130.0 dB @ 100 ft), the inverse function, validation, HTML escaping, and rendering through a real Twig environment with autoescape on.

## License

MIT. See [LICENSE](LICENSE).
