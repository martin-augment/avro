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

namespace Apache\Avro\Tests\Generator;

use Apache\Avro\Generator\AvroTranspiler;
use Apache\Avro\Schema\AvroSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AvroTranspilerTest extends TestCase
{
    private AvroTranspiler $transpiler;

    public function setUp(): void
    {
        $this->transpiler = new AvroTranspiler();
    }

    #[Test]
    public function nested_schema_generation(): void
    {
        $schema = <<<JSON
            {
               "type":"record",
               "name":"Lisp",
               "fields":[
                  {
                     "name":"value",
                     "type":[
                        "null",
                        "string",
                        {
                           "type":"record",
                           "name":"Cons",
                           "fields":[
                              {
                                 "name":"car",
                                 "type":"Lisp"
                              },
                              {
                                 "name":"cdr",
                                 "type":"Lisp"
                              }
                           ]
                        }
                     ]
                  }
               ]
            }
            JSON;

        $avroSchema = AvroSchema::parse($schema);
        $files = $this->transpiler->translate($avroSchema, '/generated', 'MyApp\\Avro\\Generated');

        self::assertCount(2, $files);

        self::assertArrayHasKey('/generated/Lisp.php', $files);
        self::assertArrayHasKey('/generated/Cons.php', $files);

        $expectedLisp = <<<PHP
            <?php
            
            declare(strict_types=1);
            
            namespace MyApp\Avro\Generated;
            
            final class Lisp
            {
                private null|string|\MyApp\Avro\Generated\Cons \$value;
                public function __construct(null|string|\MyApp\Avro\Generated\Cons \$value)
                {
                    \$this->value = \$value;
                }
                public function value(): null|string|\MyApp\Avro\Generated\Cons
                {
                    return \$this->value;
                }
            }

            PHP;

        self::assertEquals($expectedLisp, $files['/generated/Lisp.php']);

        $expectedLisp = <<<PHP
            <?php

            declare(strict_types=1);
            
            namespace MyApp\Avro\Generated;
            
            final class Cons
            {
                private \MyApp\Avro\Generated\Lisp \$car;
                private \MyApp\Avro\Generated\Lisp \$cdr;
                public function __construct(\MyApp\Avro\Generated\Lisp \$car, \MyApp\Avro\Generated\Lisp \$cdr)
                {
                    \$this->car = \$car;
                    \$this->cdr = \$cdr;
                }
                public function car(): \MyApp\Avro\Generated\Lisp
                {
                    return \$this->car;
                }
                public function cdr(): \MyApp\Avro\Generated\Lisp
                {
                    return \$this->cdr;
                }
            }

            PHP;
        self::assertEquals($expectedLisp, $files['/generated/Cons.php']);
    }
}
