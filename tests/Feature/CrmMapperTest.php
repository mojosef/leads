<?php

use Illuminate\Support\Facades\File;
use mojosef\Leads\ContactForm\CrmMapper;
use mojosef\Leads\ContactForm\FormDefinition;
use mojosef\Leads\ContactForm\FormValidator;

afterEach(function () {
    File::deleteDirectory($this->app->langPath('vendor/contact-form'));
});

it('maps validated data to canonical values only', function () {
    $payload = app(CrmMapper::class)->map([
        'age_bracket' => 'age_30_39',
        'town' => 'Harrogate',
        'marital_status' => 'divorced',
        'search_goal' => 'marriage',
        'dating_challenges' => ['limited_time', 'poor_match_quality'],
        'meet_timeline' => 'within_6_months',
        'investment_range' => 'gbp_4000_7999',
        'support_level' => ['unsure'],
        'first_name' => 'Alex',
        'email' => 'alex@example.com',
        'phone_number' => '+44 7700 900123',
    ]);

    expect($payload)->toBe([
        'form_schema_version' => 1,
        'age_bracket' => 'age_30_39',
        'town' => 'Harrogate',
        'marital_status' => 'divorced',
        'search_goal' => 'marriage',
        'dating_challenges' => ['limited_time', 'poor_match_quality'],
        'meet_timeline' => 'within_6_months',
        'investment_range' => 'gbp_4000_7999',
        'support_level' => ['unsure'],
        'fname' => 'Alex',
        'email' => 'alex@example.com',
        'contact' => '+44 7700 900123',
    ]);
});

it('never contains translated display labels', function () {
    $payload = app(CrmMapper::class)->map([
        'age_bracket' => 'age_30_39',
        'investment_range' => 'gbp_4000_7999',
        'dating_challenges' => ['limited_time'],
    ]);

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

    expect($encoded)
        ->not->toContain('30–39')
        ->not->toContain('£')
        ->not->toContain('Limited time');
});

it('rejects values outside the canonical answer set', function () {
    app(CrmMapper::class)->map(['age_bracket' => 'Under 30']);
})->throws(ValueError::class);

it('applies the configured CRM property mapping without touching values', function () {
    config()->set('leads.contact_form.crm_properties.age_bracket', 'age_range');

    $payload = app(CrmMapper::class)->map(['age_bracket' => 'age_30_39']);

    expect($payload)->toHaveKey('age_range', 'age_30_39')
        ->not->toHaveKey('age_bracket');
});

it('stamps the package-owned schema version', function () {
    expect(app(CrmMapper::class)->map([]))->toBe(['form_schema_version' => FormDefinition::SCHEMA_VERSION]);
});

it('applies all defaults when the contact_form config section is absent', function () {
    config()->set('leads.contact_form', null);

    $payload = app(CrmMapper::class)->map(app(FormValidator::class)->validate([
        'age_bracket' => 'age_30_39',
        'town' => 'Harrogate',
        'marital_status' => 'divorced',
        'search_goal' => 'marriage',
        'dating_challenges' => ['limited_time'],
        'meet_timeline' => 'within_6_months',
        'investment_range' => 'gbp_4000_7999',
        'support_level' => ['unsure'],
        'first_name' => 'Alex',
        'email' => 'alex@example.com',
        'phone_number' => '+44 7700 900123',
    ]));

    expect($payload['form_schema_version'])->toBe(1)
        ->and($payload)->toHaveKey('fname', 'Alex')
        ->and($payload)->toHaveKey('contact', '+44 7700 900123')
        ->and($payload)->not->toHaveKey('first_name')
        ->and($payload)->not->toHaveKey('phone_number');
});

it('omits unanswered optional questions instead of coercing them', function () {
    $payload = app(CrmMapper::class)->map([
        'age_bracket' => 'age_30_39',
        'support_level' => null,
    ]);

    expect($payload)->not->toHaveKey('support_level');
});

it('omits disabled questions from the payload', function () {
    config()->set('leads.contact_form.questions.investment_range.enabled', false);

    $payload = app(CrmMapper::class)->map(['investment_range' => 'gbp_4000_7999']);

    expect($payload)->not->toHaveKey('investment_range');
});

it('sends an identical payload from two sites with different branded wording', function () {
    $submission = [
        'age_bracket' => 'age_30_39',
        'town' => 'Harrogate',
        'marital_status' => 'divorced',
        'search_goal' => 'marriage',
        'dating_challenges' => ['limited_time'],
        'meet_timeline' => 'within_6_months',
        'investment_range' => 'gbp_4000_7999',
        'support_level' => ['unsure'],
        'first_name' => 'Alex',
        'email' => 'alex@example.com',
        'phone_number' => '+44 7700 900123',
    ];

    // Site A: package defaults.
    $siteA = app(CrmMapper::class)->map(app(FormValidator::class)->validate($submission));

    // Site B: fully rebranded wording via vendor translation overrides.
    $path = $this->app->langPath('vendor/contact-form/en');
    File::ensureDirectoryExists($path);
    File::put($path.'/form.php', '<?php return '.var_export([
        'age_bracket' => [
            'question' => 'Which age range are you in?',
            'answers' => ['age_30_39' => 'Between 30 and 39'],
        ],
        'investment_range' => [
            'question' => 'What budget suits you best?',
            'answers' => ['gbp_4000_7999' => 'A mid-tier membership'],
        ],
    ], true).';');

    $siteB = app(CrmMapper::class)->map(app(FormValidator::class)->validate($submission));

    expect($siteB)->toBe($siteA)
        ->and($siteB['age_bracket'])->toBe('age_30_39')
        ->and($siteB['investment_range'])->toBe('gbp_4000_7999');
});
