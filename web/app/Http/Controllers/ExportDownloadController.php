<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\FormExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDownloadController extends Controller
{
    public function __invoke(Request $request, FormExport $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        abort_unless($export->status === TaskStatus::Completed && $export->stored_path !== null, 404);

        $filename = 'submissions-'.$export->form->public_id.'-'.$export->created_at->format('Ymd-His').'.csv';

        return Storage::disk('local')->download($export->stored_path, $filename);
    }
}
