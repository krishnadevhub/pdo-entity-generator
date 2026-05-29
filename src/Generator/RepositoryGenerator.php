<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Generator;

/**
 * Generates PHP Repository class source code with PDO-based CRUD methods
 *
 * Produces a repository class with find, findAll, insert, update, and delete
 * methods. All queries use prepared statements with named parameters.
 *
 * @package kdevhub\PdoEntityGenerator\Generator
 */
final class RepositoryGenerator
{
    /**
     * Generate the full Repository class source code
     *
     * @param string $className The PascalCase entity class name
     * @param string $entityNamespace The namespace of the entity class
     * @param string $repositoryNamespace The namespace for the generated repository
     * @param string $tableName The database table name
     * @param list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $columns
     * @return string The complete PHP source code for the repository class
     */
    public function generate(
        string $className,
        string $entityNamespace,
        string $repositoryNamespace,
        string $tableName,
        array $columns,
    ): string {
        $repositoryClassName = $className . 'Repository';
        $primaryKey = $this->findPrimaryKey($columns);
        $nonPrimaryColumns = array_filter($columns, static fn (array $col): bool => !$col['isPrimary']);

        $hydrateBody = $this->buildHydrateBody($className, $columns);
        $insertMethod = $this->buildInsertMethod($className, $tableName, $primaryKey, $nonPrimaryColumns);
        $updateMethod = $this->buildUpdateMethod($className, $tableName, $primaryKey, $nonPrimaryColumns);

        $primaryProperty = EntityGenerator::snakeToCamelCase($primaryKey);
        $primaryGetter = 'get' . ucfirst($primaryProperty);
        $primaryPhpType = $this->getColumnPhpType($primaryKey, $columns);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$repositoryNamespace};

        use {$entityNamespace}\\{$className};

        class {$repositoryClassName}
        {
            public function __construct(
                private readonly \PDO \$pdo,
            ) {
            }

            public function find({$primaryPhpType} \$id): ?{$className}
            {
                \$sql = 'SELECT * FROM `{$tableName}` WHERE `{$primaryKey}` = :id LIMIT 1';
                \$statement = \$this->pdo->prepare(\$sql);
                \$statement->execute(['id' => \$id]);

                \$row = \$statement->fetch(\PDO::FETCH_ASSOC);

                if (\$row === false) {
                    return null;
                }

                return \$this->hydrateEntity(\$row);
            }

            /**
             * @return {$className}[]
             */
            public function findAll(): array
            {
                \$sql = 'SELECT * FROM `{$tableName}`';
                \$statement = \$this->pdo->query(\$sql);

                \$entities = [];
                while (\$row = \$statement->fetch(\PDO::FETCH_ASSOC)) {
                    \$entities[] = \$this->hydrateEntity(\$row);
                }

                return \$entities;
            }

        {$insertMethod}
        {$updateMethod}
            public function delete({$primaryPhpType} \$id): bool
            {
                \$sql = 'DELETE FROM `{$tableName}` WHERE `{$primaryKey}` = :id';
                \$statement = \$this->pdo->prepare(\$sql);
                \$statement->execute(['id' => \$id]);

                return \$statement->rowCount() > 0;
            }

        {$hydrateBody}
        }

