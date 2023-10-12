<?php

declare(strict_types=1);

namespace Gordinskiy\SymfonyJsonSchema;

use Gordinskiy\JsonSchema\NodeType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Ulid;
use Symfony\Component\Validator\Constraints\Url;

final readonly class SchemaTypeGuesser
{
    /**
     * @param Constraint ...$constraints
     * @return ?NodeType
     *
     * @throws \Exception
     */
    public static function getTypeOfConstraints(Constraint ...$constraints): ?NodeType
    {
        $result = [];

        foreach ($constraints as $constraint) {
            if ($type = self::getTypeOfConstraint($constraint)) {
                $result[$type->value] = $type;
            }
        }

        if (count($result) > 1) {
            throw new \Exception('Type conflict');
        }

        return reset($result) ?: null;
    }

    public static function getTypeOfConstraint(Constraint $constraint): ?NodeType
    {
        if ($constraint instanceof Type) {
            return self::typeByConstraint($constraint);
        }

        return self::getTypeByConstraintClass($constraint::class);
    }

    /**
     * @param class-string $constraintClass
     * @return NodeType|null
     */
    private static function getTypeByConstraintClass(string $constraintClass): ?NodeType
    {
        return match ($constraintClass) {
            Regex::class,
            Email::class,
            Url::class,
            Ulid::class,
            Length::class => NodeType::String,
            Collection::class => NodeType::Object,
            All::class => NodeType::Array,
            Constraint::class => null,
            default => self::getTypeByConstraintParentClass($constraintClass)
        };
    }

    /**
     * @param class-string $constraintClass
     * @return NodeType|null
     */
    private static function getTypeByConstraintParentClass(string $constraintClass): ?NodeType
    {
        if ($parentClass = get_parent_class($constraintClass)) {
            return self::getTypeByConstraintClass($parentClass);
        }

        return null;
    }

    private static function typeByConstraint(Type $constraint): ?NodeType
    {
        return match ($constraint->type) {
            'bool',
            'boolean' => NodeType::Boolean,

            // Risky. According JSONSchema number 5.0 is valid integer, but not for PHP or Symfony
            'int',
            'integer',
            'long' => NodeType::Integer,

            'float',
            'double',
            'real' => NodeType::Number,

            'string' => NodeType::String,
            'array' => NodeType::Array,

            'numeric' => null,
        };
    }
}
