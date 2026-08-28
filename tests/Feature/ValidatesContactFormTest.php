<?php

use Illuminate\Support\Facades\Log;
use mojosef\Leads\ContactForm\FormValidator;
use mojosef\Leads\ContactForm\ValidatesContactForm;
use mojosef\Leads\Enums\Question;

function fakeContactForm(): object
{
    return new class
    {
        use ValidatesContactForm;

        public ?string $age_bracket = null;

        public ?string $town = null;

        public array $dating_challenges = [];

        public ?string $first_name = null;

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

it('returns a mapped CRM payload from validatedCrmPayload', function () {
    $form = new class
    {
        use ValidatesContactForm;

        public function validate($rules = null, $messages = [], $attributes = []): array
        {
            return [
                'first_name' => 'Joe',
                'phone_number' => '07903042428',
                'age_bracket' => 'age_30_39',
            ];
        }
    };

    expect($form->validatedCrmPayload())->toBe([
        'form_schema_version' => 2,
        'age_bracket' => 'age_30_39',
        'fname' => 'Joe',
        'contact' => '07903042428',
    ]);
});

it('throws for an undefined step instead of silently passing', function () {
    fakeContactForm()->validateStep(99);
})->throws(LogicException::class, 'No contact-form fields defined for step [99]');

it('drops rules for fields the form does not declare as properties, and logs the gap', function () {
    Log::shouldReceive('debug')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => in_array('age_bracket', $context['fields'], true)
            && ! in_array('first_name', $context['fields'], true));

    $form = new class
    {
        use ValidatesContactForm;

        public ?string $first_name = null;

        public ?string $email = null;

        public function validate($rules = null, $messages = [], $attributes = []): array
        {
            return [];
        }
    };

    $rules = $form->rules();

    expect($rules)->toHaveKeys(['first_name', 'email'])
        ->not->toHaveKey('age_bracket')
        ->not->toHaveKey('dating_challenges')
        ->not->toHaveKey('dating_challenges.*');
});

it('keeps checkbox wildcard rules when the checkbox property is declared', function () {
    $form = new class
    {
        use ValidatesContactForm;

        public array $dating_challenges = [];

        public function validate($rules = null, $messages = [], $attributes = []): array
        {
            return [];
        }
    };

    expect($form->rules())->toHaveKeys(['dating_challenges', 'dating_challenges.*']);
});

it('drops undeclared step fields instead of throwing at validation time', function () {
    $form = new class
    {
        use ValidatesContactForm;

        public ?string $town = null;

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

    expect(array_keys($form->validateCalledWith['rules']))->toBe(['town']);
});

it('reads step fields from a stepFields property when the method is not overridden', function () {
    $form = new class
    {
        use ValidatesContactForm;

        public ?string $age_bracket = null;

        public ?string $town = null;

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
