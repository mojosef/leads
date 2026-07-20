<?php

namespace mojosef\Leads\ContactForm;

use BackedEnum;
use mojosef\Leads\Enums\InputType;
use mojosef\Leads\Enums\Question;

/**
 * Maps validated contact-form data to the CRM payload. Every fixed-choice
 * value is round-tripped through its answer enum, so the payload can only
 * ever contain canonical backed values — never translated display labels.
 * Free-text fields pass through as plain strings.
 *
 * Absent and null answers are omitted rather than coerced, preserving the
 * distinction between "not answered" and a real canonical value like
 * SupportLevel 'unsure'.
 */
class CrmMapper
{
    public function __construct(private readonly FormDefinition $definition) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function map(array $validated): array
    {
        $payload = ['form_schema_version' => $this->definition->schemaVersion()];

        foreach ($this->definition->questions() as $question) {
            $value = $validated[$question->value] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $payload[$this->definition->crmProperty($question)] = $this->canonicalise($question, $value);
        }

        return $payload;
    }

    /**
     * @return string|list<string>
     */
    private function canonicalise(Question $question, mixed $value): string|array
    {
        $enum = $question->answerEnum();

        if ($enum === null) {
            return (string) $value;
        }

        if ($question->inputType() === InputType::Checkbox) {
            return array_map(
                fn (mixed $item): string => $this->canonicalValue($enum, $item),
                array_values((array) $value),
            );
        }

        return $this->canonicalValue($enum, $value);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    private function canonicalValue(string $enum, mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $enum::from($value)->value;
    }
}
