<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportType: string
{
    case Word = 'word';
    case Excel = 'excel';
}
