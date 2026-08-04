<?php

namespace Database\Seeders;

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Schema\FormSchema;
use App\Schema\SchemaFactory;
use App\Services\FormService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo account with three realistic published forms, submissions and
 * analytics events — the state reviewers land in with the README
 * credentials.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $formService = app(FormService::class);

        $user = User::query()->firstOrCreate(
            ['email' => 'demo@formforge.test'],
            ['name' => 'Demo User', 'password' => 'password', 'email_verified_at' => now()]
        );

        if ($user->forms()->exists()) {
            $this->command?->info('Demo data already present, skipping.');

            return;
        }

        foreach ([$this->jobApplicationSchema(), $this->feedbackSchema(), $this->eventSchema()] as $schema) {
            $form = $formService->createFormFromSchema($user, $schema, \App\Enums\VersionSource::Manual);
            $formService->publish($form->refresh());
            $this->seedActivity($form->refresh());
        }
    }

    private function jobApplicationSchema(): array
    {
        $schema = SchemaFactory::emptySchema('Job Application');
        $schema['description'] = 'Apply for an open role at Acme Inc.';
        $schema['sections'] = [
            SchemaFactory::section('Personal details', ['fields' => [
                SchemaFactory::field(FieldType::Text, ['key' => 'full_name', 'label' => 'Full name', 'required' => true, 'placeholder' => 'Jane Doe', 'validation' => ['min_length' => 2, 'max_length' => 100] + $this->noRules()]),
                SchemaFactory::field(FieldType::Email, ['key' => 'email', 'label' => 'Email address', 'required' => true, 'placeholder' => 'jane@example.com']),
                SchemaFactory::field(FieldType::Phone, ['key' => 'phone', 'label' => 'Phone number', 'required' => true]),
                SchemaFactory::field(FieldType::Address, ['key' => 'address', 'label' => 'Current address', 'required' => false]),
            ]]),
            SchemaFactory::section('Role & experience', ['fields' => [
                SchemaFactory::field(FieldType::Dropdown, ['key' => 'position', 'label' => 'Position applied for', 'required' => true, 'options' => [
                    ['label' => 'Software Engineer', 'value' => 'swe'],
                    ['label' => 'Senior Software Engineer', 'value' => 'senior_swe'],
                    ['label' => 'Product Designer', 'value' => 'designer'],
                ]]),
                SchemaFactory::field(FieldType::Number, ['key' => 'years_experience', 'label' => 'Years of experience', 'required' => true, 'validation' => ['min' => 0, 'max' => 50] + $this->noRules()]),
                SchemaFactory::field(FieldType::Textarea, ['key' => 'leadership_examples', 'label' => 'Tell us about a team you led', 'required' => true, 'logic' => [
                    'action' => 'show', 'match' => 'all',
                    'conditions' => [['field' => 'position', 'operator' => 'equals', 'value' => 'senior_swe']],
                ]]),
                SchemaFactory::field(FieldType::Checkbox, ['key' => 'skills', 'label' => 'Skills', 'required' => true, 'options' => [
                    ['label' => 'PHP / Laravel', 'value' => 'laravel'],
                    ['label' => 'Python', 'value' => 'python'],
                    ['label' => 'JavaScript', 'value' => 'javascript'],
                    ['label' => 'SQL', 'value' => 'sql'],
                ]]),
            ]]),
            SchemaFactory::section('Documents', ['fields' => [
                SchemaFactory::field(FieldType::File, ['key' => 'resume', 'label' => 'Resume (PDF)', 'required' => true, 'validation' => ['mimes' => ['pdf'], 'max_size_kb' => 5120] + $this->noRules()]),
                SchemaFactory::field(FieldType::Url, ['key' => 'portfolio_url', 'label' => 'Portfolio or GitHub URL']),
                SchemaFactory::field(FieldType::Date, ['key' => 'available_from', 'label' => 'Available from', 'required' => true]),
            ]]),
        ];

        return $schema;
    }

    private function feedbackSchema(): array
    {
        $schema = SchemaFactory::emptySchema('Customer Feedback Survey');
        $schema['description'] = 'Two minutes — help us improve.';
        $schema['sections'] = [
            SchemaFactory::section('Your experience', ['fields' => [
                SchemaFactory::field(FieldType::Rating, ['key' => 'overall_rating', 'label' => 'Overall satisfaction', 'required' => true, 'meta' => ['rating_max' => 5]]),
                SchemaFactory::field(FieldType::Textarea, ['key' => 'what_went_wrong', 'label' => 'What went wrong?', 'required' => true, 'logic' => [
                    'action' => 'show', 'match' => 'all',
                    'conditions' => [['field' => 'overall_rating', 'operator' => 'less_than', 'value' => 3]],
                ]]),
                SchemaFactory::field(FieldType::Radio, ['key' => 'recommend', 'label' => 'Would you recommend us?', 'required' => true, 'options' => [
                    ['label' => 'Definitely', 'value' => 'yes'],
                    ['label' => 'Maybe', 'value' => 'maybe'],
                    ['label' => 'No', 'value' => 'no'],
                ]]),
                SchemaFactory::field(FieldType::Textarea, ['key' => 'comments', 'label' => 'Anything else?']),
            ]]),
        ];

        return $schema;
    }

    private function eventSchema(): array
    {
        $schema = SchemaFactory::emptySchema('Event Registration — DevConf 2026');
        $schema['sections'] = [
            SchemaFactory::section('Attendee', ['fields' => [
                SchemaFactory::field(FieldType::Text, ['key' => 'full_name', 'label' => 'Full name', 'required' => true]),
                SchemaFactory::field(FieldType::Email, ['key' => 'email', 'label' => 'Email', 'required' => true]),
                SchemaFactory::field(FieldType::Dropdown, ['key' => 'ticket_type', 'label' => 'Ticket type', 'required' => true, 'options' => [
                    ['label' => 'Standard', 'value' => 'standard'],
                    ['label' => 'VIP', 'value' => 'vip'],
                    ['label' => 'Student', 'value' => 'student'],
                ]]),
                SchemaFactory::field(FieldType::Checkbox, ['key' => 'workshops', 'label' => 'Workshops', 'options' => [
                    ['label' => 'AI in production', 'value' => 'ai'],
                    ['label' => 'Scaling Laravel', 'value' => 'laravel'],
                    ['label' => 'Kubernetes 101', 'value' => 'k8s'],
                ]]),
                SchemaFactory::field(FieldType::Date, ['key' => 'arrival_date', 'label' => 'Arrival date', 'required' => true]),
            ]]),
        ];

        return $schema;
    }

    private function seedActivity(Form $form): void
    {
        $schema = FormSchema::fromArray($form->publishedVersion->schema_json);
        $submissionTarget = random_int(25, 45);
        $submitted = 0;

        for ($day = 29; $day >= 0; $day--) {
            $date = now()->subDays($day);
            $viewsToday = random_int(5, 25);

            for ($i = 0; $i < $viewsToday; $i++) {
                $visitor = Str::random(32);
                $at = $date->copy()->setTime(random_int(8, 22), random_int(0, 59));

                $form->events()->create([
                    'form_version_id' => $form->published_version_id,
                    'visitor_id' => $visitor,
                    'event' => 'view',
                    'created_at' => $at,
                ]);
                $form->increment('views_count');

                if (random_int(1, 100) > 55) {
                    continue; // bounced without starting
                }
                $form->events()->create([
                    'form_version_id' => $form->published_version_id,
                    'visitor_id' => $visitor,
                    'event' => 'start',
                    'created_at' => $at->copy()->addSeconds(10),
                ]);

                if ($submitted >= $submissionTarget || random_int(1, 100) > 65) {
                    // Abandoned mid-form: record where they dropped off.
                    $keys = $schema->fieldKeys();
                    $form->events()->create([
                        'form_version_id' => $form->published_version_id,
                        'visitor_id' => $visitor,
                        'event' => 'abandon',
                        'field_key' => $keys[array_rand($keys)] ?? null,
                        'created_at' => $at->copy()->addSeconds(90),
                    ]);

                    continue;
                }

                $submission = $this->makeSubmission($form, $schema, $at);
                $submitted++;
                $form->events()->create([
                    'form_version_id' => $form->published_version_id,
                    'visitor_id' => $visitor,
                    'event' => 'submit',
                    'submission_id' => $submission->id,
                    'created_at' => $submission->submitted_at,
                ]);
            }
        }
    }

    private function makeSubmission(Form $form, FormSchema $schema, \Illuminate\Support\Carbon $at): Submission
    {
        $duration = random_int(45, 480);
        $submission = $form->submissions()->create([
            'form_version_id' => $form->published_version_id,
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'user_agent' => fake()->userAgent(),
            'started_at' => $at,
            'submitted_at' => $at->copy()->addSeconds($duration),
            'duration_seconds' => $duration,
        ]);
        $form->increment('submissions_count');

        foreach ($schema->answerableFields() as $field) {
            [$text, $json] = $this->fakeAnswer($field);
            if ($text === null && $json === null) {
                continue;
            }
            $submission->answers()->create([
                'form_id' => $form->id,
                'field_key' => $field['key'],
                'field_type' => $field['type'],
                'value_text' => $text,
                'value_json' => $json,
            ]);
        }

        return $submission;
    }

    /** @return array{0: ?string, 1: ?array} [value_text, value_json] */
    private function fakeAnswer(array $field): array
    {
        $type = FieldType::from($field['type']);
        $options = array_map(fn ($o) => $o['value'], $field['options'] ?? []);

        return match ($type) {
            FieldType::Text => [fake()->name(), null],
            FieldType::Textarea => [fake()->boolean(70) ? fake()->paragraph() : null, null],
            FieldType::Number => [(string) fake()->numberBetween(
                (int) ($field['validation']['min'] ?? 0),
                (int) ($field['validation']['max'] ?? 20)
            ), null],
            FieldType::Email => [fake()->safeEmail(), null],
            FieldType::Phone => ['+91 '.fake()->numerify('##########'), null],
            FieldType::Date => [fake()->dateTimeBetween('now', '+60 days')->format('Y-m-d'), null],
            FieldType::Time => [sprintf('%02d:%02d', random_int(9, 18), random_int(0, 59)), null],
            FieldType::Dropdown, FieldType::Radio => [$options !== [] ? $options[array_rand($options)] : null, null],
            FieldType::Checkbox => [null, $options !== []
                ? array_values(array_intersect_key($options, array_flip((array) array_rand($options, min(2, count($options))))))
                : null],
            FieldType::Rating => [(string) random_int(1, (int) ($field['meta']['rating_max'] ?? 5)), null],
            FieldType::Address => [null, [
                'line1' => fake()->streetAddress(),
                'line2' => null,
                'city' => fake()->city(),
                'state' => fake()->state(),
                'postal_code' => fake()->postcode(),
                'country' => fake()->country(),
            ]],
            FieldType::Url => [fake()->boolean(60) ? 'https://github.com/'.fake()->userName() : null, null],
            FieldType::File => [null, [
                'name' => 'resume_'.fake()->lastName().'.pdf',
                'path' => 'seeded/placeholder.pdf',
                'size_kb' => random_int(80, 900),
            ]],
            default => [null, null],
        };
    }

    private function noRules(): array
    {
        return [
            'min' => null, 'max' => null, 'min_length' => null, 'max_length' => null,
            'regex' => null, 'mimes' => null, 'max_size_kb' => null, 'multiple' => null,
        ];
    }
}
