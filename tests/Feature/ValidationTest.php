<?php

use Illuminate\Validation\ValidationException;
use mojosef\Leads\ContactForm\FormValidator;
use mojosef\Leads\Enums\Question;

function validContactSubmission(array $overrides = []): array
{
    return array_merge([
        'age_bracket' => 'age_30_39',
        'town' => 'Harrogate',
        'occupation' => 'Architect',
        'marital_status' => 'divorced',
        'search_goal' => 'marriage',
        'dating_challenges' => ['limited_time', 'poor_match_quality'],
        'meet_timeline' => 'within_6_months',
        'investment_range' => 'gbp_4000_7999',
        'support_level' => ['expert_advice'],
        'first_name' => 'Alex',
        'email' => 'alex@example.com',
        'phone_number' => '+44 7700 900123',
    ], $overrides);
}

it('passes a submission of valid canonical values', function () {
    $validated = app(FormValidator::class)->validate(validContactSubmission());

    expect($validated['age_bracket'])->toBe('age_30_39')
        ->and($validated['dating_challenges'])->toBe(['limited_time', 'poor_match_quality']);
});

it('rejects unknown answer values', function (string $field, mixed $value) {
    app(FormValidator::class)->validate(validContactSubmission([$field => $value]));
})->throws(ValidationException::class)->with([
    'unknown age bracket' => ['age_bracket', 'age_25_29'],
    'translated label instead of value' => ['age_bracket', '30–39'],
    'value from a different enum' => ['marital_status', 'age_30_39'],
    'unknown checkbox value' => ['dating_challenges', ['limited_time', 'too_picky']],
]);

it('rejects a checkbox answer submitted as a string', function () {
    app(FormValidator::class)->validate(validContactSubmission([
        'dating_challenges' => 'limited_time',
    ]));
})->throws(ValidationException::class);

it('rejects duplicate checkbox answers', function () {
    app(FormValidator::class)->validate(validContactSubmission([
        'dating_challenges' => ['limited_time', 'limited_time'],
    ]));
})->throws(ValidationException::class);

it('rejects a missing required answer', function () {
    $data = validContactSubmission();
    unset($data['support_level']);

    app(FormValidator::class)->validate($data);
})->throws(ValidationException::class);

it('accepts null for an optional question while still accepting unsure as a real value', function () {
    config()->set('leads.contact_form.questions.support_level.required', false);

    $validator = app(FormValidator::class);

    $withNull = $validator->validate(validContactSubmission(['support_level' => null]));
    $withUnsure = $validator->validate(validContactSubmission(['support_level' => ['unsure']]));

    expect($withNull['support_level'])->toBeNull()
        ->and($withUnsure['support_level'])->toBe(['unsure']);
});

it('does not validate disabled questions', function () {
    config()->set('leads.contact_form.questions.investment_range.enabled', false);

    $data = validContactSubmission();
    unset($data['investment_range']);

    $validated = app(FormValidator::class)->validate($data);

    expect($validated)->not->toHaveKey('investment_range');
});

it('returns step-scoped rules including checkbox element rules', function () {
    $rules = app(FormValidator::class)->rulesFor('search_goal', 'dating_challenges', 'meet_timeline');

    expect(array_keys($rules))->toEqualCanonicalizing([
        'search_goal',
        'dating_challenges',
        'dating_challenges.*',
        'meet_timeline',
    ]);
});

it('accepts Question cases as well as field names for step-scoped rules', function () {
    $rules = app(FormValidator::class)->rulesFor(Question::AgeBracket, 'town');

    expect(array_keys($rules))->toEqualCanonicalizing(['age_bracket', 'town']);
});

it('throws on an unknown field name in step-scoped rules', function () {
    app(FormValidator::class)->rulesFor('dating_experience');
})->throws(ValueError::class);

it('resolves validation messages through the contact-form namespace', function () {
    try {
        app(FormValidator::class)->validate(validContactSubmission(['age_bracket' => 'nope']));
        $this->fail('Expected validation to fail.');
    } catch (ValidationException $e) {
        expect($e->errors()['age_bracket'][0])
            ->toContain('Please choose one of the provided options')
            ->toContain('How old are you?');
    }
});
