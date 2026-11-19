<?php

declare(strict_types=1);

namespace trainhorn\soundlevel\tests;

use PHPUnit\Framework\TestCase;
use trainhorn\soundlevel\services\SoundLevelException;
use trainhorn\soundlevel\twig\SoundLevelExtension;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;

/**
 * Renders real Twig templates through the extension (autoescape on, as in Craft).
 */
final class SoundLevelExtensionTest extends TestCase
{
    private function render(string $template, array $context = []): string
    {
        $twig = new Environment(new ArrayLoader(['t' => $template]), ['autoescape' => 'html', 'strict_variables' => true]);
        $twig->addExtension(new SoundLevelExtension());

        return $twig->render('t', $context);
    }

    public function testFunction(): void
    {
        self::assertSame('130', $this->render('{{ soundLevelAt(150, 10, 100) }}'));
        self::assertSame('130.0', $this->render('{{ soundLevelAt(150, 10, 100)|number_format(1) }}'));
        self::assertSame('142', $this->render('{{ soundLevelAt(150, 10, 25, "ft", 0, 0) }}'));
        self::assertSame('109.43', $this->render('{{ soundLevelAt(150, 10, 1000, "ft", 1.9, 2) }}'));
    }

    public function testFilter(): void
    {
        self::assertSame('136', $this->render('{{ 150|soundLevelAt(10, 50) }}'));
        self::assertSame('110', $this->render('{{ rating|soundLevelAt(10, 1000) }}', ['rating' => '150']));
    }

    public function testDistanceFunction(): void
    {
        self::assertSame('100', $this->render('{{ soundLevelDistance(150, 10, 130) }}'));
        self::assertSame('17782.8', $this->render('{{ soundLevelDistance(150, 10, 85) }}'));
    }

    public function testTableIsNotDoubleEscaped(): void
    {
        $html = $this->render('{{ soundLevelTable(150, 10) }}');
        self::assertStringStartsWith('<table class="sound-level-table">', $html);
        self::assertStringContainsString('<tr><th scope="row">100 ft</th><td>130.0</td><td>-20.0</td></tr>', $html);
        self::assertStringNotContainsString('&lt;table', $html);
    }

    public function testTableOptionsFromTwigHash(): void
    {
        $html = $this->render("{{ soundLevelTable(130, 1, { unit: 'm', distances: [1, 10, 100], caption: 'Siren <test>' }) }}");
        self::assertStringContainsString('<caption>Siren &lt;test&gt;</caption>', $html);
        self::assertStringContainsString('<th scope="row">100 m</th><td>90.0</td><td>-40.0</td>', $html);
    }

    public function testInvalidInputSurfacesAsTwigError(): void
    {
        try {
            $this->render('{{ soundLevelAt(150, 10, 0) }}');
            self::fail('expected an error');
        } catch (RuntimeError $e) {
            self::assertInstanceOf(SoundLevelException::class, $e->getPrevious());
            self::assertStringContainsString('distance must be greater than 0', $e->getMessage());
        }
    }

    public function testInvalidPrecision(): void
    {
        $this->expectException(RuntimeError::class);
        $this->render('{{ soundLevelAt(150, 10, 100, "ft", 0, 9) }}');
    }
}