        PHP;
    }

    /**
     * Find the primary key column name from the column list
     *
     * @param list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $columns
     * @return string The primary key column name, defaults to 'id'
     */
    private function findPrimaryKey(array $columns): string
    {
        foreach ($columns as $column) {
            if ($column['isPrimary']) {
                return $column['name'];
            }
        }

        return 'id';
    }

    /**
     * Get the PHP type for a specific column by name
     *
     * @param string $columnName The database column name to look up
     * @param list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $columns
     * @return string The PHP type, defaults to 'int'
     */
    private function getColumnPhpType(string $columnName, array $columns): string
    {
        foreach ($columns as $column) {
            if ($column['name'] === $columnName) {
                return $column['phpType'];
            }
        }

        return 'int';
    }

    /**
     * Build the private hydrateEntity method that maps a database row to an entity
     *
     * @param string $className The entity class name
     * @param list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $columns
     * @return string The hydrateEntity method source code
     */
    private function buildHydrateBody(string $className, array $columns): string
    {
        $assignments = '';

        foreach ($columns as $column) {
            $property = EntityGenerator::snakeToCamelCase($column['name']);
            $setter = $column['isPrimary'] ? null : 'set' . ucfirst($property);
            $colName = $column['name'];

            $value = match ($column['phpType']) {
                '\\DateTimeImmutable' => $column['nullable']
                    ? "(\$row['{$colName}'] !== null ? new \\DateTimeImmutable(\$row['{$colName}']) : null)"
                    : "new \\DateTimeImmutable(\$row['{$colName}'])",
                'int' => "(int) \$row['{$colName}']",
                'float' => "(float) \$row['{$colName}']",
                'bool' => "(bool) \$row['{$colName}']",
                default => "(string) \$row['{$colName}']",
            };

            if ($column['isPrimary']) {
                $assignments .= "        \$reflection = new \\ReflectionProperty(\$entity, '{$property}');\n";
                $assignments .= "        \$reflection->setValue(\$entity, {$value});\n\n";
            } else {
                $assignments .= "        \$entity->{$setter}({$value});\n";
            }
        }

        return <<<PHP
            private function hydrateEntity(array \$row): {$className}
            {
                \$entity = new {$className}();

        {$assignments}
                return \$entity;
            }
        PHP;
    }

    /**
     * Build the insert method with prepared statement parameter bindings
     *
     * @param string $className The entity class name
     * @param string $tableName The database table name
     * @param string $primaryKey The primary key column name
     * @param array<int, array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $nonPrimaryColumns
     * @return string The insert method source code
     */
    private function buildInsertMethod(
        string $className,
        string $tableName,
        string $primaryKey,
        array $nonPrimaryColumns,
    ): string {
        $columnNames = [];
        $placeholders = [];
        $paramBindings = [];

        foreach ($nonPrimaryColumns as $column) {
            $colName = $column['name'];
            $property = EntityGenerator::snakeToCamelCase($colName);
            $getter = 'get' . ucfirst($property);
            $columnNames[] = "`{$colName}`";
            $placeholders[] = ":{$colName}";

            $paramBindings[] = match ($column['phpType']) {
                '\\DateTimeImmutable' => "            '{$colName}' => \$entity->{$getter}()?->format('Y-m-d H:i:s'),",
                'bool' => "            '{$colName}' => (int) \$entity->{$getter}(),",
                default => "            '{$colName}' => \$entity->{$getter}(),",
            };
        }

        $cols = implode(', ', $columnNames);
        $vals = implode(', ', $placeholders);
        $params = implode("\n", $paramBindings);
        $primaryProperty = EntityGenerator::snakeToCamelCase($primaryKey);

        return <<<PHP
            public function insert({$className} \$entity): {$className}
            {
                \$sql = 'INSERT INTO `{$tableName}` ({$cols}) VALUES ({$vals})';
                \$statement = \$this->pdo->prepare(\$sql);
                \$statement->execute([
        {$params}
                ]);

                \$reflection = new \\ReflectionProperty(\$entity, '{$primaryProperty}');
                \$reflection->setValue(\$entity, (int) \$this->pdo->lastInsertId());

                return \$entity;
            }

        PHP;
    }

    /**
     * Build the update method with prepared statement parameter bindings
     *
     * @param string $className The entity class name
     * @param string $tableName The database table name
     * @param string $primaryKey The primary key column name
     * @param array<int, array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $nonPrimaryColumns
     * @return string The update method source code
     */
    private function buildUpdateMethod(
        string $className,
        string $tableName,
        string $primaryKey,
        array $nonPrimaryColumns,
    ): string {
        $setClauses = [];
        $paramBindings = [];

        foreach ($nonPrimaryColumns as $column) {
            $colName = $column['name'];
            $property = EntityGenerator::snakeToCamelCase($colName);
            $getter = 'get' . ucfirst($property);
            $setClauses[] = "`{$colName}` = :{$colName}";

            $paramBindings[] = match ($column['phpType']) {
                '\\DateTimeImmutable' => "            '{$colName}' => \$entity->{$getter}()?->format('Y-m-d H:i:s'),",
                'bool' => "            '{$colName}' => (int) \$entity->{$getter}(),",
                default => "            '{$colName}' => \$entity->{$getter}(),",
            };
        }

        $primaryProperty = EntityGenerator::snakeToCamelCase($primaryKey);
        $primaryGetter = 'get' . ucfirst($primaryProperty);
        $sets = implode(', ', $setClauses);
        $params = implode("\n", $paramBindings);

        return <<<PHP
            public function update({$className} \$entity): {$className}
            {
                \$sql = 'UPDATE `{$tableName}` SET {$sets} WHERE `{$primaryKey}` = :id';
                \$statement = \$this->pdo->prepare(\$sql);
                \$statement->execute([
        {$params}
                    'id' => \$entity->{$primaryGetter}(),
                ]);

                return \$entity;
            }

        PHP;
    }
}
