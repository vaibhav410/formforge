<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle shared by ai_tasks, imports and form_exports. */
enum TaskStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case PreviewReady = 'preview_ready'; // imports only: awaiting user mapping review
    case Committed = 'committed';        // imports only: schema accepted into a form
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Committed], true);
    }
}
