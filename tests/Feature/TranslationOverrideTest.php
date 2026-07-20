<?php

use Illuminate\Support\Facades\File;
use mojosef\Leads\ContactForm\FormDefinition;
use mojosef\Leads\Enums\AgeBracket;
use mojosef\Leads\Enums\MaritalStatus;
use mojosef\Leads\Enums\Question;

afterEach(function () {
    File::deleteDirectory($this->app->langPath('vendor/contact-form'));
});

function writeVendorOverride(array $lines): void
{
    $path = app()->langPath('vendor/contact-form/en');
    File::ensureDirectoryExists($path);
    File::put($path.'/form.php', '<?php return '.var_export($lines, true).';');
}

it('lets a site override question wording via vendor translations', function () {
    writeVendorOverride([
        'age_bracket' => [
            'question' => 'Which age range are you in?',
        ],
    ]);

    expect(Question::AgeBracket->label())->toBe('Which age range are you in?')
        // Untouched questions keep the package defaults.
        ->and(Question::MaritalStatus->label())->toBe('What is your marital status?');
});

it('lets a site override answer wording via vendor translations', function () {
    writeVendorOverride([
        'age_bracket' => [
            'answers' => [
                'age_under_30' => 'I’m under 30',
                'age_30_39' => 'Between 30 and 39',
            ],
        ],
    ]);

    expect(AgeBracket::Under30->label())->toBe('I’m under 30')
        ->and(AgeBracket::Age30To39->label())->toBe('Between 30 and 39')
        // Answers the site did not override keep the package defaults.
        ->and(AgeBracket::Age40To49->label())->toBe('40–49')
        ->and(MaritalStatus::Divorced->label())->toBe('Divorced');
});

it('keeps canonical values untouched when wording is overridden', function () {
    writeVendorOverride([
        'age_bracket' => [
            'question' => 'Which age range are you in?',
            'answers' => [
                'age_under_30' => 'I’m under 30',
                'age_30_39' => 'Between 30 and 39',
            ],
        ],
    ]);

    expect(Question::AgeBracket->value)->toBe('age_bracket')
        ->and(AgeBracket::Under30->value)->toBe('age_under_30')
        ->and(AgeBracket::Age30To39->value)->toBe('age_30_39');
});

it('omits disabled questions and honours configured display order', function () {
    config()->set('leads.contact_form.questions.town.enabled', false);
    config()->set('leads.contact_form.questions.email.order', 1);

    $questions = app(FormDefinition::class)->questions();

    expect($questions)->not->toContain(Question::Town)
        ->and($questions[0])->toBe(Question::Email);
});
