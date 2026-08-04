<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FormStatus;
use App\Enums\VersionSource;
use App\Enums\VersionStatus;
use App\Exceptions\InvalidSchemaException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Schema\FormSchema;
use App\Schema\FormSchemaValidator;
use App\Schema\SchemaFactory;
use App\Schema\SchemaSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * All writes to forms and versions flow through here — the Livewire
 * builder, the AI apply step, the import commit and the JSON editor
 * share one save path, so sanitize → validate → persist can never be
 * bypassed.
 */
class FormService
{
    public function __construct(
        private readonly SchemaSanitizer $sanitizer,
        private readonly FormSchemaValidator $validator,
    ) {
    }

    public function createForm(User $user, string $title = 'Untitled form'): Form
    {
        return $this->createFormFromSchema(
            $user,
            SchemaFactory::emptySchema($title),
            VersionSource::Manual
        );
    }

    /**
     * @throws InvalidSchemaException
     */
    public function createFormFromSchema(
        User $user,
        array $schema,
        VersionSource $source,
        ?string $label = null,
        bool $lenient = false,
    ): Form {
        $schema = $this->cleanAndValidate($schema, $lenient);

        return DB::transaction(function () use ($user, $schema, $source, $label) {
            $form = $user->forms()->create([
                'title' => $schema['title'],
                'description' => $schema['description'],
                'status' => FormStatus::Draft,
                'settings' => $schema['settings'],
            ]);

            $form->versions()->create([
                'version' => 1,
                'schema_json' => $schema,
                'status' => VersionStatus::Draft,
                'source' => $source,
                'label' => $label ?? 'Initial version',
            ]);

            return $form;
        });
    }

    /**
     * Persist a builder/editor/AI change into the form's editable draft.
     * Creates the next draft version if the latest version is published.
     *
     * @throws InvalidSchemaException
     */
    public function saveDraftSchema(
        Form $form,
        array $schema,
        VersionSource $source = VersionSource::Manual,
        ?string $label = null,
        bool $lenient = false,
    ): FormVersion {
        $schema = $this->cleanAndValidate($schema, $lenient);

        return DB::transaction(function () use ($form, $schema, $source, $label) {
            $draft = $form->latestDraftVersion();

            if ($draft === null) {
                $draft = $form->versions()->create([
                    'version' => ($form->latestVersion()?->version ?? 0) + 1,
                    'schema_json' => $schema,
                    'status' => VersionStatus::Draft,
                    'source' => $source,
                    'label' => $label,
                ]);
            } else {
                $draft->update([
                    'schema_json' => $schema,
                    'source' => $source,
                    'label' => $label ?? $draft->label,
                ]);
            }

            // Keep the listing columns in sync with the schema truth.
            $form->update([
                'title' => $schema['title'],
                'description' => $schema['description'],
                'settings' => $schema['settings'],
            ]);

            return $draft;
        });
    }

    /** Publish the current draft (or re-publish the latest version). */
    public function publish(Form $form): FormVersion
    {
        return DB::transaction(function () use ($form) {
            $version = $form->latestDraftVersion() ?? $form->latestVersion();

            if ($version === null) {
                throw new \LogicException('Form has no versions to publish.');
            }

            $form->versions()
                ->where('status', VersionStatus::Published)
                ->where('id', '!=', $version->id)
                ->update(['status' => VersionStatus::Superseded]);

            $version->update([
                'status' => VersionStatus::Published,
                'published_at' => now(),
            ]);

            $form->update([
                'status' => FormStatus::Published,
                'published_version_id' => $version->id,
                'published_at' => $form->published_at ?? now(),
            ]);

            $this->forgetCompiledSchema($form);

            return $version;
        });
    }

    /**
     * Roll back: copy an old version's schema into a new version and
     * publish it. History stays intact — rollback is itself a version.
     */
    public function rollbackTo(Form $form, FormVersion $target): FormVersion
    {
        if ($target->form_id !== $form->id) {
            throw new \LogicException('Version does not belong to this form.');
        }

        return DB::transaction(function () use ($form, $target) {
            // Drop any pending draft; the rollback becomes the new head.
            $form->latestDraftVersion()?->delete();

            $form->versions()->create([
                'version' => ($form->latestVersion()?->version ?? 0) + 1,
                'schema_json' => $target->schema_json,
                'status' => VersionStatus::Draft,
                'source' => VersionSource::Rollback,
                'label' => "Rollback to v{$target->version}",
            ]);

            return $this->publish($form);
        });
    }

    public function unpublish(Form $form): void
    {
        $form->update(['status' => FormStatus::Draft]);
        $this->forgetCompiledSchema($form);
    }

    public function archive(Form $form): void
    {
        $form->update(['status' => FormStatus::Archived]);
        $this->forgetCompiledSchema($form);
    }

    /**
     * The published schema for public rendering, Redis-cached: the fill
     * page is the hottest path and never needs a versions-table read.
     */
    public function publishedSchema(Form $form): ?FormSchema
    {
        $cached = Cache::remember(
            self::schemaCacheKey($form),
            now()->addHours(12),
            function () use ($form) {
                $form->loadMissing('publishedVersion');

                return $form->publishedVersion?->schema_json ?? false;
            }
        );

        return $cached === false || $cached === null ? null : FormSchema::fromArray($cached);
    }

    public function forgetCompiledSchema(Form $form): void
    {
        Cache::forget(self::schemaCacheKey($form));
    }

    public static function schemaCacheKey(Form $form): string
    {
        return "form:{$form->id}:published-schema";
    }

    /** @throws InvalidSchemaException */
    private function cleanAndValidate(array $schema, bool $lenient): array
    {
        $schema = $this->sanitizer->sanitize($schema, $lenient);
        $errors = $this->validator->validate($schema);

        if ($errors !== []) {
            throw new InvalidSchemaException($errors);
        }

        return $schema;
    }
}
