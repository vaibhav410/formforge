<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InvalidSchemaException extends RuntimeException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(
            'Schema validation failed: '.
            implode(' | ', array_map(
                fn (array $e) => "{$e['path']}: {$e['message']}",
                array_slice($errors, 0, 5)
            )).
            (count($errors) > 5 ? ' (+'.(count($errors) - 5).' more)' : '')
        );
    }
}
