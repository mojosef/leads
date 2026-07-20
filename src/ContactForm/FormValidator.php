<?php

namespace mojosef\Leads\ContactForm;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use mojosef\Leads\Enums\InputType;
use mojosef\Leads\Enums\Question;

/**
 * Package-owned validation for the shared contact form. Fixed-choice answers
 * are validated with Rule::enum() against the canonical answer enums, so an
 * unknown or translated value can never enter the pipeline. Sites influence
 * only whether an optional question is required (via config) — never the set
 * of acceptable values.
 */
class FormValidator
{
    public function __construct(private readonly FormDefinition $definition) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the validated data
     *
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        return Validator::make($data, $this->rules(), $this->messages(), $this->attributes())->validate();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->definition->questions() as $question) {
            $field = $question->value;
            $presence = $this->definition->isRequired($question) ? 'required' : 'nullable';

            switch ($question->inputType()) {
                case InputType::Select:
                    $rules[$field] = [$presence, Rule::enum($question->answerEnum())];
                    break;

                case InputType::Checkbox:
                    $rules[$field] = array_merge(
                        [$presence, 'array'],
                        $presence === 'required' ? ['min:1'] : [],
                    );
                    $rules["$field.*"] = ['distinct', Rule::enum($question->answerEnum())];
                    break;

                case InputType::Email:
                    $rules[$field] = [$presence, 'string', 'email', 'max:255'];
                    break;

                case InputType::Tel:
                    $rules[$field] = [$presence, 'string', 'max:32'];
                    break;

                case InputType::Text:
                    $rules[$field] = [$presence, 'string', 'max:255'];
                    break;
            }
        }

        return $rules;
    }

    /**
     * Rules for a subset of questions — for stepped forms that validate one
     * step at a time. Includes the wildcard element rules for checkbox
     * fields, so passing Question::DatingChallenges also returns the
     * `dating_challenges.*` rules. Unknown field names throw, so a typo in a
     * site's step definition fails loudly instead of skipping validation.
     *
     * @return array<string, list<mixed>>
     */
    public function rulesFor(Question|string ...$questions): array
    {
        $fields = array_map(
            fn (Question|string $question): string => $question instanceof Question
                ? $question->value
                : Question::from($question)->value,
            $questions,
        );

        return collect($this->rules())
            ->filter(fn (array $rules, string $key): bool => in_array(Str::before($key, '.'), $fields, true))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->definition->questions() as $question) {
            $field = $question->value;

            $messages["$field.required"] = __('contact-form::validation.required');

            if ($question->inputType() === InputType::Checkbox) {
                $messages["$field.array"] = __('contact-form::validation.array');
                $messages["$field.min"] = __('contact-form::validation.min');
                $messages["$field.*.enum"] = __('contact-form::validation.enum');
                $messages["$field.*.distinct"] = __('contact-form::validation.distinct');
            } elseif ($question->hasFixedAnswers()) {
                $messages["$field.enum"] = __('contact-form::validation.enum');
            }

            if ($question->inputType() === InputType::Email) {
                $messages["$field.email"] = __('contact-form::validation.email');
            }
        }

        return $messages;
    }

    /**
     * Field names shown in validation messages, resolved from the same
     * translations as the rendered questions.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect($this->definition->questions())
            ->mapWithKeys(fn (Question $question): array => [$question->value => $question->label()])
            ->all();
    }
}
