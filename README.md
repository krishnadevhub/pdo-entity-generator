# PDO Entity Generator

A CLI tool to generate PHP PDO Entity and Repository classes from database tables. Works in any PHP project — no framework required.

## Table of Contents

- [Requirements](#requirements)
- [Getting Started](#getting-started)
  - [Installation](#installation)
  - [Configuration](#configuration)
  - [Quick Start](#quick-start)
- [Usage](#usage)
  - [Generating Entities](#generating-entities)
  - [Generated Entity Example](#generated-entity-example)
  - [Generated Repository Example](#generated-repository-example)
  - [Generated PdoFactory](#generated-pdofactory)
  - [Using Generated Classes](#using-generated-classes)
- [Reference](#reference)
  - [Configuration Options](#configuration-options)
  - [Naming Conventions](#naming-conventions)
  - [Type Mapping](#type-mapping)
- [Architecture](#architecture)
- [Contributing](#contributing)
  - [Development Setup](#development-setup)
  - [Project Structure](#project-structure)
  - [Coding Standards](#coding-standards)
  - [Adding a New SQL Type Mapping](#adding-a-new-sql-type-mapping)
  - [Modifying Generated Code Templates](#modifying-generated-code-templates)
  - [Testing Changes Locally](#testing-changes-locally)
  - [Submitting Changes](#submitting-changes)
- [Troubleshooting](#troubleshooting)
- [Licence](#licence)

---

## Requirements

- PHP 8.4 or higher
- PDO extension (`ext-pdo`)
- MySQL / MariaDB database
- Composer 2.x

## Getting Started

### Installation

Install the package via Composer:

```bash
composer require kdevhubin/pdoentitygenerator
```

This package is a **Composer Plugin**. On first install, Composer will prompt you to allow the plugin:

```
Do you trust "kdevhubin/pdoentitygenerator" to execute code and wish to enable it now? (writes "allow-plugins" to composer.json)
```

Type `y` to allow it. You can also pre-authorise the plugin by adding it to your project's `composer.json`:

```json
{
    "config": {
        "allow-plugins": {
            "kdevhubin/pdoentitygenerator": true
        }
    }
}
```

Once allowed, the plugin automatically creates `config/pdoentitygenerator.yaml` with default settings. There is no manual setup step required.

### Configuration

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
    factory_namespace: App\Factory
    entity_directory: src/Entity
    repository_directory: src/Repository
    factory_directory: src/Factory
```

> **Important**: The `database.dbname` field is required. The generator will not run without it.

### Quick Start

```bash
# 1. Install the package (config file is auto-created via Composer Plugin)
composer require kdevhubin/pdoentitygenerator
# When prompted, type 'y' to allow the plugin

# 2. Update config/pdoentitygenerator.yaml with your database credentials
nano config/pdoentitygenerator.yaml

# 3. Generate entity and repository for a table
vendor/bin/pdoentitygenerator table users

# Output:
#   Created: src/Entity/Users.php
#   Created: src/Repository/UsersRepository.php
#   Created: src/Factory/PdoFactory.php
```

---

## Usage

### Generating Entities

Run the CLI command with the `table` subcommand followed by the database table name:

```bash
vendor/bin/pdoentitygenerator table <table_name>
```

The tool will:

1. Read `config/pdoentitygenerator.yaml` for database connection details
2. Connect to the database via PDO
3. Inspect the table schema (column names, types, nullability)
4. Generate an Entity class, a Repository class, and a PdoFactory class in the configured output directories

#### Example

```bash
vendor/bin/pdoentitygenerator table test_my_table
```

This generates:

- `src/Entity/TestMyTable.php` — POPO with typed properties, getters, and setters
- `src/Repository/TestMyTableRepository.php` — PDO-based CRUD repository
- `src/Factory/PdoFactory.php` — framework-agnostic factory for creating configured PDO connections (skipped if already exists)

### Generated Entity Example

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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
```

### Generated Repository Example

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
| `insert($entity)` | Inserts a new record and sets the generated ID on the entity | `Entity` |
| `update($entity)` | Updates an existing record by primary key | `Entity` |
| `delete($id)` | Deletes a record by primary key | `bool` |

All queries use **prepared statements** with `bindValue()` and explicit `PDO::PARAM_*` type constants to enforce data types and prevent SQL injection.

### Generated PdoFactory

The generator also creates a `PdoFactory` class that reads your database credentials from `config/pdoentitygenerator.yaml` and returns a configured `PDO` instance. Uses a singleton pattern — subsequent calls to `create()` return the same connection, avoiding redundant connections. Call `reset()` to force a new connection. This is framework-agnostic and works in any PHP project.

#### Plain PHP Usage

```php
// Use the factory to create a PDO connection from config
$pdo = \App\Factory\PdoFactory::create();

// Instantiate the repository
$repository = new \App\Repository\TestMyTableRepository($pdo);
```

#### Symfony Usage (services.yaml)

Register the factory as a service to enable autowiring for all repositories:

```yaml
services:
    PDO:
        factory: ['App\Factory\PdoFactory', 'create']
```

### Using Generated Classes

```php
// Use the factory (reads credentials from config/pdoentitygenerator.yaml)
$pdo = \App\Factory\PdoFactory::create();

// Or create a PDO connection manually
// $pdo = new \PDO('mysql:host=127.0.0.1;dbname=my_database', 'root', 'secret');

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

## Reference

### Configuration Options

| Key | Description | Default |
|-----|-------------|---------|
| `database.host` | Database host | `127.0.0.1` |
| `database.port` | Database port | `3306` |
| `database.dbname` | Default database name (**required**) | — |
| `database.username` | Database username | `root` |
| `database.password` | Database password | — |
| `database.driver` | PDO driver | `mysql` |
| `output.entity_namespace` | Namespace for generated entities | `App\Entity` |
| `output.repository_namespace` | Namespace for generated repositories | `App\Repository` |
| `output.factory_namespace` | Namespace for generated factory class | `App\Factory` |
| `output.entity_directory` | Output directory for entity files | `src/Entity` |
| `output.repository_directory` | Output directory for repository files | `src/Repository` |
| `output.factory_directory` | Output directory for factory file | `src/Factory` |

### Naming Conventions

The generator automatically converts between database snake_case and PHP naming conventions:

| Database (snake_case) | PHP (camelCase / PascalCase) | Used For |
|----------------------|------------------------------|----------|
| `test_my_table` | `TestMyTable` | Class name |
| `created_at` | `createdAt` | Property name |
| `first_name` | `firstName` | Property name |
| `id` | `id` | Property name |

### Type Mapping

| SQL Type | PHP Type |
|----------|----------|
| `int`, `integer`, `bigint`, `smallint`, `mediumint`, `tinyint` | `int` |
| `varchar`, `char`, `text`, `tinytext`, `mediumtext`, `longtext`, `enum`, `set`, `json` | `string` |
| `decimal`, `float`, `double`, `numeric`, `real` | `float` |
| `datetime`, `timestamp`, `date` | `\DateTimeImmutable` |
| `tinyint(1)`, `boolean`, `bool` | `bool` |
| `time` | `string` |
| `year` | `int` |

---

## Architecture

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
│       ├── PdoFactoryGenerator.php     # Generates PdoFactory class source code
│       └── RepositoryGenerator.php     # Generates Repository class source code
├── composer.json
├── .gitignore
└── README.md
```

### How It Works

1. **CLI Entry Point** (`bin/pdoentitygenerator`) — Resolves the Composer autoloader and delegates to `GenerateEntityCommand`
2. **Command** (`GenerateEntityCommand`) — Parses `argv`, loads config, connects to the database, and orchestrates the generation pipeline
3. **Config Loader** (`ConfigLoader`) — Reads `config/pdoentitygenerator.yaml`, merges with defaults, and validates required fields
4. **Table Inspector** (`TableInspector`) — Executes `DESCRIBE <table>` to retrieve column metadata (name, type, nullability, primary key)
5. **Entity Generator** (`EntityGenerator`) — Produces a POPO class with typed properties, getters, setters, and snake_case→camelCase conversion
6. **Repository Generator** (`RepositoryGenerator`) — Produces a repository class with `find`, `findAll`, `insert`, `update`, `delete` methods using `bindValue()` with explicit `PDO::PARAM_*` type constants
7. **PdoFactory Generator** (`PdoFactoryGenerator`) — Produces a framework-agnostic factory class that reads database credentials from `config/pdoentitygenerator.yaml` and returns a configured `PDO` instance
8. **Post-Install Handler** (`PostInstallHandler`) — Composer Plugin (implements `PluginInterface` and `EventSubscriberInterface`) that subscribes to `POST_INSTALL_CMD` and `POST_UPDATE_CMD` events to auto-create `config/pdoentitygenerator.yaml` in the host project when the package is installed

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
       factory_namespace: App\Factory
       entity_directory: src/Entity
       repository_directory: src/Repository
       factory_directory: src/Factory
   ```

5. **Run the generator** to verify everything works:

   ```bash
   php bin/pdoentitygenerator table test_my_table
   ```

### Project Structure

| Directory / File | Purpose |
|-----------------|---------|
| `bin/pdoentitygenerator` | CLI entry point — edit to change autoloader resolution logic |
| `src/Command/` | CLI command handling and argument parsing |
| `src/Composer/` | Composer Plugin — subscribes to install/update events to auto-create config |
| `src/Config/` | Configuration loading and validation |
| `src/Database/` | Database introspection (schema reading) |
| `src/Generator/` | Code generation — Entity, Repository, and PdoFactory templates |

### Coding Standards

This project follows the [PHP Coding Standards and Best Practices](docs/PHP+Coding+Standards+and+Best+Practices.md) document. Key rules are summarised below.

#### General

- **PHP Version**: All code must target PHP 8.4+
- **Strict Types**: Every PHP file must declare `declare(strict_types=1);`
- **PSR-12**: Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) Extended Coding Style
- **Line Length**: Maximum 120 characters per line
- **No Doctrine**: This project intentionally avoids Doctrine ORM — all database interaction uses raw PDO

#### PHPDoc

- All classes must have a class-level PHPDoc with `@package` tag
- All methods must have PHPDoc with `@param`, `@return`, and `@throws` tags
- Document all exception types the method can throw, including those from called methods

```php
/**
 * Brief description of what the method does
 *
 * @param string $paramName Description of the parameter
 * @return Type Description of the return value
 * @throws RuntimeException When something goes wrong
 */
public function methodName(string $paramName): Type
{
    // Implementation
}
```

#### Imports and Namespaces

- All classes use the `kdevhub\PdoEntityGenerator\` namespace
- Replace Fully Qualified Names (FQN) with `use` import statements (e.g. `use RuntimeException;` instead of `\RuntimeException`)
- Alphabetise import statements
- Remove unused imports

#### Class Structure

- Mark classes as `final` unless they are designed for extension
- Declare visibility on all properties, methods, and constants (`public`, `protected`, `private`)
- Order class members: constants → properties → constructor → public methods → protected methods → private methods

#### PHP 8.4 Features

- **Constructor Promotion**: Use constructor property promotion where appropriate
- **Readonly Properties**: Use `readonly` for properties that should not change after construction
- **Match Expressions**: Prefer `match` over `switch` for value-based branching
- **Typed Constants**: Use typed constants (`const string`, `const array`)

#### Strings and Arrays

- Use **single quotes** for strings without variable interpolation
- Use `{$variable}` syntax for complex interpolation in double-quoted strings
- Use short array syntax (`[]`) with trailing commas in multiline arrays

#### Best Practices

- Use early returns (guard clauses) to reduce nesting — maximum 3–4 levels of indentation
- Use named constants instead of magic numbers or strings
- Use dependency injection — avoid `new` inside classes for dependencies
- All database queries must use parameterised prepared statements

### Adding a New SQL Type Mapping

To add support for a new SQL data type:

1. Open `src/Database/TableInspector.php`
2. Add the mapping to the `TYPE_MAP` constant:

   ```php
   private const array TYPE_MAP = [
       // ... existing mappings
       'new_sql_type' => 'php_type',
   ];
   ```

3. If the type requires special handling in generated code (e.g. custom hydration or parameter binding), update the corresponding `match` expressions in:
   - `src/Generator/RepositoryGenerator.php` → `buildHydrateBody()` for reading from DB
   - `src/Generator/RepositoryGenerator.php` → `buildBindValueLine()` for writing to DB
   - `src/Generator/RepositoryGenerator.php` → `resolvePdoParamType()` if a new PDO parameter type is needed

### Modifying Generated Code Templates

The generated Entity and Repository code is built using PHP heredoc templates within the generator classes:

- **Entity template**: `src/Generator/EntityGenerator.php` → `buildClassTemplate()`, `buildGetter()`, `buildSetter()`
- **Repository template**: `src/Generator/RepositoryGenerator.php` → `generate()`, `buildInsertMethod()`, `buildUpdateMethod()`, `buildHydrateBody()`
- **PdoFactory template**: `src/Generator/PdoFactoryGenerator.php` → `generate()`

When modifying templates:
- Pay close attention to heredoc indentation — the closing marker's position determines the base indent level
- Variable interpolation within heredocs uses `{$variable}` syntax
- Escape `$` with `\$` when the dollar sign should appear in generated output
- Test with tables containing various column types (int, varchar, datetime, boolean) to verify output

### Testing Changes Locally

After making changes, verify the generator produces valid output:

```bash
# 1. Run the generator against a test table
php bin/pdoentitygenerator table test_my_table

# 2. Check the generated files for syntax errors
php -l src/Entity/TestMyTable.php
php -l src/Repository/TestMyTableRepository.php
php -l src/Factory/PdoFactory.php

# 3. Verify the generated code follows expected patterns
#    - Entity has correct namespace, properties, getters, setters
#    - Repository has find, findAll, insert, update, delete methods
#    - PdoFactory reads config and returns configured PDO instance
#    - All methods use prepared statements
#    - snake_case columns are correctly mapped to camelCase properties

# 4. Clean up generated test files
rm -f src/Entity/TestMyTable.php src/Repository/TestMyTableRepository.php src/Factory/PdoFactory.php
```

### Submitting Changes

1. Create a feature branch from `test-generator`:

   ```bash
   git checkout -b feature/your-feature-name
   ```

2. Make your changes and verify generated output
3. Ensure all PHPDoc comments are complete with `@param`, `@return`, and `@throws` tags
4. Verify imports are alphabetised and no FQNs are used in code
5. Commit with a descriptive message
6. Push and create a pull request targeting `test-generator`

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
- The database server is running
- The host, port, username, and password are correct
- The specified database exists
- The PDO driver matches your database (e.g. `mysql` for MySQL/MariaDB)

### "Table not found or has no columns" error

The specified table does not exist in the configured database. Check:
- The table name is spelled correctly (case-sensitive)
- You are connected to the correct database

### Generated files have incorrect namespace

Update the `output.entity_namespace`, `output.repository_namespace`, and `output.factory_namespace` values in `config/pdoentitygenerator.yaml` to match your project's PSR-4 autoload configuration.

---

## Licence

This project is licensed under the MIT Licence — see the [LICENSE](LICENSE) file for details.
