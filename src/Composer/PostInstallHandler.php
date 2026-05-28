<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Composer;

use Composer\Script\Event;
use kdevhub\PdoEntityGenerator\Config\ConfigLoader;

final class PostInstallHandler
{
    private const string CONFIG_DIR = 'config';
    private const string CONFIG_FILE = 'config/pdoentitygenerator.yaml';

    public static function createConfig(Event $event): void
    {
        $io = $event->getIO();
        $projectRoot = self::resolveProjectRoot();
        $configDir = $projectRoot . '/' . self::CONFIG_DIR;
        $configFile = $projectRoot . '/' . self::CONFIG_FILE;

        if (file_exists($configFile)) {
            $io->write('<info>pdoentitygenerator:</info> config/pdoentitygenerator.yaml already exists, skipping.');
            return;
        }

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
            $io->write('<info>pdoentitygenerator:</info> Created config/ directory.');
        }

        file_put_contents($configFile, ConfigLoader::getDefaultConfigContent());
        $io->write('<info>pdoentitygenerator:</info> Created config/pdoentitygenerator.yaml — please update with your database credentials.');
    }

    private static function resolveProjectRoot(): string
    {
        // When installed as a dependency, the project root is four levels up from this file
        // vendor/kdevhubin/pdoentitygenerator/src/Composer/PostInstallHandler.php
        $vendorPath = dirname(__DIR__, 4);

        if (file_exists($vendorPath . '/composer.json')) {
            return $vendorPath;
        }

        // Fallback: current working directory
        return getcwd() ?: '.';
    }
}
