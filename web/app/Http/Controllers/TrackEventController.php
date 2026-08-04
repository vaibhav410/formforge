<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FormStatus;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Beacon endpoint for funnel analytics from the public form: `start`
 * on first interaction, `field_focus` as the respondent moves through
 * the form, `abandon` (via navigator.sendBeacon) when they leave
 * without submitting. Throttled and fire-and-forget.
 */
class TrackEventController extends Controller
{
    public function __invoke(Request $request, Form $form): JsonResponse
    {
        abort_unless($form->status === FormStatus::Published, 404);

        $data = $request->validate([
            'event' => ['required', 'in:start,field_focus,abandon'],
            'field_key' => ['nullable', 'string', 'max:100'],
        ]);

        $visitorId = $request->cookie('ff_visitor');
        if (! is_string($visitorId) || $visitorId === '') {
            return response()->json(['ok' => false], 202);
        }

        // Starts are once per visitor per hour; focus/abandon flow freely.
        if ($data['event'] === 'start') {
            $alreadyStarted = $form->events()
                ->where('visitor_id', $visitorId)
                ->where('event', 'start')
                ->where('created_at', '>=', now()->subHour())
                ->exists();
            if ($alreadyStarted) {
                return response()->json(['ok' => true]);
            }
        }

        $form->events()->create([
            'form_version_id' => $form->published_version_id,
            'visitor_id' => $visitorId,
            'event' => $data['event'],
            'field_key' => $data['field_key'] ?? null,
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
