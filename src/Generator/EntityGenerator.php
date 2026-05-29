<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Generator;

/**
 * Generates PHP Entity class source code from database column metadata
 *
 * Produces a plain PHP object (POPO) with typed properties, getters,
 * and setters. Converts snake_case column names to camelCase properties
 * and PascalCase class names.
 *
 * @package kdevhub\PdoEntityGenerator\Generator
 */
final class EntityGenerator
{
    /**
     * Generate the full Entity class source code
     *
     * @param string $className The PascalCase class name for the entity
     * @param string $namespace The namespace for the generated entity
     * @param list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $columns
     * @return string The complete PHP source code for the entity class
     */
    public function generate(string $className, string $namespace, array $columns): string
    {
        $properties = '';
        $gettersSetters = '';

        foreach ($columns as $column) {
            $propertyName = self::snakeToCamelCase($column['name']);
            $phpType = $column['phpType'];
            $nullable = $column['nullable'] || $column['isPrimary'];
            $typeHint = $nullable ? '?' . $phpType : $phpType;
            $defaultValue = $this->resolveDefaultValue($column);

            $properties .= sprintf("    private %s \$%s%s;\n", $typeHint, $propertyName, $defaultValue);
            $gettersSetters .= $this->buildGetter($propertyName, $typeHint);

            if (!$column['isPrimary']) {
                $gettersSetters .= $this->buildSetter($propertyName, $typeHint, $className);
            }
        }

        return $this->buildClassTemplate($className, $namespace, $properties, $gettersSetters);
    }

    /**
     * Convert a snake_case string to camelCase
     *
     * @param string $value The snake_case string to convert
     * @return string The camelCase result
     */
    public static function snakeToCamelCase(string $value): string
    {
        return lcfirst(str_replace('_', '', ucwords($value, '_')));
    }

    /**
     * Convert a snake_case string to PascalCase
     *
     * @param string $value The snake_case string to convert
     * @return string The PascalCase result
     */
    public static function snakeToPascalCase(string $value): string
    {
        return str_replace('_', '', ucwords($value, '_'));
    }

    /**
     * Resolve the default value expression for a column property
     *
     * @param array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool} $column
     * @return string The default value assignment string
     */
    private function resolveDefaultValue(array $column): string
    {
        if ($column['isPrimary']) {
            return ' = null';
        }

        if ($column['nullable']) {
            return ' = null';
        }

        return match ($column['phpType']) {
            'string' => " = ''",
            'int' => ' = 0',
            'float' => ' = 0.0',
            'bool' => ' = false',
            default => ' = null',
        };
    }

    /**
     * Build a getter method for the given property
     *
     * @param string $propertyName The camelCase property name
     * @param string $typeHint The PHP type hint including nullability
     * @return string The getter method source code
     */
    private function buildGetter(string $propertyName, string $typeHint): string
    {
        $methodName = 'get' . ucfirst($propertyName);

        return <<<PHP

            public function {$methodName}(): {$typeHint}
            {
                return \$this->{$propertyName};
            }

        PHP;
    }

    /**
     * Build a fluent setter method for the given property
     *
     * @param string $propertyName The camelCase property name
     * @param string $typeHint The PHP type hint including nullability
     * @param string $className The entity class name for the return type
     * @return string The setter method source code
     */
    private function buildSetter(string $propertyName, string $typeHint, string $className): string
    {
        $methodName = 'set' . ucfirst($propertyName);

        return <<<PHP

            public function {$methodName}({$typeHint} \${$propertyName}): self
            {
                \$this->{$propertyName} = \${$propertyName};

                return \$this;
            }

        PHP;
    }

    /**
     * Build the complete class template with properties and methods
     *
     * @param string $className The PascalCase class name
     * @param string $namespace The namespace for the entity
     * @param string $properties The generated property declarations
     * @param string $gettersSetters The generated getter and setter methods
     * @return string The complete PHP class source code
     */
    private function buildClassTemplate(
        string $className,
        string $namespace,
        string $properties,
        string $gettersSetters,
    ): string {
        $gettersSetters = $this->normaliseIndentation($gettersSetters);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        class {$className}
        {
        {$properties}
        {$gettersSetters}}

        PHP;
    }

    /**
     * Normalise heredoc indentation to standard 4-space indent
     *
     * @param string $code The raw heredoc output to normalise
     * @return string The re-indented source code
     */
    private function normaliseIndentation(string $code): string
    {
        $lines = explode("\n", $code);
        $normalised = [];

        foreach ($lines as $line) {
            $trimmed = $line;
            if (str_starts_with($trimmed, '            ')) {
                $trimmed = '    ' . ltrim($trimmed);
            }
            $normalised[] = $trimmed;
        }

        return implode("\n", $normalised);
    }
}
