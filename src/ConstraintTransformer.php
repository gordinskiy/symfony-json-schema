<?php

declare(strict_types=1);

namespace Gordinskiy\SymfonyJsonSchema;

use Gordinskiy\JsonSchema\Array\ArraySchema;
use Gordinskiy\JsonSchema\Boolean\BooleanSchema;
use Gordinskiy\JsonSchema\Composition\AllOfSchema;
use Gordinskiy\JsonSchema\Composition\NotSchema;
use Gordinskiy\JsonSchema\Composition\OneOfSchema;
use Gordinskiy\JsonSchema\NodeType;
use Gordinskiy\JsonSchema\Numeric\NumberSchema;
use Gordinskiy\JsonSchema\Object\ObjectProperties;
use Gordinskiy\JsonSchema\Object\ObjectProperty;
use Gordinskiy\JsonSchema\Object\ObjectSchema;
use Gordinskiy\JsonSchema\SchemaNodeInterface;
use Gordinskiy\JsonSchema\String\StringFormat;
use Gordinskiy\JsonSchema\String\StringSchema;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Ulid;
use Symfony\Component\Validator\Constraints\Url;

class ConstraintTransformer
{
    public function transform(Constraint ...$constraints): SchemaNodeInterface
    {
        return match (SchemaTypeGuesser::getTypeOfConstraints(...$constraints)) {
            NodeType::String => $this->buildStringSchema(...$constraints),
            NodeType::Object => $this->buildObjectSchema(...$constraints),
            NodeType::Array => $this->buildArraySchema(...$constraints),
            null => $this->buildGenericSchema(...$constraints),
            default => throw new \Exception('Not supported constraint ' . $this->norm(...$constraints)),
        };
    }

    private function buildStringSchema(Constraint ...$constraints): StringSchema
    {
        $minLength = null;
        $maxLength = null;
        $pattern = null;
        $format = null;

        foreach ($constraints as $constraint) {
            $constraintClass = $constraint::class;

            do {
                switch ($constraintClass) {
                    case Length::class:
                        /** @var Length $constraint */
                        $minLength = $constraint->min;
                        $maxLength = $constraint->max;
                        break;
                    case Regex::class:
                        /** @var Regex $constraint */
                        $pattern = $constraint->pattern;
                        break;
                    case Email::class:
                        /** @var Email $constraint */
                        $format = StringFormat::Email;
                        break;
                    case Url::class:
                        /** @var Url $constraint */
                        $format = StringFormat::UriReference;
                        break;
                    case Ulid::class:
                        /** @var Ulid $constraint */
                        $minLength = 26;
                        $maxLength = 26;
                        $pattern = '[0-7][0-9A-HJKMNP-TV-Z]{25}';
                        break;
                }
            } while ($constraintClass = get_parent_class($constraintClass));
        }

        return new StringSchema(
            minLength: $minLength,
            maxLength: $maxLength,
            pattern: $pattern,
            format: $format
        );
    }

    private function buildObjectSchema(Constraint ...$constraints): ObjectSchema
    {
        $properties = [];
        $required = [];
        $additionalProperties = null;

        foreach ($constraints as $constraint) {
            $constraintClass = $constraint::class;

            do {
                switch ($constraintClass) {
                    case Collection::class:
                        /** @var Collection $constraint */
                        if ($constraint->allowExtraFields) {
                            $additionalProperties = true;
                        }

                        foreach ($constraint->fields as $fieldName => $fieldConstraints) {
                            if ($fieldConstraints instanceof Optional) {
                                $fieldConstraints = $fieldConstraints->constraints;
                            } elseif ($fieldConstraints instanceof Required) {
                                $fieldConstraints = $fieldConstraints->constraints;

                                $required[$fieldName] = $fieldName;
                            }

                            if (!$constraint->allowMissingFields) {
                                $required[$fieldName] = $fieldName;
                            }

                            $fieldConstraints = is_array($fieldConstraints) ? $fieldConstraints : [$fieldConstraints];
                            $properties[] = new ObjectProperty(
                                $fieldName,
                                $this->transform(...$fieldConstraints)
                            );
                        }

                        break;
                };
            } while ($constraintClass = get_parent_class($constraintClass));
        }

        return new ObjectSchema(
            properties: $properties ? new ObjectProperties(...$properties) : null,
            required: array_values($required),
            additionalProperties: $additionalProperties,
        );
    }

    private function buildArraySchema(Constraint ...$constraints): ArraySchema
    {
        $items = null;

        foreach ($constraints as $constraint) {
            switch ($constraint::class) {
                case All::class:
                    $items = $this->transform(...$constraint->constraints);

                    break;
            };
        }

        return new ArraySchema(
            items: $items,
        );
    }

    private function buildGenericSchema(Constraint ...$constraints): SchemaNodeInterface
    {
        $schemas = [];

        foreach ($constraints as $constraint) {
            switch ($constraint::class) {
                case NotBlank::class:
                    $schemas[] = new NotSchema(
                        new OneOfSchema(
                            new ArraySchema(minItems: 1),
                            new StringSchema(minLength: 1),
                            new NotSchema(new StringSchema(const: '0')),
                            new NotSchema(new NumberSchema(const: 0)),
                            new NotSchema(new BooleanSchema(const: false)),
                        )
                    );
                    break;
            };
        }

        return new AllOfSchema(...$schemas);
    }

    private function norm(Constraint ...$constraints): string
    {
        $result = [];

        foreach ($constraints as $constraint) {
            $result[] = $constraint::class;
        }

        return implode(',', $result);
    }
}
