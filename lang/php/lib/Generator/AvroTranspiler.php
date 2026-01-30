<?php

/**
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements.  See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership.  The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License.  You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace Apache\Avro\Generator;

use Apache\Avro\Schema\AvroArraySchema;
use Apache\Avro\Schema\AvroEnumSchema;
use Apache\Avro\Schema\AvroMapSchema;
use Apache\Avro\Schema\AvroPrimitiveSchema;
use Apache\Avro\Schema\AvroRecordSchema;
use Apache\Avro\Schema\AvroSchema;
use Apache\Avro\Schema\AvroUnionSchema;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

class AvroTranspiler
{
    private BuilderFactory $factory;
    private Standard $printer;

    /** @var array<string, AvroSchema> */
    private array $registry = [];

    public function __construct()
    {
        $this->factory = new BuilderFactory();
        $this->printer = new Standard(['shortArraySyntax' => true]);
    }

    /**
     * @return array<string, string> Map of filename to file contents
     */
    public function translate(
        AvroSchema $schema,
        string $path,
        string $phpNamespace
    ): array {
        $this->registry = [];
        $this->buildRegistry($schema);

        $files = [];

        foreach ($this->registry as $name => $registeredSchema) {
            $node = match (true) {
                $registeredSchema instanceof AvroEnumSchema => $this->buildEnum(
                    $registeredSchema,
                    $phpNamespace,
                    $registeredSchema->symbols()
                ),
                $registeredSchema instanceof AvroRecordSchema => $this->buildRecord(
                    $registeredSchema,
                    $phpNamespace
                ),
                default => null
            };

            if (null !== $node) {
                $code = <<<PHP
                    <?php
                    
                    declare(strict_types=1);
                    
                    {$this->printer->prettyPrint([$node])}
                    
                    PHP;

                $filename = $path.'/'.ucwords($name).'.php';
                $files[$filename] = $code;
            }
        }

        return $files;
    }

    public function buildRegistry(AvroSchema $rootSchema): void
    {
        $this->collectSchemas($rootSchema);
    }

    private function collectSchemas(AvroSchema $schema): void
    {
        if ($schema instanceof AvroRecordSchema) {
            if (!array_key_exists($schema->fullname(), $this->registry)) {
                $this->registry[$schema->fullname()] = $schema;
                foreach ($schema->fields() as $field) {
                    $this->collectSchemas($field->type());
                }
            }
        } elseif ($schema instanceof AvroEnumSchema) {
            $this->registry[$schema->fullname()] = $schema;
        } elseif ($schema instanceof AvroArraySchema) {
            $this->collectSchemas($schema->items());
        } elseif ($schema instanceof AvroMapSchema) {
            $this->collectSchemas($schema->values());
        } elseif ($schema instanceof AvroUnionSchema) {
            foreach ($schema->schemas() as $unionSchema) {
                $this->collectSchemas($unionSchema);
            }
        }
    }

    private function buildRecord(
        AvroRecordSchema $avroRecord,
        string $phpNamespace
    ): Node {
        $className = ucwords($avroRecord->name());
        $class = $this->factory->class($className)->makeFinal();

        foreach ($avroRecord->fields() as $field) {
            $phpType = $this->avroTypeToPhp($field->type(), $phpNamespace);
            $property = $this->factory->property($field->name())
                ->makePrivate()
                ->setType($phpType);

            if ($field->hasDefaultValue()) {
                $property->setDefault($this->buildDefault($field->defaultValue()));
            }

            $class->addStmt($property);
        }

        // Add constructor
        $constructor = $this->factory->method('__construct')->makePublic();
        foreach ($avroRecord->fields() as $field) {
            $phpType = $this->avroTypeToPhp($field->type(), $phpNamespace);
            $param = $this->factory->param($field->name())->setType($phpType);
            if ($field->hasDefaultValue()) {
                $param->setDefault($this->buildDefault($field->defaultValue()));
            }
            $constructor->addParam($param);
            $constructor->addStmt(
                new Node\Expr\Assign(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $field->name()),
                    new Node\Expr\Variable($field->name())
                )
            );
        }
        $class->addStmt($constructor);

        // Add getters
        foreach ($avroRecord->fields() as $field) {
            $phpType = $this->avroTypeToPhp($field->type(), $phpNamespace);
            $getter = $this->factory->method($field->name())
                ->makePublic()
                ->setReturnType($phpType)
                ->addStmt(
                    new Stmt\Return_(
                        new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $field->name())
                    )
                );
            $class->addStmt($getter);
        }

        return $this->factory->namespace($phpNamespace)
            ->addStmt($class)
            ->getNode();
    }

    /**
     * @param array<string> $values
     */
    private function buildEnum(
        AvroEnumSchema $avroEnum,
        string $phpNamespace,
        array $values
    ): Node {
        $className = ucwords($avroEnum->name());
        $enum = $this->factory->enum($className)->setScalarType('string');

        foreach ($values as $value) {
            $caseName = strtoupper($value);
            $enum->addStmt(
                $this->factory->enumCase($caseName)->setValue($value)
            );
        }

        return $this->factory->namespace($phpNamespace)
            ->addStmt($enum)
            ->getNode();
    }

    private function avroTypeToPhp(AvroSchema $schema, string $phpNamespace): string
    {
        return match (true) {
            $schema instanceof AvroPrimitiveSchema => $this->avroPrimitiveTypeToPhp($schema),
            $schema instanceof AvroArraySchema, $schema instanceof AvroMapSchema => 'array',
            $schema instanceof AvroRecordSchema, $schema instanceof AvroEnumSchema => '\\'.$phpNamespace.'\\'.ucwords($schema->name()),
            $schema instanceof AvroUnionSchema => $this->unionToPhp($schema, $phpNamespace),
            default => 'mixed'
        };
    }

    private function avroPrimitiveTypeToPhp(AvroPrimitiveSchema $primitiveSchema): string
    {
        return match ($primitiveSchema->type()) {
            AvroSchema::NULL_TYPE => 'null',
            AvroSchema::BOOLEAN_TYPE => 'bool',
            AvroSchema::INT_TYPE, AvroSchema::LONG_TYPE => 'int',
            AvroSchema::FLOAT_TYPE, AvroSchema::DOUBLE_TYPE => 'float',
            AvroSchema::STRING_TYPE, AvroSchema::BYTES_TYPE => 'string',
            default => throw new AvroTranspilerException("Unknown primitive type: ".$primitiveSchema->type()),
        };
    }

    private function unionToPhp(AvroUnionSchema $union, string $phpNamespace): string
    {
        $types = [];
        foreach ($union->schemas() as $schema) {
            $types[] = $this->avroTypeToPhp($schema, $phpNamespace);
        }

        return implode('|', array_unique($types));
    }

    private function buildDefault(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->factory->val($value);
        }

        return $value;
    }
}
