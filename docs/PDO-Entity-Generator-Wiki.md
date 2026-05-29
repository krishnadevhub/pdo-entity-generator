# PDO Entity Generator — Wiki

> A standalone Composer CLI tool that generates PHP PDO Entity and Repository classes from MySQL/MariaDB database tables. No framework required.

---

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Generating Classes](#generating-classes)
  - [Generated Entity](#generated-entity)
  - [Generated Repository](#generated-repository)
  - [Using Generated Classes in Your Project](#using-generated-classes-in-your-project)
- [Architecture](#architecture)
  - [Project Structure](#project-structure)
  - [Execution Flow](#execution-flow)
  - [Component Details](#component-details)
- [SQL-to-PHP Type Mapping](#sql-to-php-type-mapping)
- [Naming Conventions](#naming-conventions)
- [Configuration Reference](#configuration-reference)
- [Coding Standards](#coding-standards)
- [Contributing](#contributing)
  - [Development Setup](#development-setup)
  - [Adding a New SQL Type Mapping](#adding-a-new-sql-type-mapping)
  - [Modifying Generated Code Templates](#modifying-generated-code-templates)
  - [Testing Changes Locally](#testing-changes-locally)
  - [Submitting Changes](#submitting-changes)
- [Troubleshooting](#troubleshooting)
- [Licence](#licence)

---

## Overview

The **PDO Entity Generator** (`kdevhubin/pdoentitygenerator`) is a CLI tool distributed as a Composer Plugin. It introspects MySQL/MariaDB table schemas and produces two PHP source files per table:

1. **Entity** — a plain PHP object (POPO) with typed properties, getters, and fluent setters.
2. **Repository** — a PDO-based data-access class with `find`, `findAll`, `insert`, `update`, and `delete` methods using prepared statements.

The tool intentionally avoids any ORM dependency (e.g. Doctrine). All database interaction uses raw PDO with parameterised queries, making it suitable for projects that require lightweight data-access layers without framework coupling.

### Key Features

- **Zero-framework dependency** — works in any PHP 8.4+ project with PDO enabled.
- **Composer Plugin** — automatically creates a default configuration file on first install.
- **Strict typing** — all generated code uses `declare(strict_types=1)` and PHP 8.4 typed properties.
- **Fluent setters** — setter methods return `self` for method chaining.
- **Prepared statements** — all generated SQL queries use `bindValue()` with explicit `PDO::PARAM_*` type constants to enforce data types and prevent SQL injection.
- **Automatic naming conversion** — database `snake_case` names are converted to PHP `camelCase` (properties) and `PascalCase` (classes).
- **Comprehensive type mapping** — MySQL/MariaDB column types are mapped to appropriate PHP types, including `\DateTimeImmutable` for date/datetime columns.

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.4 or higher |
| PDO extension | `ext-pdo` |
| Database | MySQL / MariaDB |
| Composer | 2.x |

---

## Installation

Install via Composer:

```bash
composer require kdevhubin/pdoentitygenerator
```

Because this package is a **Composer Plugin**, Composer will prompt you to trust it:

```
Do you trust "kdevhubin/pdoentitygenerator" to execute code and wish to enable it now?
(writes "allow-plugins" to composer.json)
```

Type `y` to allow. Alternatively, pre-authorise the plugin in your `composer.json`:

```json
{
    "config": {
        "allow-plugins": {
            "kdevhubin/pdoentitygenerator": true
        }
    }
}
```

Once allowed, the plugin automatically creates `config/pdoentitygenerator.yaml` with default settings. No manual setup step is required.

---

## Configuration

Open `config/pdoentitygenerator.yaml` in your project root and update it with your database credentials:

```yaml
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
```

> **Important**: The `database.dbname` field is required. The generator will not run without it.

See [Configuration Reference](#configuration-reference) for all available options and their defaults.

---

## Usage

### Generating Classes

Run the CLI command with the `table` subcommand followed by the database table name:

```bash
vendor/bin/pdoentitygenerator table <table_name>
```

#### Example

```bash
vendor/bin/pdoentitygenerator table test_my_table
```

This generates:

- `src/Entity/TestMyTable.php` — Entity class with typed properties, getters, and setters.
- `src/Repository/TestMyTableRepository.php` — Repository class with full CRUD operations.

The tool will:

1. Read `config/pdoentitygenerator.yaml` for database connection details.
2. Connect to the database via PDO.
3. Inspect the table schema using `DESCRIBE` (column names, types, nullability, primary key).
4. Generate an Entity class and a Repository class in the configured output directories.

### Generated Entity

For a table `test_my_table` with columns `id`, `name`, `email`, and `created_at`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

class TestMyTable
{
    private ?int $id = null;
    private string $name = '';
    private string $email = '';
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    // ... additional getters/setters for each column
}
```

Key characteristics:

- **Primary key** (`id`) is nullable (`?int`) with a default of `null` and has no setter — it is populated via reflection after insert.
- **Non-nullable columns** receive type-appropriate defaults (`''` for strings, `0` for ints, `0.0` for floats, `false` for booleans).
- **Nullable columns** are typed with `?` prefix and default to `null`.
- **Date/datetime columns** use `\DateTimeImmutable`.

### Generated Repository

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TestMyTable;

class TestMyTableRepository
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function find(int $id): ?TestMyTable { ... }
    public function findAll(): array { ... }
    public function insert(TestMyTable $entity): TestMyTable { ... }
    public function update(TestMyTable $entity): TestMyTable { ... }
    public function delete(int $id): bool { ... }
}
```

#### CRUD Methods

| Method | Description | Return Type |
|--------|-------------|-------------|
| `find($id)` | Finds a single entity by primary key | `?Entity` (null if not found) |
| `findAll()` | Returns all entities from the table | `Entity[]` |
| `insert($entity)` | Inserts a new record; sets the auto-generated ID on the entity via reflection | `Entity` |
| `update($entity)` | Updates an existing record by primary key | `Entity` |
| `delete($id)` | Deletes a record by primary key | `bool` |

Key characteristics:

- All queries use **prepared statements** with `bindValue()` and explicit `PDO::PARAM_*` type constants.
- Each parameter is bound with the correct PDO type: `PDO::PARAM_INT` for integers, `PDO::PARAM_BOOL` for booleans, `PDO::PARAM_STR` for strings/floats/dates.
- Nullable columns use a local variable with a ternary to switch between the typed constant and `PDO::PARAM_NULL`.
- The `insert` method sets the newly generated primary key on the entity using `ReflectionProperty`.
- The `hydrateEntity` method maps database rows to entity objects, handling type casting and `DateTimeImmutable` construction.
- `DateTimeImmutable` values are formatted as `'Y-m-d H:i:s'` for insert/update operations.

### Using Generated Classes in Your Project

```php
// Create a PDO connection
$pdo = new \PDO('mysql:host=127.0.0.1;dbname=my_database', 'root', 'secret');

// Instantiate the repository
$repository = new \App\Repository\TestMyTableRepository($pdo);

// Find by ID
$entity = $repository->find(1);

// Find all
$entities = $repository->findAll();

// Insert a new record
$entity = new \App\Entity\TestMyTable();
$entity->setName('John Doe');
$entity->setEmail('john@example.com');
$entity = $repository->insert($entity);
// $entity->getId() now contains the auto-generated ID

// Update an existing record
$entity->setName('Jane Doe');
$entity = $repository->update($entity);

// Delete by ID
$deleted = $repository->delete($entity->getId());
```

---

## Architecture

### Project Structure

```
kdevhubin/pdoentitygenerator
├── bin/
│   └── pdoentitygenerator              # CLI entry point (executable PHP script)
├── src/
│   ├── Command/
│   │   └── GenerateEntityCommand.php   # Parses CLI args, orchestrates generation
│   ├── Composer/
│   │   └── PostInstallHandler.php      # Composer Plugin — auto-creates config on install
│   ├── Config/
│   │   └── ConfigLoader.php            # Loads and validates YAML configuration
│   ├── Database/
│   │   └── TableInspector.php          # Reads table schema via DESCRIBE query
│   └── Generator/
│       ├── EntityGenerator.php         # Generates Entity class source code
│       └── RepositoryGenerator.php     # Generates Repository class source code
├── composer.json
├── .gitignore
├── LICENSE
└── README.md
```

### Execution Flow

```
┌────────────────────────────────────────────────────────────────────────┐
│  CLI Invocation: vendor/bin/pdoentitygenerator table <table_name>     │
└──────────────────────────────┬─────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│  bin/pdoentitygenerator                                              │
│  Resolves Composer autoloader → delegates to GenerateEntityCommand   │
└──────────────────────────────┬───────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│  GenerateEntityCommand.run($argv)                                    │
│  1. Parses CLI arguments (command + table name)                      │
│  2. Resolves project root (walks up to find composer.json)           │
│  3. Loads config via ConfigLoader                                    │
│  4. Creates PDO connection                                           │
│  5. Inspects table via TableInspector                                │
│  6. Generates Entity via EntityGenerator                             │
│  7. Generates Repository via RepositoryGenerator                     │
│  8. Writes both files to configured output directories               │
└──────────────────────────────┬───────────────────────────────────────┘
                               │
                 ┌─────────────┼─────────────┐
                 ▼             ▼             ▼
          ┌───────────┐ ┌───────────┐ ┌───────────────┐
          │ Config    │ │ Table     │ │ Entity &      │
          │ Loader    │ │ Inspector │ │ Repository    │
          │           │ │           │ │ Generators    │
          └───────────┘ └───────────┘ └───────────────┘
```

### Component Details

#### 1. CLI Entry Point (`bin/pdoentitygenerator`)

- Resolves the Composer autoloader from either the host project's `vendor/` directory or the local development `vendor/` directory.
- Instantiates `GenerateEntityCommand` and delegates execution.
- Returns an appropriate exit code (0 for success, 1 for failure).

#### 2. GenerateEntityCommand (`src/Command/GenerateEntityCommand.php`)

- Parses command-line arguments: expects `table <table_name>`.
- Supports `--help` flag for usage information.
- Resolves the project root by walking up from the current working directory until a `composer.json` is found.
- Orchestrates the full generation pipeline: config → connection → inspection → generation → file writing.
- Provides console output at each step for user feedback.

#### 3. PostInstallHandler (`src/Composer/PostInstallHandler.php`)

- Implements `PluginInterface` and `EventSubscriberInterface` from Composer.
- Subscribes to `POST_INSTALL_CMD` and `POST_UPDATE_CMD` events.
- On first install, creates `config/pdoentitygenerator.yaml` with default settings if it does not already exist.
- Skips creation if the config file is already present.

#### 4. ConfigLoader (`src/Config/ConfigLoader.php`)

- Reads `config/pdoentitygenerator.yaml` using Symfony YAML component.
- Merges parsed values with sensible defaults for all fields.
- Validates that the required `database.dbname` field is present.
- Casts the `database.port` value to integer.

#### 5. TableInspector (`src/Database/TableInspector.php`)

- Executes `DESCRIBE <table>` via PDO to retrieve column metadata.
- Maps each column to its PHP type using a comprehensive `TYPE_MAP` constant.
- Returns structured column data: name, PHP type, nullability, primary key status, and whether a default exists.
- Handles SQL type normalisation (strips size/precision specifiers, `unsigned`, `zerofill`).
- Quotes table identifiers with backticks to prevent SQL injection.

#### 6. EntityGenerator (`src/Generator/EntityGenerator.php`)

- Generates a POPO class with `declare(strict_types=1)`.
- Converts `snake_case` column names to `camelCase` properties and `PascalCase` class names.
- Creates typed private properties with appropriate defaults.
- Generates getter methods for all columns.
- Generates fluent setter methods for non-primary-key columns (setters return `self`).
- Primary key properties are nullable and have no setter.
- Normalises heredoc indentation to standard 4-space indent.

#### 7. RepositoryGenerator (`src/Generator/RepositoryGenerator.php`)

- Generates a repository class with constructor-promoted `readonly PDO` dependency.
- Creates five CRUD methods: `find`, `findAll`, `insert`, `update`, `delete`.
- Generates a private `hydrateEntity` method to map database rows to entity objects.
- All parameter bindings use `bindValue()` with explicit `PDO::PARAM_*` type constants:
  - `int` → `PDO::PARAM_INT`
  - `bool` → `PDO::PARAM_BOOL`
  - `string`, `float`, `\DateTimeImmutable` → `PDO::PARAM_STR`
  - Nullable columns → ternary between typed constant and `PDO::PARAM_NULL`
- Handles special type serialisation:
  - `DateTimeImmutable` → formatted as `'Y-m-d H:i:s'` for writes; constructed from string for reads.
  - Primary key → set via `ReflectionProperty` (bypassing the missing setter).

---

## SQL-to-PHP Type Mapping

The `TableInspector` maps SQL column types to PHP types as follows:

| SQL Type | PHP Type |
|----------|----------|
| `int`, `integer`, `bigint`, `smallint`, `mediumint`, `tinyint` | `int` |
| `tinyint(1)`, `boolean`, `bool` | `bool` |
| `float`, `double`, `decimal`, `numeric`, `real` | `float` |
| `varchar`, `char`, `text`, `tinytext`, `mediumtext`, `longtext` | `string` |
| `enum`, `set`, `json` | `string` |
| `blob`, `binary`, `varbinary` | `string` |
| `date`, `datetime`, `timestamp` | `\DateTimeImmutable` |
| `time` | `string` |
| `year` | `int` |

> **Note**: `tinyint(1)` is treated as `bool`, while other `tinyint` sizes map to `int`. The type resolver checks for exact matches first (e.g. `tinyint(1)`) before stripping size specifiers.

---

## Naming Conventions

The generator automatically converts between database snake_case and PHP naming conventions:

| Database (snake_case) | PHP Equivalent | Used For |
|----------------------|----------------|----------|
| `test_my_table` | `TestMyTable` | Class name (PascalCase) |
| `created_at` | `createdAt` | Property name (camelCase) |
| `first_name` | `firstName` | Property name (camelCase) |
| `id` | `id` | Property name (camelCase) |
| `created_at` | `getCreatedAt()` / `setCreatedAt()` | Getter/Setter methods |

---

## Configuration Reference

All configuration is stored in `config/pdoentitygenerator.yaml`.

### Database Settings

| Key | Description | Default | Required |
|-----|-------------|---------|----------|
| `database.host` | Database host address | `127.0.0.1` | No |
| `database.port` | Database port number | `3306` | No |
| `database.dbname` | Database name | — | **Yes** |
| `database.username` | Database username | `root` | No |
| `database.password` | Database password | `''` (empty) | No |
| `database.driver` | PDO driver name | `mysql` | No |

### Output Settings

| Key | Description | Default |
|-----|-------------|---------|
| `output.entity_namespace` | PHP namespace for generated entity classes | `App\Entity` |
| `output.repository_namespace` | PHP namespace for generated repository classes | `App\Repository` |
| `output.entity_directory` | File system directory for entity files (relative to project root) | `src/Entity` |
| `output.repository_directory` | File system directory for repository files (relative to project root) | `src/Repository` |

---

## Coding Standards

This project follows strict PHP coding standards. Key rules:

| Area | Standard |
|------|----------|
| **PHP Version** | 8.4+ |
| **Strict Types** | Every file declares `declare(strict_types=1)` |
| **Style** | PSR-12 Extended Coding Style |
| **Line Length** | Maximum 120 characters |
| **ORM** | None — raw PDO only |
| **Classes** | Marked `final` unless designed for extension |
| **Visibility** | Declared on all properties, methods, and constants |
| **PHPDoc** | Required on all classes (`@package`) and methods (`@param`, `@return`, `@throws`) |
| **Imports** | Alphabetised `use` statements; no FQNs in code |
| **Strings** | Single quotes unless variable interpolation needed |
| **Arrays** | Short syntax (`[]`) with trailing commas in multiline |
| **Constants** | Typed (`const string`, `const array`) |
| **Constructor** | Use property promotion where appropriate |
| **Properties** | Use `readonly` where value should not change after construction |
| **Branching** | Prefer `match` over `switch` |
| **Nesting** | Maximum 3–4 levels; use guard clauses for early returns |
| **Dependencies** | Inject via constructor; avoid `new` inside classes |
| **SQL** | Parameterised prepared statements only |

### Class Member Ordering

1. Constants
2. Properties
3. Constructor
4. Public methods
5. Protected methods
6. Private methods

---

## Contributing

### Development Setup

1. **Clone the repository**:

   ```bash
   git clone <repository-url>
   cd pdoentitygenerator
   ```

2. **Install dependencies**:

   ```bash
   composer install
   ```

3. **Set up a test database** (MySQL/MariaDB):

   ```sql
   CREATE DATABASE pdo_generator_test;
   USE pdo_generator_test;

   CREATE TABLE test_my_table (
       id INT AUTO_INCREMENT PRIMARY KEY,
       name VARCHAR(255) NOT NULL,
       email VARCHAR(255),
       is_active TINYINT(1) DEFAULT 1,
       created_at DATETIME DEFAULT CURRENT_TIMESTAMP
   );
   ```

4. **Configure the tool**:

   Create `config/pdoentitygenerator.yaml` with your test database credentials:

   ```yaml
   database:
       host: 127.0.0.1
       port: 3306
       dbname: pdo_generator_test
       username: root
       password: your_password
       driver: mysql

   output:
       entity_namespace: App\Entity
       repository_namespace: App\Repository
       entity_directory: src/Entity
       repository_directory: src/Repository
   ```

5. **Run the generator** to verify everything works:

   ```bash
   php bin/pdoentitygenerator table test_my_table
   ```

### Adding a New SQL Type Mapping

1. Open `src/Database/TableInspector.php`.
2. Add the mapping to the `TYPE_MAP` constant:

   ```php
   private const array TYPE_MAP = [
       // ... existing mappings
       'new_sql_type' => 'php_type',
   ];
   ```

3. If the type requires special handling in generated code (e.g. custom hydration or parameter binding), update the corresponding `match` expressions in:
   - `src/Generator/RepositoryGenerator.php` → `buildHydrateBody()` for reading from the database.
   - `src/Generator/RepositoryGenerator.php` → `buildInsertMethod()` and `buildUpdateMethod()` for writing to the database.

### Modifying Generated Code Templates

The generated Entity and Repository code is built using PHP heredoc templates within the generator classes:

- **Entity template**: `src/Generator/EntityGenerator.php` → `buildClassTemplate()`, `buildGetter()`, `buildSetter()`
- **Repository template**: `src/Generator/RepositoryGenerator.php` → `generate()`, `buildInsertMethod()`, `buildUpdateMethod()`, `buildHydrateBody()`

When modifying templates:

- Pay close attention to heredoc indentation — the closing marker's position determines the base indent level.
- Variable interpolation within heredocs uses `{$variable}` syntax.
- Escape `$` with `\$` when the dollar sign should appear in generated output.
- Test with tables containing various column types (int, varchar, datetime, boolean) to verify output.

### Testing Changes Locally

After making changes, verify the generator produces valid output:

```bash
# 1. Run the generator against a test table
php bin/pdoentitygenerator table test_my_table

# 2. Check the generated files for syntax errors
php -l src/Entity/TestMyTable.php
php -l src/Repository/TestMyTableRepository.php

# 3. Verify the generated code follows expected patterns
#    - Entity has correct namespace, properties, getters, setters
#    - Repository has find, findAll, insert, update, delete methods
#    - All methods use prepared statements
#    - snake_case columns are correctly mapped to camelCase properties

# 4. Clean up generated test files
rm -f src/Entity/TestMyTable.php src/Repository/TestMyTableRepository.php
```

### Submitting Changes

1. Create a feature branch from `test-generator`:

   ```bash
   git checkout -b feature/your-feature-name
   ```

2. Make your changes and verify generated output.
3. Ensure all PHPDoc comments are complete with `@param`, `@return`, and `@throws` tags.
4. Verify imports are alphabetised and no FQNs are used in code.
5. Commit with a descriptive message.
6. Push and create a pull request targeting `test-generator`.

---

## Troubleshooting

### "Configuration file not found" error

The `config/pdoentitygenerator.yaml` file was not created during installation. This usually happens when the Composer Plugin was not allowed. Either:

1. Re-run `composer install` and type `y` when prompted to trust the plugin, or
2. Manually create the file:

   ```bash
   mkdir -p config
   ```

   Then create `config/pdoentitygenerator.yaml` with the contents shown in the [Configuration](#configuration) section.

### "Database name must be specified" error

The `database.dbname` field in `config/pdoentitygenerator.yaml` is empty or missing. Update it with your database name.

### "Database connection failed" error

Verify your database credentials in `config/pdoentitygenerator.yaml`. Ensure:

- The database server is running.
- The host, port, username, and password are correct.
- The specified database exists.
- The PDO driver matches your database (e.g. `mysql` for MySQL/MariaDB).

### "Table not found or has no columns" error

The specified table does not exist in the configured database. Check:

- The table name is spelled correctly (case-sensitive).
- You are connected to the correct database.

### Generated files have incorrect namespace

Update the `output.entity_namespace` and `output.repository_namespace` values in `config/pdoentitygenerator.yaml` to match your project's PSR-4 autoload configuration.

---

## Licence

This project is licensed under the **MIT Licence**. See the [LICENSE](../LICENSE) file for details.

Copyright &copy; 2026 Krishnamoorthy Velautham
