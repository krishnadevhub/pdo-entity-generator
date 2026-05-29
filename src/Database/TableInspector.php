<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Database;

use PDO;
use RuntimeException;

/**
 * Inspects a database table schema using the DESCRIBE query
 *
 * Reads column metadata (name, type, nullability, primary key) from a
 * MySQL/MariaDB table and maps SQL types to their PHP equivalents.
 *
 * @package kdevhub\PdoEntityGenerator\Database
 */
final class TableInspector
{
    /**
     * SQL type to PHP type mapping
     *
     * @var array<string, string>
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

    /**
     * @param PDO $pdo The PDO connection to the target database
     */
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Inspect a database table and return its column metadata
     *
     * Executes a DESCRIBE query against the given table and maps each
     * column to its PHP type, nullability, and primary key status.
     *
     * @param string $tableName The database table name to inspect
     * @return list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}>
     * @throws RuntimeException If the table does not exist or has no columns
     */
    public function inspect(string $tableName): array
    {
        $statement = $this->pdo->prepare('DESCRIBE ' . $this->quoteIdentifier($tableName));
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === false || count($rows) === 0) {
            throw new RuntimeException(sprintf('Table "%s" not found or has no columns.', $tableName));
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

    /**
     * Resolve a SQL column type to its PHP equivalent
     *
     * Checks for exact matches first (e.g. tinyint(1) for boolean),
     * then strips size/precision specifiers and tries the base type.
     *
     * @param string $sqlType The raw SQL type string from DESCRIBE output
     * @return string The corresponding PHP type
     */
    private function resolvePhpType(string $sqlType): string
    {
        $normalised = strtolower(trim($sqlType));

        if (isset(self::TYPE_MAP[$normalised])) {
            return self::TYPE_MAP[$normalised];
        }

        $baseType = preg_replace('/\(.*\)/', '', $normalised);
        $baseType = trim($baseType, ' unsigned zerofill');

        if (isset(self::TYPE_MAP[$baseType])) {
            return self::TYPE_MAP[$baseType];
        }

        return 'string';
    }

    /**
     * Quote a database identifier to prevent SQL injection
     *
     * @param string $identifier The table or column name to quote
     * @return string The quoted identifier wrapped in backticks
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
