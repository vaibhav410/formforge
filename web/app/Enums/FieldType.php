<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical list of field types. The PHP side of the schema contract:
 * the validator, renderer, builder palette and import parsers all
 * consult this enum — never a hard-coded string list.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Email = 'email';
    case Phone = 'phone';
    case Date = 'date';
    case Time = 'time';
    case Dropdown = 'dropdown';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case File = 'file';
    case Rating = 'rating';
    case Heading = 'heading';
    case Address = 'address';
    case Url = 'url';
    case Password = 'password';
    case Signature = 'signature';
    case Color = 'color';
    case Hidden = 'hidden';

    /** Types that carry an options list. */
    public function hasOptions(): bool
    {
        return in_array($this, [self::Dropdown, self::Radio, self::Checkbox], true);
    }

    /** Layout-only types collect no answer. */
    public function collectsAnswer(): bool
    {
        return $this !== self::Heading;
    }

    /** Types whose answer is an array/object rather than a scalar. */
    public function hasStructuredValue(): bool
    {
        return in_array($this, [self::Checkbox, self::Address, self::File], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Short text',
            self::Textarea => 'Long text',
            self::Number => 'Number',
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::Date => 'Date',
            self::Time => 'Time',
            self::Dropdown => 'Dropdown',
            self::Radio => 'Radio choice',
            self::Checkbox => 'Checkboxes',
            self::File => 'File upload',
            self::Rating => 'Rating',
            self::Heading => 'Heading',
            self::Address => 'Address',
            self::Url => 'URL',
            self::Password => 'Password',
            self::Signature => 'Signature',
            self::Color => 'Color',
            self::Hidden => 'Hidden',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
