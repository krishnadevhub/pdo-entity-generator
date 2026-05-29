<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Command;

use kdevhub\PdoEntityGenerator\Config\ConfigLoader;
use kdevhub\PdoEntityGenerator\Database\TableInspector;
use kdevhub\PdoEntityGenerator\Generator\EntityGenerator;
use kdevhub\PdoEntityGenerator\Generator\RepositoryGenerator;
use PDO;
use PDOException;
use RuntimeException;

/**
 * CLI command that orchestrates entity and repository generation
 *
 * Parses command-line arguments, loads configuration, connects to the
 * database, inspects the table schema, and generates Entity and Repository
 * PHP source files in the configured output directories.
 *
 * @package kdevhub\PdoEntityGenerator\Command
 */
final class GenerateEntityCommand
{
    private const string USAGE = <<<'TEXT'
    PDO Entity Generator

    Usage:
      vendor/bin/pdoentitygenerator table <table_name>

    Arguments:
      table         The command to execute
      <table_name>  The database table name to generate from

    Options:
      --help        Display this help message
    TEXT;

    /**
     * Run the CLI command with the given arguments
     *
     * @param list<string> $argv The command-line arguments
     * @return int Exit code (0 for success, 1 for failure)
     */
    public function run(array $argv): int
    {
        if (count($argv) < 3 || in_array('--help', $argv, true)) {
            $this->writeLine(self::USAGE);
            return count($argv) > 1 && in_array('--help', $argv, true) ? 0 : 1;
        }

        $command = $argv[1] ?? '';
        $tableName = $argv[2] ?? '';

        if ($command !== 'table') {
            $this->writeError(sprintf('Unknown command: "%s". Use "table <table_name>".', $command));
            return 1;
        }

        if (empty($tableName)) {
            $this->writeError('Table name is required. Usage: vendor/bin/pdoentitygenerator table <table_name>');
            return 1;
        }

        try {
            return $this->execute($tableName);
        } catch (RuntimeException $e) {
            $this->writeError($e->getMessage());
            return 1;
        }
    }

    /**
     * Execute the generation pipeline for the given table
     *
     * @param string $tableName The database table name to generate from
     * @return int Exit code (0 for success)
     * @throws RuntimeException If configuration, connection, or generation fails
     */
    private function execute(string $tableName): int
    {
        $projectRoot = $this->resolveProjectRoot();
        $this->writeLine(sprintf('Project root: %s', $projectRoot));

        $configLoader = new ConfigLoader();
        $config = $configLoader->load($projectRoot);
        $this->writeLine(sprintf('Database: %s', $config['database']['dbname']));

        $pdo = $this->createPdoConnection($config['database']);
        $this->writeLine('Connected to database.');

        $inspector = new TableInspector($pdo);
        $columns = $inspector->inspect($tableName);
        $this->writeLine(sprintf('Found %d column(s) in table "%s".', count($columns), $tableName));

        $className = EntityGenerator::snakeToPascalCase($tableName);

        $entityGenerator = new EntityGenerator();
        $entityCode = $entityGenerator->generate(
            $className,
            $config['output']['entity_namespace'],
            $columns,
        );

        $repositoryGenerator = new RepositoryGenerator();
        $repositoryCode = $repositoryGenerator->generate(
            $className,
            $config['output']['entity_namespace'],
            $config['output']['repository_namespace'],
            $tableName,
            $columns,
        );

        $this->writeFile(
            $projectRoot,
            $config['output']['entity_directory'],
            $className . '.php',
            $entityCode,
        );

        $this->writeFile(
            $projectRoot,
            $config['output']['repository_directory'],
            $className . 'Repository.php',
            $repositoryCode,
        );

        $this->writeLine('');
        $this->writeLine('Generation complete!');

        return 0;
    }

    /**
     * Resolve the project root by walking up from the current directory
     *
     * @return string Absolute path to the project root containing composer.json
     */
    private function resolveProjectRoot(): string
    {
        $dir = getcwd() ?: '.';

        while ($dir !== '/') {
            if (file_exists($dir . '/composer.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return getcwd() ?: '.';
    }

    /**
     * Create a PDO connection from the database configuration
     *
     * @param array{host: string, port: int, dbname: string, username: string, password: string, driver: string} $dbConfig
     * @return PDO The established database connection
     * @throws RuntimeException If the connection cannot be established
     */
    private function createPdoConnection(array $dbConfig): PDO
    {
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbConfig['driver'],
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['dbname'],
        );

        try {
            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                sprintf('Database connection failed: %s', $e->getMessage())
            );
        }

        return $pdo;
    }

    /**
     * Write generated source code to a file in the specified directory
     *
     * Creates the output directory if it does not exist.
     *
     * @param string $projectRoot Absolute path to the project root
     * @param string $directory The relative output directory path
     * @param string $filename The output file name
     * @param string $content The generated PHP source code
     * @return void
     */
    private function writeFile(string $projectRoot, string $directory, string $filename, string $content): void
    {
        $dirPath = rtrim($projectRoot, '/') . '/' . $directory;

        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
            chmod($dirPath, 0777);
        }

        $filePath = $dirPath . '/' . $filename;
        file_put_contents($filePath, $content);
        chmod($filePath, 0666);

        $this->writeLine(sprintf('  Created: %s/%s', $directory, $filename));
    }

    /**
     * Write a message to standard output
     *
     * @param string $message The message to output
     * @return void
     */
    private function writeLine(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    /**
     * Write an error message to standard error
     *
     * @param string $message The error message to output
     * @return void
     */
    private function writeError(string $message): void
    {
        fwrite(STDERR, 'Error: ' . $message . "\n");
    }
}
