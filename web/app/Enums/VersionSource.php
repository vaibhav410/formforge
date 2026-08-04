<?php

declare(strict_types=1);

namespace App\Enums;

enum VersionSource: string
{
    case Manual = 'manual';
    case Ai = 'ai';
    case Import = 'import';
    case Rollback = 'rollback';
}
