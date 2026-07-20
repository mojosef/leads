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

it('renders UTF-8 currency labels correctly', function () {
    expect(InvestmentRange::UnderGbp4000->label())->toBe('Under £4,000')
        ->and(InvestmentRange::Gbp4000To7999->label())->toBe('£4,000–£7,999')
        ->and(InvestmentRange::Gbp8000To11999->label())->toBe('£8,000–£11,999')
        ->and(InvestmentRange::Gbp12000To16000->label())->toBe('£12,000–£16,000');
});

it('has a validation message for every rule the validator references', function () {
    foreach (['required', 'enum', 'array', 'min', 'distinct', 'email'] as $key) {
        expect(Lang::has("contact-form::validation.$key"))->toBeTrue("Missing validation translation for $key");
    }
});
