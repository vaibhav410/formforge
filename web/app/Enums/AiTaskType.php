<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTaskType: string
{
    case Generate = 'generate';
    case Edit = 'edit';
    case Translate = 'translate';
}
