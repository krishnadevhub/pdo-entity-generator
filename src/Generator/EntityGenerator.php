<?php

declare(strict_types=1);

namespace kdevhub\PdoEntityGenerator\Generator;

final class EntityGenerator
{
    /**
     * @param list<array{name: string, phpType: string, nullable: bool, isPrimary: bool, hasDefault: bool}> $columns
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

    private function normaliseIndentation(string $code): string
    {
        $lines = explode("\n", $code);
        $normalised = [];

        foreach ($lines as $line) {
            $trimmed = $line;
            // Remove extra leading spaces from heredoc indentation
            if (str_starts_with($trimmed, '            ')) {
                $trimmed = '    ' . ltrim($trimmed);
            }
            $normalised[] = $trimmed;
        }

        return implode("\n", $normalised);
    }

    public static function snakeToCamelCase(string $value): string
    {
        return lcfirst(str_replace('_', '', ucwords($value, '_')));
    }

    public static function snakeToPascalCase(string $value): string
    {
        return str_replace('_', '', ucwords($value, '_'));
    }
}
