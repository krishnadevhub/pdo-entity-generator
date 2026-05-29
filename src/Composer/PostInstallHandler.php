<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use kdevhub\PdoEntityGenerator\Config\ConfigLoader;

/**
 * Composer Plugin that auto-creates the configuration file on install or update
 *
 * Implements PluginInterface and EventSubscriberInterface to hook into
 * Composer's post-install and post-update lifecycle events. When triggered,
 * it creates config/pdoentitygenerator.yaml in the host project if it
 * does not already exist.
 *
 * @package kdevhub\PdoEntityGenerator\Composer
 */
final class PostInstallHandler implements PluginInterface, EventSubscriberInterface
{
    private const string CONFIG_DIR = 'config';
    private const string CONFIG_FILE = 'config/pdoentitygenerator.yaml';

    /**
     * Activate the plugin
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The IO interface for console output
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * Deactivate the plugin
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The IO interface for console output
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * Handle plugin uninstallation
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The IO interface for console output
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * Return the list of Composer events this plugin subscribes to
     *
     * @return array<string, string> Map of event names to handler method names
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstall',
        ];
    }

    /**
     * Create the default configuration file in the host project
     *
     * Checks whether config/pdoentitygenerator.yaml already exists.
     * If not, creates the config/ directory (if needed) and writes the
     * default YAML configuration template.
     *
     * @param Event $event The Composer script event
     * @return void
     */
    public function onPostInstall(Event $event): void
    {
        $io = $event->getIO();
        $projectRoot = $this->resolveProjectRoot($event);
        $configDir = $projectRoot . '/' . self::CONFIG_DIR;
        $configFile = $projectRoot . '/' . self::CONFIG_FILE;

        if (file_exists($configFile)) {
            $io->write('<info>pdoentitygenerator:</info> config/pdoentitygenerator.yaml already exists, skipping.');
            return;
        }

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
            chmod($configDir, 0775);
            $io->write('<info>pdoentitygenerator:</info> Created config/ directory.');
        }

        file_put_contents($configFile, ConfigLoader::getDefaultConfigContent());
        chmod($configFile, 0666);
        $io->write(
            '<info>pdoentitygenerator:</info> Created config/pdoentitygenerator.yaml'
            . ' — please update with your database credentials.'
        );
    }

    /**
     * Resolve the host project root directory from the Composer vendor path
     *
     * @param Event $event The Composer script event
     * @return string Absolute path to the project root
     */
    private function resolveProjectRoot(Event $event): string
    {
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');

        if (is_string($vendorDir)) {
            $projectRoot = dirname($vendorDir);

            if (file_exists($projectRoot . '/composer.json')) {
                return $projectRoot;
            }
        }

        return getcwd() ?: '.';
    }
}
