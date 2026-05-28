<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Generator;

final class RepositoryGenerator
{
    /**
     * @param list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $columns
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
            ) {}

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

    private function findPrimaryKey(array $columns): string
    {
        foreach ($columns as $column) {
            if ($column['isPrimary']) {
                return $column['name'];
            }
        }

        return 'id';
    }

    private function getColumnPhpType(string $columnName, array $columns): string
    {
        foreach ($columns as $column) {
            if ($column['name'] === $columnName) {
                return $column['phpType'];
            }
        }

        return 'int';
    }

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
                // Use reflection to set the primary key since there is no setter
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
