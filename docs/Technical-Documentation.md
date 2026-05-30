# PDO Entity Generator — Technical Documentation

> Internal architecture, class reference, design decisions, and extension guide for developers working on the PDO Entity Generator codebase.

---

## Table of Contents

- [System Design](#system-design)
  - [Design Principles](#design-principles)
  - [Design Patterns](#design-patterns)
  - [Dependency Graph](#dependency-graph)
- [Class Reference](#class-reference)
  - [GenerateEntityCommand](#generateentitycommand)
  - [PostInstallHandler](#postinstallhandler)
  - [ConfigLoader](#configloader)
  - [TableInspector](#tableinspector)
  - [EntityGenerator](#entitygenerator)
  - [RepositoryGenerator](#repositorygenerator)
  - [PdoFactoryGenerator](#pdofactorygenerator)
- [Data Flow](#data-flow)
  - [CLI Execution Pipeline](#cli-execution-pipeline)
  - [Composer Plugin Lifecycle](#composer-plugin-lifecycle)
  - [Column Metadata Structure](#column-metadata-structure)
  - [Configuration Structure](#configuration-structure)
- [Code Generation Internals](#code-generation-internals)
  - [Entity Generation](#entity-generation)
  - [Repository Generation](#repository-generation)
  - [PdoFactory Generation](#pdofactory-generation)
  - [Heredoc Template System](#heredoc-template-system)
  - [Type Handling in Generated Code](#type-handling-in-generated-code)
- [Error Handling Strategy](#error-handling-strategy)
- [Security Considerations](#security-considerations)
- [Performance Considerations](#performance-considerations)
- [Dependencies](#dependencies)
- [Extension Guide](#extension-guide)
  - [Adding a New Database Driver](#adding-a-new-database-driver)
  - [Adding Custom Generated Methods](#adding-custom-generated-methods)
  - [Adding New Column Type Support](#adding-new-column-type-support)

---

## System Design

### Design Principles

The codebase is built around these core principles:

1. **Single Responsibility** — Each class handles exactly one concern: CLI parsing, config loading, schema inspection, entity generation, or repository generation.
2. **No Framework Coupling** — The tool has no dependency on any PHP framework. It uses raw PDO and the Symfony YAML component as its only runtime dependencies (beyond the Composer plugin API).
3. **Immutability Where Possible** — Constructor-promoted `readonly` properties are used for injected dependencies (e.g. `PDO` in `TableInspector`). Generated entity classes use immutable primary keys (no setter).
4. **Fail Fast** — `RuntimeException` is thrown at the earliest point of failure (missing config, invalid YAML, empty database name, connection failure, missing table).
5. **Convention Over Configuration** — Sensible defaults are provided for all configuration values except `database.dbname`, which is the only mandatory field.

### Design Patterns

| Pattern | Where Used | Purpose |
|---------|------------|---------|
| **Command** | `GenerateEntityCommand` | Encapsulates the CLI action as an object with a `run()` method |
| **Plugin / Observer** | `PostInstallHandler` | Subscribes to Composer lifecycle events to auto-create config |
| **Builder** | `EntityGenerator`, `RepositoryGenerator` | Constructs PHP source code incrementally via private builder methods |
| **Strategy (implicit)** | `TableInspector::TYPE_MAP` | Maps SQL types to PHP types via a lookup table rather than conditional logic |
| **Template Method** | `RepositoryGenerator::generate()` | Defines the skeleton of the repository class, delegating method bodies to `buildInsertMethod()`, `buildUpdateMethod()`, `buildHydrateBody()` |

### Dependency Graph

```
bin/pdoentitygenerator
  └── GenerateEntityCommand
        ├── ConfigLoader
        │     └── Symfony\Component\Yaml\Yaml
        ├── TableInspector
        │     └── PDO (runtime)
        ├── EntityGenerator
        ├── RepositoryGenerator
        │     └── EntityGenerator (static methods only)
        └── PdoFactoryGenerator

PostInstallHandler (Composer Plugin — independent entry point)
  ├── Composer\Plugin\PluginInterface
  ├── Composer\EventDispatcher\EventSubscriberInterface
  └── ConfigLoader (static method only)
```

Key observations:

- `RepositoryGenerator` depends on `EntityGenerator` for the static utility methods `snakeToCamelCase()` and `snakeToPascalCase()`. This is the only cross-generator coupling.
- `PdoFactoryGenerator` is fully self-contained with no dependencies on other generators.
- `PostInstallHandler` uses `ConfigLoader::getDefaultConfigContent()` as a static call to avoid duplicating the default YAML template.
- `PDO` is created inside `GenerateEntityCommand` and passed to `TableInspector` via constructor injection.

---

## Class Reference

### GenerateEntityCommand

**Namespace**: `kdevhub\PdoEntityGenerator\Command`
**File**: `src/Command/GenerateEntityCommand.php`
**Modifier**: `final`

Orchestrates the entire CLI generation pipeline. Parses arguments, loads configuration, connects to the database, inspects the table, generates source code, and writes files to disc.

#### Public Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `run` | `run(array $argv): int` | Exit code | Entry point. Parses CLI args and delegates to `execute()`. Returns `0` on success, `1` on failure. |

#### Private Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `execute` | `execute(string $tableName): int` | Exit code | Runs the full generation pipeline for the given table name. |
| `resolveProjectRoot` | `resolveProjectRoot(): string` | Absolute path | Walks up from `getcwd()` to find the nearest directory containing `composer.json`. |
| `createPdoConnection` | `createPdoConnection(array $dbConfig): PDO` | PDO instance | Creates a PDO connection with exception mode, associative fetch, and native prepared statements enabled. |
| `writeFile` | `writeFile(string $projectRoot, string $directory, string $filename, string $content): void` | void | Writes generated source code to disc. Creates the output directory if it does not exist. |
| `generateFactory` | `generateFactory(string $projectRoot, string $directory, string $namespace): void` | void | Generates the `PdoFactory.php` class in the configured factory directory. Skips if the file already exists. |
| `writeLine` | `writeLine(string $message): void` | void | Writes a message to `STDOUT`. |
| `writeError` | `writeError(string $message): void` | void | Writes an error message to `STDERR` with `Error: ` prefix. |

#### Constants

| Constant | Type | Description |
|----------|------|-------------|
| `USAGE` | `string` | The CLI help text displayed when `--help` is passed or arguments are insufficient. |

#### PDO Configuration

The PDO connection is created with the following attributes:

| Attribute | Value | Purpose |
|-----------|-------|---------|
| `PDO::ATTR_ERRMODE` | `PDO::ERRMODE_EXCEPTION` | Throws `PDOException` on query errors |
| `PDO::ATTR_DEFAULT_FETCH_MODE` | `PDO::FETCH_ASSOC` | Returns associative arrays by default |
| `PDO::ATTR_EMULATE_PREPARES` | `false` | Uses native prepared statements for security |

The DSN string format: `{driver}:host={host};port={port};dbname={dbname};charset=utf8mb4`

---

### PostInstallHandler

**Namespace**: `kdevhub\PdoEntityGenerator\Composer`
**File**: `src/Composer/PostInstallHandler.php`
**Modifier**: `final`
**Implements**: `PluginInterface`, `EventSubscriberInterface`

Composer Plugin that hooks into `post-install-cmd` and `post-update-cmd` lifecycle events to auto-create the default configuration file.

#### Public Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `activate` | `activate(Composer $composer, IOInterface $io): void` | void | Required by `PluginInterface`. No-op. |
| `deactivate` | `deactivate(Composer $composer, IOInterface $io): void` | void | Required by `PluginInterface`. No-op. |
| `uninstall` | `uninstall(Composer $composer, IOInterface $io): void` | void | Required by `PluginInterface`. No-op. |
| `getSubscribedEvents` | `static getSubscribedEvents(): array` | Event map | Returns `POST_INSTALL_CMD` and `POST_UPDATE_CMD` mapped to `onPostInstall`. |
| `onPostInstall` | `onPostInstall(Event $event): void` | void | Creates `config/pdoentitygenerator.yaml` if it does not already exist. |

#### Private Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `resolveProjectRoot` | `resolveProjectRoot(Event $event): string` | Absolute path | Resolves the host project root via Composer's `vendor-dir` config. Falls back to `getcwd()`. |

#### Constants

| Constant | Type | Value |
|----------|------|-------|
| `CONFIG_DIR` | `string` | `'config'` |
| `CONFIG_FILE` | `string` | `'config/pdoentitygenerator.yaml'` |

#### Behaviour

1. Resolves the project root from the Composer vendor directory path.
2. Checks whether `config/pdoentitygenerator.yaml` already exists.
3. If yes → writes a skip message to the Composer IO and returns.
4. If no → creates the `config/` directory (if needed) and writes the default YAML content via `ConfigLoader::getDefaultConfigContent()`.

---

### ConfigLoader

**Namespace**: `kdevhub\PdoEntityGenerator\Config`
**File**: `src/Config/ConfigLoader.php`
**Modifier**: `final`

Loads, validates, and merges YAML configuration with defaults.

#### Public Methods

| Method | Signature | Return | Throws | Description |
|--------|-----------|--------|--------|-------------|
| `load` | `load(string $projectRoot): array` | Validated config array | `RuntimeException` | Reads `config/pdoentitygenerator.yaml`, merges with defaults, validates required fields. |
| `getDefaultConfigContent` | `static getDefaultConfigContent(): string` | YAML string | — | Returns the default YAML template content for new installations. |

#### Private Methods

| Method | Signature | Return | Throws | Description |
|--------|-----------|--------|--------|-------------|
| `mergeWithDefaults` | `mergeWithDefaults(array $parsed): array` | Merged config | `RuntimeException` | Deep-merges parsed YAML with `DEFAULTS`. Casts `port` to `int`. Validates `dbname` is non-empty. |

#### Constants

| Constant | Type | Description |
|----------|------|-------------|
| `CONFIG_PATH` | `string` | `'config/pdoentitygenerator.yaml'` — relative path from project root |
| `DEFAULTS` | `array` | Default configuration values for all settings |

#### Default Values

```php
[
    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'dbname'   => '',        // Must be overridden — validated
        'username' => 'root',
        'password' => '',
        'driver'   => 'mysql',
    ],
    'output' => [
        'entity_namespace'     => 'App\\Entity',
        'repository_namespace' => 'App\\Repository',
        'factory_namespace'    => 'App\\Factory',
        'entity_directory'     => 'src/Entity',
        'repository_directory' => 'src/Repository',
        'factory_directory'    => 'src/Factory',
    ],
]
```

#### Validation Rules

| Rule | Error Message |
|------|---------------|
| Config file must exist | `Configuration file not found: {path}` |
| Parsed YAML must be an array | `Invalid configuration format in {path}. Expected YAML mapping.` |
| `database.dbname` must be non-empty | `Database name (database.dbname) must be specified in config/pdoentitygenerator.yaml` |

---

### TableInspector

**Namespace**: `kdevhub\PdoEntityGenerator\Database`
**File**: `src/Database/TableInspector.php`
**Modifier**: `final`

Introspects a MySQL/MariaDB table schema using the `DESCRIBE` query.

#### Constructor

```php
public function __construct(
    private readonly PDO $pdo,
)
```

#### Public Methods

| Method | Signature | Return | Throws | Description |
|--------|-----------|--------|--------|-------------|
| `inspect` | `inspect(string $tableName): array` | Column metadata list | `RuntimeException` | Executes `DESCRIBE` and maps each column to its PHP type, nullability, and primary key status. |

#### Private Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `resolvePhpType` | `resolvePhpType(string $sqlType): string` | PHP type string | Resolves a SQL type to its PHP equivalent. Checks exact matches first, then strips size/precision specifiers. Falls back to `'string'`. |
| `quoteIdentifier` | `quoteIdentifier(string $identifier): string` | Backtick-quoted string | Wraps identifier in backticks with escaping to prevent SQL injection. |

#### Column Metadata Schema

Each element in the returned array has the following structure:

```php
[
    'name'       => string,  // Column name from DESCRIBE output
    'phpType'    => string,  // Resolved PHP type (e.g. 'int', 'string', '\\DateTimeImmutable')
    'nullable'   => bool,    // true if column allows NULL
    'isPrimary'  => bool,    // true if column is the primary key
    'hasDefault' => bool,    // true if column has a default value or is nullable
]
```

#### Type Resolution Algorithm

1. Normalise the SQL type to lowercase and trim whitespace.
2. Check for an exact match in `TYPE_MAP` (e.g. `tinyint(1)` → `bool`).
3. If no exact match, strip size/precision specifiers via `preg_replace('/\(.*\)/', '', ...)`.
4. Trim `unsigned` and `zerofill` suffixes from the base type.
5. Check the base type against `TYPE_MAP`.
6. If still no match, default to `'string'`.

This two-pass approach ensures that `tinyint(1)` is correctly identified as `bool` before the generic `tinyint` → `int` mapping is applied.

#### Complete TYPE_MAP

```php
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
```

---

### EntityGenerator

**Namespace**: `kdevhub\PdoEntityGenerator\Generator`
**File**: `src/Generator/EntityGenerator.php`
**Modifier**: `final`

Generates PHP Entity class source code from database column metadata.

#### Public Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `generate` | `generate(string $className, string $namespace, array $columns): string` | PHP source code | Generates the full entity class with properties, getters, and setters. |
| `snakeToCamelCase` | `static snakeToCamelCase(string $value): string` | camelCase string | Converts `snake_case` to `camelCase`. Used by both generators. |
| `snakeToPascalCase` | `static snakeToPascalCase(string $value): string` | PascalCase string | Converts `snake_case` to `PascalCase`. Used for class name derivation. |

#### Private Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `resolveDefaultValue` | `resolveDefaultValue(array $column): string` | Default value expression | Returns the default value assignment string based on column type and nullability. |
| `buildGetter` | `buildGetter(string $propertyName, string $typeHint): string` | Method source | Builds a getter method for the given property. |
| `buildSetter` | `buildSetter(string $propertyName, string $typeHint, string $className): string` | Method source | Builds a fluent setter method that returns `self`. |
| `buildClassTemplate` | `buildClassTemplate(string $className, string $namespace, string $properties, string $gettersSetters): string` | Class source | Assembles the complete class from its parts using a heredoc template. |
| `normaliseIndentation` | `normaliseIndentation(string $code): string` | Re-indented code | Normalises heredoc output to standard 4-space indentation. |

#### Default Value Resolution

| Condition | Default Value |
|-----------|---------------|
| Primary key column | `= null` |
| Nullable column | `= null` |
| `string` type | `= ''` |
| `int` type | `= 0` |
| `float` type | `= 0.0` |
| `bool` type | `= false` |
| Any other type | `= null` |

#### Generation Rules

- Primary key properties are nullable (`?Type`) with no setter — the value is set via reflection in the repository.
- Nullable columns use the `?` type prefix.
- Setters are fluent — they return `self` for method chaining.
- All properties are `private`.

---

### PdoFactoryGenerator

**Namespace**: `kdevhub\PdoEntityGenerator\Generator`
**File**: `src/Generator/PdoFactoryGenerator.php`
**Modifier**: `final`

Generates a framework-agnostic PHP factory class that reads database credentials from `config/pdoentitygenerator.yaml` and returns a configured `PDO` instance. Uses a singleton pattern to reuse the same connection throughout the request lifecycle. Works in plain PHP, Symfony (via `factory:` in `services.yaml`), Laravel, Slim, or any PHP project.

#### Public Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `generate` | `generate(string $namespace): string` | PHP source code | Generates the full `PdoFactory` class with `create()`, `reset()`, `loadDatabaseConfig()`, and `resolveProjectRoot()` methods. |

#### Generated Class Structure

The generated `PdoFactory` class contains:

| Member | Type | Description |
|--------|------|-------------|
| `CONFIG_PATH` | `const string` | Relative path to the YAML config file (`config/pdoentitygenerator.yaml`) |
| `DB_DEFAULTS` | `const array` | Default database configuration values |
| `$instance` | `private static ?PDO` | Singleton instance — holds the cached PDO connection |
| `create(?string $configFile = null): PDO` | `public static` | Returns the singleton PDO connection, creating it on first call. Accepts an optional config file path override. |
| `reset(): void` | `public static` | Clears the singleton instance, forcing a new connection on the next `create()` call. |
| `loadDatabaseConfig(?string $configFile): array` | `private static` | Loads and validates database configuration from the YAML file. |
| `resolveProjectRoot(): string` | `private static` | Walks up from `__DIR__` to find the nearest directory containing `composer.json`. |

#### PDO Configuration

The generated factory creates PDO connections with the same attributes as `GenerateEntityCommand::createPdoConnection()`:

| Attribute | Value | Purpose |
|-----------|-------|---------|
| `PDO::ATTR_ERRMODE` | `PDO::ERRMODE_EXCEPTION` | Throws `PDOException` on query errors |
| `PDO::ATTR_DEFAULT_FETCH_MODE` | `PDO::FETCH_ASSOC` | Returns associative arrays by default |
| `PDO::ATTR_EMULATE_PREPARES` | `false` | Uses native prepared statements for security |

---

### RepositoryGenerator

**Namespace**: `kdevhub\PdoEntityGenerator\Generator`
**File**: `src/Generator/RepositoryGenerator.php`
**Modifier**: `final`

Generates PHP Repository class source code with PDO-based CRUD methods.

#### Public Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `generate` | `generate(string $className, string $entityNamespace, string $repositoryNamespace, string $factoryNamespace, string $tableName, array $columns): string` | PHP source code | Generates the full repository class with all CRUD methods and a static `create()` factory method. |

#### Private Methods

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `findPrimaryKey` | `findPrimaryKey(array $columns): string` | Column name | Finds the primary key column name. Defaults to `'id'` if none found. |
| `getColumnPhpType` | `getColumnPhpType(string $columnName, array $columns): string` | PHP type | Looks up the PHP type for a column by name. Defaults to `'int'`. |
| `resolvePdoParamType` | `resolvePdoParamType(string $phpType): string` | PDO constant string | Maps a PHP type to the corresponding `PDO::PARAM_*` constant code string. |
| `buildBindValueLine` | `buildBindValueLine(string $colName, string $getter, array $column): string` | Generated code line(s) | Builds a `bindValue()` call with the correct PDO type constant. Uses a local variable for nullable columns. |
| `buildHydrateBody` | `buildHydrateBody(string $className, array $columns): string` | Method source | Builds the private `hydrateEntity()` method that maps a database row to an entity. |
| `buildInsertMethod` | `buildInsertMethod(string $className, string $tableName, string $primaryKey, array $nonPrimaryColumns): string` | Method source | Builds the `insert()` method with `bindValue()` parameter bindings. |
| `buildUpdateMethod` | `buildUpdateMethod(string $className, string $tableName, string $primaryKey, array $nonPrimaryColumns, array $allColumns): string` | Method source | Builds the `update()` method with `bindValue()` parameter bindings. |

#### Generated Method Specifications

**`create(): self`** (static)
- Creates a new repository instance using `PdoFactory::create()` for the database connection.
- Provides a convenient alternative to manual constructor injection.

**`find($id): ?Entity`**
- SQL: `SELECT * FROM \`table\` WHERE \`pk\` = :id LIMIT 1`
- Binds the primary key with `bindValue(':id', $id, \PDO::PARAM_INT)`.
- Returns `null` if no row found.
- Hydrates a single entity via `hydrateEntity()`.

**`findAll(): Entity[]`**
- SQL: `SELECT * FROM \`table\``
- Returns an array of hydrated entities.

**`insert(Entity $entity): Entity`**
- SQL: `INSERT INTO \`table\` (cols...) VALUES (:params...)`
- Excludes the primary key column from the INSERT.
- Each column is bound with `bindValue()` using the correct `PDO::PARAM_*` type.
- After execution, sets the auto-generated ID on the entity via `ReflectionProperty`.
- Returns the entity with the populated ID.

**`update(Entity $entity): Entity`**
- SQL: `UPDATE \`table\` SET col = :col, ... WHERE \`pk\` = :id`
- Updates all non-primary-key columns, each bound with `bindValue()` and the correct PDO type.
- Primary key is bound with `bindValue(':id', $entity->getId(), \PDO::PARAM_INT)`.
- Returns the entity unchanged.

**`delete($id): bool`**
- SQL: `DELETE FROM \`table\` WHERE \`pk\` = :id`
- Binds the primary key with `bindValue(':id', $id, \PDO::PARAM_INT)`.
- Returns `true` if at least one row was affected.

**`hydrateEntity(array $row): Entity` (private)**
- Creates a new entity instance.
- Sets the primary key via `ReflectionProperty` (bypassing the missing setter).
- Sets all other properties via their setter methods.
- Handles type casting:

| PHP Type | Hydration Logic |
|----------|----------------|
| `int` | `(int) $row['col']` |
| `float` | `(float) $row['col']` |
| `bool` | `(bool) $row['col']` |
| `\DateTimeImmutable` | `new \DateTimeImmutable($row['col'])` (with null check if nullable) |
| `string` (default) | `(string) $row['col']` |

#### Parameter Binding for Writes

All write operations use `bindValue()` with explicit PDO type constants:

| PHP Type | PDO Type Constant | Value Expression |
|----------|-------------------|------------------|
| `int` | `\PDO::PARAM_INT` | `$entity->getX()` |
| `bool` | `\PDO::PARAM_BOOL` | `$entity->getX()` |
| `string` | `\PDO::PARAM_STR` | `$entity->getX()` |
| `float` | `\PDO::PARAM_STR` | `$entity->getX()` |
| `\DateTimeImmutable` | `\PDO::PARAM_STR` | `$entity->getX()?->format('Y-m-d H:i:s')` |
| Nullable (any type) | Typed constant or `\PDO::PARAM_NULL` | Local variable with ternary null check |

---

## Data Flow

### CLI Execution Pipeline

```
User Input                    Internal Processing                       Output
─────────                     ───────────────────                       ──────

argv[1] = "table"    ──►  GenerateEntityCommand::run()
argv[2] = "users"           │
                            ├── resolveProjectRoot()        ──►  /path/to/project
                            │
                            ├── ConfigLoader::load()        ──►  {database: {...}, output: {...}}
                            │     └── Yaml::parseFile()
                            │     └── mergeWithDefaults()
                            │
                            ├── createPdoConnection()       ──►  PDO instance
                            │
                            ├── TableInspector::inspect()   ──►  [{name, phpType, nullable, isPrimary, hasDefault}, ...]
                            │     └── DESCRIBE `users`
                            │     └── resolvePhpType()
                            │
                            ├── snakeToPascalCase("users")  ──►  "Users"
                            │
                            ├── EntityGenerator::generate() ──►  PHP source code string
                            │     └── buildGetter() × N
                            │     └── buildSetter() × N
                            │     └── buildClassTemplate()
                            │
                            ├── RepositoryGenerator::generate() ──► PHP source code string
                            │     └── buildHydrateBody()
                            │     └── buildInsertMethod()
                            │     └── buildUpdateMethod()
                            │
                            ├── writeFile("src/Entity/Users.php")
                            ├── writeFile("src/Repository/UsersRepository.php")
                            └── generateFactory("src/Factory", "App\\Factory")
                                  └── PdoFactoryGenerator::generate()
```

### Composer Plugin Lifecycle

```
composer install / composer update
        │
        ▼
Composer dispatches POST_INSTALL_CMD / POST_UPDATE_CMD
        │
        ▼
PostInstallHandler::onPostInstall(Event $event)
        │
        ├── resolveProjectRoot() via vendor-dir
        │
        ├── Does config/pdoentitygenerator.yaml exist?
        │     │
        │     ├── YES → Log skip message → return
        │     │
        │     └── NO  → mkdir config/ (if needed)
        │              → file_put_contents(ConfigLoader::getDefaultConfigContent())
        │              → Log creation message
        │
        └── return
```

### Column Metadata Structure

The `TableInspector::inspect()` method returns a list of column metadata arrays. This is the central data structure that flows through both generators:

```php
// Type alias for the column metadata (used in PHPDoc throughout the codebase)
list<array{
    name: string,       // Database column name (e.g. 'created_at')
    phpType: string,    // PHP type (e.g. 'string', 'int', '\\DateTimeImmutable')
    nullable: bool,     // Whether the column allows NULL
    isPrimary: bool,    // Whether the column is the primary key
    hasDefault: bool,   // Whether the column has a default value or is nullable
}>
```

### Configuration Structure

```php
// Return type of ConfigLoader::load()
array{
    database: array{
        host: string,
        port: int,
        dbname: string,
        username: string,
        password: string,
        driver: string,
    },
    output: array{
        entity_namespace: string,
        repository_namespace: string,
        factory_namespace: string,
        entity_directory: string,
        repository_directory: string,
        factory_directory: string,
    },
}
```

---

## Code Generation Internals

### Entity Generation

The `EntityGenerator` builds a class in four stages:

1. **Property declarations** — iterates over columns, determines type hint (with `?` if nullable/primary), resolves default value, and generates `private $type $name = default;` lines.
2. **Getter methods** — generates a `getPropertyName(): Type` method for every column.
3. **Setter methods** — generates a `setPropertyName(Type $val): self` method for every non-primary column.
4. **Class assembly** — wraps properties and methods in the class template with `declare(strict_types=1)`, namespace, and class declaration.

### PdoFactory Generation

The `PdoFactoryGenerator` produces a self-contained factory class in a single stage:

1. **Class assembly** — generates the complete `PdoFactory` class via a heredoc template, interpolating the provided namespace. The generated class uses a singleton pattern with a static `$instance` property, `CONFIG_PATH` and `DB_DEFAULTS` constants, a public `create()` method that returns the cached connection (or creates one on first call), a public `reset()` method to clear the singleton, a private `loadDatabaseConfig()` method that reads and validates the YAML config, and a private `resolveProjectRoot()` method that walks up from `__DIR__` to find `composer.json`.

The factory is generated once per project and skipped on subsequent runs if the file already exists, ensuring it does not overwrite any user customisations.

### Repository Generation

The `RepositoryGenerator` builds a class in these stages:

1. **Primary key identification** — scans columns for `isPrimary === true`; defaults to `'id'`.
2. **Non-primary column filtering** — separates columns used in INSERT/UPDATE from the primary key.
3. **PDO type resolution** — maps each column's PHP type to the corresponding `PDO::PARAM_*` constant via `resolvePdoParamType()`.
4. **Bind statement generation** — builds `bindValue()` calls for each column via `buildBindValueLine()`, handling nullable columns with local variables.
5. **Method generation** — builds `insert`, `update`, and `hydrateEntity` methods via dedicated builder methods. The `find`, `findAll`, and `delete` methods are inline in the main heredoc template.
6. **Class assembly** — wraps everything in the repository class template with constructor, imports, and namespace.

### Heredoc Template System

Both generators use PHP heredoc syntax (`<<<PHP ... PHP;`) for code templates. Key considerations:

- **Indentation control**: The closing `PHP;` marker's position determines the base indentation that gets stripped from the output. The `normaliseIndentation()` method in `EntityGenerator` handles additional indentation corrections for heredoc blocks nested inside methods.
- **Variable interpolation**: Uses `{$variable}` syntax within double-quoted heredocs.
- **Escaping**: Dollar signs that should appear literally in generated output are escaped as `\$`.
- **Multi-line assembly**: Complex method bodies are built as strings and interpolated into the outer class template.

### Type Handling in Generated Code

The type system flows through three layers:

```
SQL Type (from DESCRIBE)
    │
    ▼ TableInspector::resolvePhpType()
PHP Type (string representation)
    │
    ├──▶ EntityGenerator: property types, getter/setter signatures
    │
    └──▶ RepositoryGenerator:
           ├── hydrateEntity(): type casting from DB row → entity
           ├── buildInsertMethod(): parameter binding entity → DB
           └── buildUpdateMethod(): parameter binding entity → DB
```

Special handling per type:

| PHP Type | Entity | Hydration (DB → PHP) | Binding (PHP → DB) | PDO Type |
|----------|--------|---------------------|---------------------|----------|
| `int` | `private ?int $id = null` | `(int) $row['col']` | `$entity->getCol()` | `PARAM_INT` |
| `float` | `private float $price = 0.0` | `(float) $row['col']` | `$entity->getCol()` | `PARAM_STR` |
| `bool` | `private bool $active = false` | `(bool) $row['col']` | `$entity->getCol()` | `PARAM_BOOL` |
| `string` | `private string $name = ''` | `(string) $row['col']` | `$entity->getCol()` | `PARAM_STR` |
| `\DateTimeImmutable` | `private ?\DateTimeImmutable $createdAt = null` | `new \DateTimeImmutable($row['col'])` | `$entity->getCol()?->format('Y-m-d H:i:s')` | `PARAM_STR` |

---

## Error Handling Strategy

The project uses a simple, consistent error handling approach:

| Layer | Exception Type | Handling |
|-------|----------------|----------|
| CLI argument parsing | None (returns exit code 1) | `writeError()` to STDERR |
| Config file missing | `RuntimeException` | Caught in `run()`, printed to STDERR |
| Invalid YAML format | `RuntimeException` | Caught in `run()`, printed to STDERR |
| Missing `dbname` | `RuntimeException` | Caught in `run()`, printed to STDERR |
| PDO connection failure | `PDOException` → `RuntimeException` | Re-thrown as `RuntimeException` with descriptive message |
| Table not found | `RuntimeException` | Caught in `run()`, printed to STDERR |

All `RuntimeException` instances thrown during `execute()` are caught by the `run()` method's try-catch block, which writes the error message to STDERR and returns exit code `1`.

The PDO connection is configured with `PDO::ERRMODE_EXCEPTION`, so any query errors (e.g. syntax errors, permission issues) will throw `PDOException` at runtime.

### Exit Codes

| Code | Meaning |
|------|---------|
| `0` | Success — files generated |
| `1` | Failure — error occurred or `--help` was not explicitly requested |

Note: `--help` returns `0` when explicitly requested, `1` when triggered by insufficient arguments.

---

## Security Considerations

### SQL Injection Prevention

1. **Table identifiers** are quoted with backticks via `TableInspector::quoteIdentifier()`, which also escapes embedded backticks.
2. **All generated queries** use `bindValue()` with explicit `PDO::PARAM_*` type constants, enforcing correct data types at the PDO level.
3. **Native prepared statements** are enforced via `PDO::ATTR_EMULATE_PREPARES => false`.

### Credential Handling

- Database credentials are stored in `config/pdoentitygenerator.yaml`, which should be added to `.gitignore`.
- The `.gitignore` already excludes `.env` and `.env.local` but does **not** exclude `config/pdoentitygenerator.yaml` by default — developers should add this exclusion for production use.
- Credentials are passed directly to the `PDO` constructor and are not logged or persisted elsewhere.

### Generated Code Security

- All generated repository methods use prepared statements.
- The primary key is set via `ReflectionProperty` to prevent external mutation through a public setter.
- No user input is directly interpolated into generated SQL — all values go through parameter binding.

---

## Performance Considerations

- **Single table per invocation**: The tool processes one table at a time. For bulk generation, call the CLI command in a loop.
- **DESCRIBE query**: A single `DESCRIBE` query is executed per table, which is lightweight on MySQL/MariaDB.
- **No caching**: The tool does not cache schema information. Each invocation re-reads the table schema from the database.
- **File I/O**: Generated files are written using `file_put_contents()` in a single operation per file. Directories are created with `mkdir()` and `0755` permissions.
- **String building**: Code is assembled via string concatenation and `sprintf()`. For the typical number of columns in a database table, this is negligible.

---

## Dependencies

### Runtime Dependencies

| Package | Version Constraint | Purpose |
|---------|-------------------|---------|
| `php` | `>=8.4` | Runtime language requirement |
| `ext-pdo` | `*` | Database connectivity |
| `composer-plugin-api` | `^2.0` | Composer Plugin interface |
| `symfony/yaml` | `^8.0` | YAML configuration parsing |

### Why These Versions?

- **PHP 8.4**: Required for typed class constants (`const string`, `const array`), constructor property promotion, `match` expressions, `readonly` properties, and `str_starts_with()`.
- **Symfony YAML 8.0**: Latest major version aligning with the PHP 8.4 requirement. Only `Yaml::parseFile()` is used — no dependency on the wider Symfony framework.
- **Composer Plugin API 2.0**: Required for `PluginInterface` and `EventSubscriberInterface` in Composer 2.x.

### Autoloading

PSR-4 autoloading is configured in `composer.json`:

```json
{
    "autoload": {
        "psr-4": {
            "kdevhub\\PdoEntityGenerator\\": "src/"
        }
    }
}
```

The CLI entry point (`bin/pdoentitygenerator`) resolves the autoloader from two possible locations:
1. `__DIR__ . '/../../../autoload.php'` — when installed as a dependency in a host project.
2. `__DIR__ . '/../vendor/autoload.php'` — when running from the package's own development directory.

---

## Extension Guide

### Adding a New Database Driver

The current implementation is MySQL/MariaDB-specific due to:

1. The `DESCRIBE` query in `TableInspector::inspect()`.
2. The DSN format in `GenerateEntityCommand::createPdoConnection()` and the generated `PdoFactory`.
3. The `TYPE_MAP` in `TableInspector`.

To add support for another database (e.g. PostgreSQL):

1. **Create a driver-specific inspector** that implements a common interface (e.g. `TableInspectorInterface`) with the `inspect()` method.
2. **Add the DSN format** for the new driver in `createPdoConnection()` or extract DSN building into a factory.
3. **Add a type map** for the new driver's SQL types.
4. **Update `ConfigLoader`** to accept and validate the new driver name.

### Adding Custom Generated Methods

To add new methods to the generated repository (e.g. `findBy`, `count`):

1. Create a new private builder method in `RepositoryGenerator` (e.g. `buildFindByMethod()`).
2. Call it from `generate()` and interpolate the result into the class template heredoc.
3. Follow the existing pattern: use prepared statements with named parameters.

### Adding New Column Type Support

1. Add the SQL type → PHP type mapping in `TableInspector::TYPE_MAP`.
2. If the new PHP type requires special handling:
   - **Hydration**: Add a case to the `match` expression in `RepositoryGenerator::buildHydrateBody()`.
   - **Insert binding**: Add a case to the `match` expression in `RepositoryGenerator::buildInsertMethod()`.
   - **Update binding**: Add a case to the `match` expression in `RepositoryGenerator::buildUpdateMethod()`.
   - **Default value**: Add a case to the `match` expression in `EntityGenerator::resolveDefaultValue()`.
3. Test with a table containing the new column type to verify generated output.
