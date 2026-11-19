<?php

declare(strict_types=1);

namespace trainhorn\soundlevel;

use Craft;
use craft\base\Plugin as BasePlugin;
use trainhorn\soundlevel\services\SoundLevel;
use trainhorn\soundlevel\twig\SoundLevelExtension;

/**
 * Sound Level plugin for Craft CMS 5.
 *
 * Registers Twig helpers that estimate sound level at distance with the
 * free-field inverse-square law (optional air absorption).
 *
 * @property-read SoundLevel $soundLevel
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'soundLevel' => SoundLevel::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->getView()->registerTwigExtension(new SoundLevelExtension($this->getSoundLevel()));
    }

    public function getSoundLevel(): SoundLevel
    {
        /** @var SoundLevel */
        return $this->get('soundLevel');
    }
}
