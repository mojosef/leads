<?php

use mojosef\Leads\ContactForm\FormValidator;
use mojosef\Leads\ContactForm\ValidatesContactForm;
use mojosef\Leads\Enums\Question;

function fakeContactForm(): object
{
    return new class
    {
        use ValidatesContactForm;

        /** @var array{rules: mixed, messages: mixed, attributes: mixed}|null */
        public ?array $validateCalledWith = null;

        protected function stepFields(): array
        {
            return [
                1 => ['age_bracket', 'town'],
                2 => [Question::DatingChallenges],
            ];
        }

        protected function additionalRules(): array
        {
            return ['first_name' => ['min:3']];
        }

        public function validate($rules = null, $messages = [], $attributes = []): array
        {
            $this->validateCalledWith = compact('rules', 'messages', 'attributes');

            return [];
        }
    };
}

it('sources rules, messages, and attributes from the package validator', function () {
    $form = fakeContactForm();
    $validator = app(FormValidator::class);

    expect($form->messages())->toBe($validator->messages())
        ->and($form->validationAttributes())->toBe($validator->attributes())
        ->and($form->rules()['age_bracket'])->toEqual($validator->rules()['age_bracket']);
});

it('merges site-level additional rules onto the package rules', function () {
    $rules = fakeContactForm()->rules();

    expect($rules['first_name'])->toContain('min:3')
        ->and($rules['first_name'])->toContain('required');
});

it('validates a step against the package rules for its fields', function () {
    $form = fakeContactForm();

    $form->validateStep(1);

    expect(array_keys($form->validateCalledWith['rules']))
        ->toEqualCanonicalizing(['age_bracket', 'town'])
        ->and($form->validateCalledWith['messages'])->toBe($form->messages());
});

it('includes checkbox element rules when validating a step by Question case', function () {
    $form = fakeContactForm();

    $form->validateStep(2);

    expect(array_keys($form->validateCalledWith['rules']))
        ->toEqualCanonicalizing(['dating_challenges', 'dating_challenges.*']);
});

it('throws for an undefined step instead of silently passing', function () {
    fakeContactForm()->validateStep(99);
})->throws(LogicException::class, 'No contact-form fields defined for step [99]');

it('reads step fields from a stepFields property when the method is not overridden', function () {
    $form = new class
    {
        use ValidatesContactForm;

        public ?array $validateCalledWith = null;

        protected array $stepFields = [
            1 => ['age_bracket', 'town'],
        ];

        public function validate($rules = null, $messages = [], $attributes = []): array
        {
            $this->validateCalledWith = compact('rules', 'messages', 'attributes');

            return [];
        }
    };

    $form->validateStep(1);

    expect(array_keys($form->validateCalledWith['rules']))
        ->toEqualCanonicalizing(['age_bracket', 'town']);
});
