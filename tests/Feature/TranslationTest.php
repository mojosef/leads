<?php

use Illuminate\Support\Facades\Lang;
use mojosef\Leads\Enums\InvestmentRange;
use mojosef\Leads\Enums\Question;

it('has a default translation for every question', function () {
    foreach (Question::cases() as $question) {
        expect(Lang::has("contact-form::form.{$question->value}.question"))
            ->toBeTrue("Missing question translation for {$question->value}")
            ->and($question->label())->not->toBe("contact-form::form.{$question->value}.question");
    }
});

it('has a default translation for every answer enum case', function () {
    foreach (Question::cases() as $question) {
        $enum = $question->answerEnum();

        if ($enum === null) {
            continue;
        }

        foreach ($enum::cases() as $answer) {
            $key = "contact-form::form.{$question->value}.answers.{$answer->value}";

            expect(Lang::has($key))->toBeTrue("Missing answer translation for $key")
                ->and($answer->label())->toBeString()->not->toBe($key);
        }
    }
});

it('renders UTF-8 punctuation in labels correctly', function () {
    expect(InvestmentRange::ReadyToInvest->label())->toBe('I’m ready to invest in the right membership')
        ->and(InvestmentRange::UnderstandOptionsFirst->label())->toBe('I’d like to understand the options before committing')
        ->and(InvestmentRange::ExploringNotReady->label())->toBe('I’m exploring and not ready to invest yet');
});

it('has a validation message for every rule the validator references', function () {
    foreach (['required', 'enum', 'array', 'min', 'distinct', 'email'] as $key) {
        expect(Lang::has("contact-form::validation.$key"))->toBeTrue("Missing validation translation for $key");
    }
});
