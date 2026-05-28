<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Database;

final class TableInspector
{
    /**
     * @var array<string, string> SQL type to PHP type mapping
     */
    private const array TYPE_MAP = [
        'tinyint(1)' => 'bool',
        'boolean'    => 'bool',
        'bool'       => 'bool',
        'tinyint'    => 'int',
        'smallint'   => 'int',
        'mediumint'  => 'int',
        'int'        => 'int',
        'integer'    => 'int',
        'bigint'     => 'int',
        'float'      => 'float',
        'double'     => 'float',
        'decimal'    => 'float',
        'numeric'    => 'float',
        'real'       => 'float',
        'char'       => 'string',
        'varchar'    => 'string',
        'tinytext'   => 'string',
        'text'       => 'string',
        'mediumtext' => 'string',
        'longtext'   => 'string',
        'enum'       => 'string',
        'set'        => 'string',
        'json'       => 'string',
        'blob'       => 'string',
        'binary'     => 'string',
        'varbinary'  => 'string',
        'date'       => '\\DateTimeImmutable',
        'datetime'   => '\\DateTimeImmutable',
        'timestamp'  => '\\DateTimeImmutable',
        'time'       => 'string',
        'year'       => 'int',
    ];

    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    /**
     * @return list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}>
     */
    public function inspect(string $tableName): array
    {
        $statement = $this->pdo->prepare('DESCRIBE ' . $this->quoteIdentifier($tableName));
        $statement->execute();

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === false || count($rows) === 0) {
            throw new \RuntimeException(sprintf('Table "%s" not found or has no columns.', $tableName));
        }

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['Field'],
                'phpType' => $this->resolvePhpType($row['Type']),
                'nullable' => $row['Null'] === 'YES',
                'isPrimary' => $row['Key'] === 'PRI',
                'hasDefault' => $row['Default'] !== null || $row['Null'] === 'YES',
            ];
        }

        return $columns;
    }

    private function resolvePhpType(string $sqlType): string
    {
        $normalised = strtolower(trim($sqlType));

        // Check for exact match first (e.g. tinyint(1) for boolean)
        if (isset(self::TYPE_MAP[$normalised])) {
            return self::TYPE_MAP[$normalised];
        }

        // Strip size/precision specifiers: int(11) → int, varchar(255) → varchar
        $baseType = preg_replace('/\(.*\)/', '', $normalised);
        $baseType = trim($baseType, ' unsigned zerofill');

        if (isset(self::TYPE_MAP[$baseType])) {
            return self::TYPE_MAP[$baseType];
        }

        return 'string';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
