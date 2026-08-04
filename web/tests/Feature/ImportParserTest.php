<?php

use App\Schema\FormSchemaValidator;
use App\Schema\SchemaSanitizer;
use App\Services\Import\ExcelParser;
use App\Services\Import\WordParser;

// The committed sample files are the parser contract: these tests pin
// exactly what a reviewer will see when importing them.

function assertCleanAfterPipeline(array $schema): void
{
    $sanitized = app(SchemaSanitizer::class)->sanitize($schema, lenient: true);
    expect(app(FormSchemaValidator::class)->validate($sanitized))->toBe([]);
}

test('the sample Word document parses into sections, fields and options', function () {
    $result = app(WordParser::class)->parse(base_path('../samples/job-application.docx'));
    $schema = $result['schema'];

    expect($schema['title'])->toBe('Job Application Form')
        ->and(array_column($schema['sections'], 'title'))
        ->toBe(['Personal Information', 'Position Details', 'Documents'])
        ->and($result['issues'])->toBe([]);

    $byKey = collect($schema['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');

    expect($byKey['email_address']['type'])->toBe('email')
        ->and($byKey['email_address']['required'])->toBeTrue()
        ->and($byKey['phone_number']['type'])->toBe('phone')
        ->and($byKey['which_position_are_you_applying_for']['type'])->toBe('radio')
        ->and(array_column($byKey['which_position_are_you_applying_for']['options'], 'value'))
        ->toBe(['software_engineer', 'product_designer', 'qa_analyst'])
        ->and($byKey['skills_select_all_that_apply']['type'])->toBe('checkbox')
        ->and($byKey['upload_your_resume']['type'])->toBe('file')
        ->and($byKey['upload_your_resume']['required'])->toBeTrue()
        ->and($byKey['why_do_you_want_to_work_with_us']['type'])->toBe('textarea');

    assertCleanAfterPipeline($schema);
});

test('the structured Excel sheet maps typed rows into sections', function () {
    $result = app(ExcelParser::class)->parse(base_path('../samples/event-feedback-structured.xlsx'));
    $schema = $result['schema'];

    expect($schema['title'])->toBe('Event Feedback')
        ->and(array_column($schema['sections'], 'title'))
        ->toBe(['About you', 'Feedback', 'Logistics'])
        ->and($result['issues'])->toBe([]);

    $byKey = collect($schema['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');

    expect($byKey['sessions']['type'])->toBe('checkbox') // "multiselect" alias
        ->and($byKey['attend_again']['type'])->toBe('radio') // "yes/no" alias
        ->and($byKey['improvements']['type'])->toBe('textarea') // "paragraph" alias
        ->and($byKey['dietary']['type'])->toBe('dropdown')
        ->and(array_column($byKey['dietary']['options'], 'label'))
        ->toBe(['None', 'Vegetarian', 'Vegan', 'Gluten-free'])
        ->and($byKey['email']['required'])->toBeTrue();

    assertCleanAfterPipeline($schema);
});

test('the plain header-row Excel sheet infers types from labels and sample data', function () {
    $result = app(ExcelParser::class)->parse(base_path('../samples/vendor-contacts-plain.xlsx'));
    $schema = $result['schema'];

    $byKey = collect($schema['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');

    expect($byKey['email']['type'])->toBe('email')
        ->and($byKey['phone']['type'])->toBe('phone')
        ->and($byKey['website']['type'])->toBe('url')
        ->and($byKey['number_of_employees']['type'])->toBe('number')
        ->and($byKey['onboarding_date']['type'])->toBe('date');

    assertCleanAfterPipeline($schema);
});

test('a garbage file is rejected with a clear error, not silently parsed', function () {
    $path = sys_get_temp_dir().'/formforge-garbage.xlsx';
    file_put_contents($path, 'this is not a spreadsheet');

    // Without an explicit reader PhpSpreadsheet would fall back to CSV
    // and "parse" the garbage. The job layer stores this message on the
    // import row as status=failed.
    expect(fn () => app(ExcelParser::class)->parse($path))
        ->toThrow(RuntimeException::class, 'not a valid .xlsx');
    @unlink($path);
});
