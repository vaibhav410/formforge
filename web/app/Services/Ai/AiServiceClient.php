<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\DTO\AiResultData;
use App\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * REST client for the FastAPI AI service. This is the only place the
 * Laravel codebase knows the service exists; everything else deals in
 * AiResultData.
 */
class AiServiceClient
{
    public function generate(string $prompt, ?string $locale = null): AiResultData
    {
        return $this->post('/v1/forms/generate', array_filter([
            'prompt' => $prompt,
            'locale' => $locale,
        ]));
    }

    public function edit(string $prompt, array $schema): AiResultData
    {
        return $this->post('/v1/forms/edit', [
            'prompt' => $prompt,
            'schema' => $schema,
        ]);
    }

    public function translate(string $targetLanguage, array $schema): AiResultData
    {
        return $this->post('/v1/forms/translate', [
            'target_language' => $targetLanguage,
            'schema' => $schema,
        ]);
    }

    public function healthy(): bool
    {
        try {
            return Http::timeout(5)
                ->get(rtrim(config('formforge.ai.url'), '/').'/health')
                ->json('status') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    private function post(string $path, array $payload): AiResultData
    {
        try {
            $response = Http::withToken(config('formforge.ai.token'))
                ->timeout((int) config('formforge.ai.timeout'))
                ->acceptJson()
                ->post(rtrim(config('formforge.ai.url'), '/').$path, $payload);
        } catch (ConnectionException $e) {
            throw new AiServiceException('AI service is unreachable: '.$e->getMessage());
        }

        if ($response->status() === 422) {
            throw new AiServiceException(
                $response->json('detail') ?? 'The AI could not produce a valid schema.',
                $response->json('attempts') ?? [],
            );
        }

        if ($response->failed()) {
            throw new AiServiceException(
                'AI service error ('.$response->status().'): '.substr($response->body(), 0, 300)
            );
        }

        return AiResultData::fromResponse($response->json());
    }
}
