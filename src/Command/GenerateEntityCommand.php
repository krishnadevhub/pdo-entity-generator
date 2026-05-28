<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Command;

use kdevhub\PdoEntityGenerator\Config\ConfigLoader;
use kdevhub\PdoEntityGenerator\Database\TableInspector;
use kdevhub\PdoEntityGenerator\Generator\EntityGenerator;
use kdevhub\PdoEntityGenerator\Generator\RepositoryGenerator;

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
     * @param list<string> $argv
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
        } catch (\RuntimeException $e) {
            $this->writeError($e->getMessage());
            return 1;
        }
    }

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

    private function resolveProjectRoot(): string
    {
        // Walk up from cwd looking for composer.json
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
     * @param array{host: string, port: int, dbname: string, username: string, password: string, driver: string} $dbConfig
     */
    private function createPdoConnection(array $dbConfig): \PDO
    {
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbConfig['driver'],
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['dbname'],
        );

        try {
            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                sprintf('Database connection failed: %s', $e->getMessage())
            );
        }

        return $pdo;
    }

    private function writeFile(string $projectRoot, string $directory, string $filename, string $content): void
    {
        $dirPath = rtrim($projectRoot, '/') . '/' . $directory;

        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $filePath = $dirPath . '/' . $filename;
        file_put_contents($filePath, $content);

        $this->writeLine(sprintf('  Created: %s/%s', $directory, $filename));
    }

    private function writeLine(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    private function writeError(string $message): void
    {
        fwrite(STDERR, 'Error: ' . $message . "\n");
    }
}
