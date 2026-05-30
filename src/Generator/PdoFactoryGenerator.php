<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Generator;

/**
 * Generates a framework-agnostic PdoFactory PHP class
 *
 * Produces a factory class that reads database credentials from
 * config/pdoentitygenerator.yaml and returns a configured PDO instance.
 * Works in plain PHP, Symfony, Laravel, Slim, or any PHP project.
 *
 * @package kdevhub\PdoEntityGenerator\Generator
 */
final class PdoFactoryGenerator
{
    /**
     * Generate the PdoFactory class source code
     *
     * @param string $namespace The namespace for the generated factory class
     * @return string The complete PHP source code for the factory class
     */
    public function generate(string $namespace): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use PDO;
        use PDOException;
        use RuntimeException;
        use Symfony\Component\Yaml\Yaml;

        /**
         * Factory for creating configured PDO database connections
         *
         * Reads database credentials from config/pdoentitygenerator.yaml and
         * returns a configured PDO instance. Uses a singleton pattern to reuse
         * the same connection throughout the request lifecycle.
         *
         * Framework-agnostic — works in plain PHP, Symfony, Laravel, Slim,
         * or any PHP project.
         *
         * Symfony usage (services.yaml):
         *     services:
         *         PDO:
         *             factory: ['{$namespace}\\PdoFactory', 'create']
         *
         * Plain PHP usage:
         *     \$pdo = {$namespace}\\PdoFactory::create();
         *     \$repository = new EmployeeRepository(\$pdo);
         *
         * @package {$namespace}
         */
        final class PdoFactory
        {
            private const string CONFIG_PATH = 'config/pdoentitygenerator.yaml';

            private const array DB_DEFAULTS = [
                'host' => '127.0.0.1',
                'port' => 3306,
                'dbname' => '',
                'username' => 'root',
                'password' => '',
                'driver' => 'mysql',
            ];

            private static ?PDO \$instance = null;

            /**
             * Get or create a configured PDO connection from the project's YAML configuration
             *
             * Returns the same connection on subsequent calls (singleton pattern).
             * Call reset() to force a new connection on the next call.
             *
             * @param string|null \$configFile Optional absolute path to the YAML config file
             * @return PDO The configured database connection
             * @throws RuntimeException If the configuration file is missing, invalid, or connection fails
             */
            public static function create(?string \$configFile = null): PDO
            {
                if (self::\$instance !== null) {
                    return self::\$instance;
                }

                \$config = self::loadDatabaseConfig(\$configFile);

                \$dsn = sprintf(
                    '%s:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    \$config['driver'],
                    \$config['host'],
                    (int) \$config['port'],
                    \$config['dbname'],
                );

                try {
                    self::\$instance = new PDO(\$dsn, \$config['username'], \$config['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                } catch (PDOException \$e) {
                    throw new RuntimeException(
                        sprintf('Database connection failed: %s', \$e->getMessage())
                    );
                }

                return self::\$instance;
            }

            /**
             * Reset the singleton instance, forcing a new connection on the next create() call
             */
            public static function reset(): void
            {
                self::\$instance = null;
            }

            /**
             * Load database configuration from the YAML config file
             *
             * @param string|null \$configFile Optional absolute path to the config file
             * @return array{host: string, port: int, dbname: string, username: string, password: string, driver: string}
             * @throws RuntimeException If the configuration file is missing or invalid
             */
            private static function loadDatabaseConfig(?string \$configFile): array
            {
                \$configFile ??= self::resolveProjectRoot() . '/' . self::CONFIG_PATH;

                if (!file_exists(\$configFile)) {
                    throw new RuntimeException(
                        sprintf(
                            "Configuration file not found: %s\\nRun 'composer install' to generate the default configuration.",
                            \$configFile
                        )
                    );
                }

                \$parsed = Yaml::parseFile(\$configFile);

                if (!is_array(\$parsed) || !isset(\$parsed['database']) || !is_array(\$parsed['database'])) {
                    throw new RuntimeException(
                        sprintf('Invalid database configuration in %s', \$configFile)
                    );
                }

                \$config = array_merge(self::DB_DEFAULTS, \$parsed['database']);
                \$config['port'] = (int) \$config['port'];

                if (empty(\$config['dbname'])) {
                    throw new RuntimeException(
                        'Database name (database.dbname) must be specified in config/pdoentitygenerator.yaml'
                    );
                }

                return \$config;
            }

            /**
             * Resolve the project root by walking up from this file's directory
             *
             * @return string Absolute path to the project root containing composer.json
             */
            private static function resolveProjectRoot(): string
            {
                \$dir = __DIR__;

                while (\$dir !== '/' && \$dir !== '') {
                    if (file_exists(\$dir . '/composer.json')) {
                        return \$dir;
                    }
                    \$dir = dirname(\$dir);
                }

                return getcwd() ?: '.';
            }
        }

        PHP;
    }
}
