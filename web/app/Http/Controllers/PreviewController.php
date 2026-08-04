<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Form;
use App\Schema\FormSchema;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Authenticated preview of the current draft — same template as the
 * public form, but no events, no token, and submission disabled.
 */
class PreviewController extends Controller
{
    public function __invoke(Request $request, Form $form): View
    {
        abort_unless($request->user()->can('view', $form), 403);

        $version = $form->latestDraftVersion() ?? $form->latestVersion();
        abort_if($version === null, 404);

        return view('public.form', [
            'form' => $form,
            'schema' => FormSchema::fromArray($version->schema_json),
            'renderToken' => null,
            'preview' => true,
        ]);
    }
}
