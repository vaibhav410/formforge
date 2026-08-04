<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Services\FormService;
use App\Services\SubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PublicFormController extends Controller
{
    public function __construct(
        private readonly FormService $formService,
        private readonly SubmissionService $submissionService,
    ) {
    }

    public function show(Request $request, Form $form): View
    {
        abort_unless($form->status === FormStatus::Published, 404);

        $schema = $this->formService->publishedSchema($form);
        abort_if($schema === null, 404);

        $visitorId = $this->visitorId($request);

        // One view event per visitor per hour, not one per refresh.
        $viewedRecently = $form->events()
            ->where('visitor_id', $visitorId)
            ->where('event', 'view')
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if (! $viewedRecently) {
            $form->events()->create([
                'form_version_id' => $form->published_version_id,
                'visitor_id' => $visitorId,
                'event' => 'view',
                'created_at' => now(),
            ]);
            $form->increment('views_count');
        }

        return view('public.form', [
            'form' => $form,
            'schema' => $schema,
            'renderToken' => $this->submissionService->makeRenderToken(),
            'preview' => false,
        ]);
    }

    public function submit(Request $request, Form $form): RedirectResponse
    {
        abort_unless($form->status === FormStatus::Published, 404);

        $schema = $this->formService->publishedSchema($form);
        abort_if($schema === null, 404);

        // Honeypot: real users never fill the visually-hidden field, and
        // bots that submit in under 3 seconds aren't users either.
        $startedAt = $this->submissionService->decodeRenderToken($request->input('_rt'));
        if ($request->filled('_website')
            || ($startedAt !== null && $startedAt->diffInSeconds(now()) < 3)) {
            // Pretend success; give the bot nothing to learn from.
            return redirect()->route('forms.public.thanks', $form);
        }

        $validated = $this->submissionService
            ->makeValidator($schema, $request->all())
            ->validate();

        $this->submissionService->store($form, $schema, $validated, $request);

        return redirect()->route('forms.public.thanks', $form);
    }

    public function thanks(Form $form): View
    {
        abort_unless($form->status === FormStatus::Published, 404);

        $schema = $this->formService->publishedSchema($form);

        return view('public.thanks', [
            'form' => $form,
            'message' => $schema?->settings()['success_message']
                ?? 'Thank you — your response has been recorded.',
        ]);
    }

    private function visitorId(Request $request): string
    {
        $visitorId = $request->cookie('ff_visitor');

        if (! is_string($visitorId) || strlen($visitorId) !== 32) {
            $visitorId = Str::random(32);
            \Illuminate\Support\Facades\Cookie::queue('ff_visitor', $visitorId, 60 * 24 * 365);
        }

        return $visitorId;
    }
}
