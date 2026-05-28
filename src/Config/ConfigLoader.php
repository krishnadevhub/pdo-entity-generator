<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Config;

use Symfony\Component\Yaml\Yaml;

final class ConfigLoader
{
    private const string CONFIG_PATH = 'config/pdoentitygenerator.yaml';

    private const array DEFAULTS = [
        'database' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'dbname' => '',
            'username' => 'root',
            'password' => '',
            'driver' => 'mysql',
        ],
        'output' => [
            'entity_namespace' => 'App\\Entity',
            'repository_namespace' => 'App\\Repository',
            'entity_directory' => 'src/Entity',
            'repository_directory' => 'src/Repository',
        ],
    ];

    /**
     * @return array{
     *     database: array{host: string, port: int, dbname: string, username: string, password: string, driver: string},
     *     output: array{entity_namespace: string, repository_namespace: string, entity_directory: string, repository_directory: string}
     * }
     */
    public function load(string $projectRoot): array
    {
        $configFile = rtrim($projectRoot, '/') . '/' . self::CONFIG_PATH;

        if (!file_exists($configFile)) {
            throw new \RuntimeException(
                sprintf("Configuration file not found: %s\nRun 'composer install' to generate the default configuration.", $configFile)
            );
        }

        $parsed = Yaml::parseFile($configFile);

        if (!is_array($parsed)) {
            throw new \RuntimeException(
                sprintf('Invalid configuration format in %s. Expected YAML mapping.', $configFile)
            );
        }

        return $this->mergeWithDefaults($parsed);
    }

    /**
     * @return array{
     *     database: array{host: string, port: int, dbname: string, username: string, password: string, driver: string},
     *     output: array{entity_namespace: string, repository_namespace: string, entity_directory: string, repository_directory: string}
     * }
     */
    private function mergeWithDefaults(array $parsed): array
    {
        $config = self::DEFAULTS;

        if (isset($parsed['database']) && is_array($parsed['database'])) {
            $config['database'] = array_merge($config['database'], $parsed['database']);
        }

        if (isset($parsed['output']) && is_array($parsed['output'])) {
            $config['output'] = array_merge($config['output'], $parsed['output']);
        }

        $config['database']['port'] = (int) $config['database']['port'];

        if (empty($config['database']['dbname'])) {
            throw new \RuntimeException(
                'Database name (database.dbname) must be specified in config/pdoentitygenerator.yaml'
            );
        }

        return $config;
    }

    public static function getDefaultConfigContent(): string
    {
        return <<<'YAML'
# PDO Entity Generator Configuration
# Update with your database credentials

database:
    host: 127.0.0.1
    port: 3306
    dbname: my_database
    username: root
    password: secret
    driver: mysql

output:
    entity_namespace: App\Entity
    repository_namespace: App\Repository
    entity_directory: src/Entity
    repository_directory: src/Repository
YAML;
    }
}
