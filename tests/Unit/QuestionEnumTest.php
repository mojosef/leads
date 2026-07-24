<?php

use mojosef\Leads\Enums\AgeBracket;
use mojosef\Leads\Enums\AnswerEnum;
use mojosef\Leads\Enums\DatingChallenge;
use mojosef\Leads\Enums\InputType;
use mojosef\Leads\Enums\InvestmentRange;
use mojosef\Leads\Enums\MaritalStatus;
use mojosef\Leads\Enums\MeetTimeline;
use mojosef\Leads\Enums\Question;
use mojosef\Leads\Enums\SearchGoal;
use mojosef\Leads\Enums\SupportLevel;

it('has a unique canonical value for every question', function () {
    $values = array_map(fn (Question $question) => $question->value, Question::cases());

    expect($values)->toBe(array_values(array_unique($values)));
});

it('exposes exactly the canonical field names', function () {
    $values = array_map(fn (Question $question) => $question->value, Question::cases());

    expect($values)->toEqualCanonicalizing([
        'age_bracket',
        'town',
        'marital_status',
        'search_goal',
        'dating_challenges',
        'meet_timeline',
        'investment_range',
        'support_level',
        'first_name',
        'email',
        'phone_number',
    ]);
});

it('provides an answer enum for every fixed-choice question', function () {
    foreach (Question::cases() as $question) {
        $fixedChoice = in_array($question->inputType(), [InputType::Select, InputType::Checkbox], true);

        if ($fixedChoice) {
            expect($question->answerEnum())
                ->not->toBeNull()
                ->and(is_subclass_of($question->answerEnum(), AnswerEnum::class))->toBeTrue()
                ->and($question->answerEnum()::question())->toBe($question);
        } else {
            expect($question->answerEnum())->toBeNull();
        }
    }
});

it('exposes the canonical answer values on each answer enum', function (string $enum, array $expected) {
    $values = array_map(fn (BackedEnum $case) => $case->value, $enum::cases());

    expect($values)->toBe($expected);
})->with([
    'age bracket' => [AgeBracket::class, ['age_under_30', 'age_30_39', 'age_40_49', 'age_50_59', 'age_60_plus']],
    'marital status' => [MaritalStatus::class, ['never_married', 'divorced', 'separated', 'widowed']],
    'search goal' => [SearchGoal::class, ['marriage', 'life_partner', 'long_term_relationship', 'exploring']],
    'dating challenge' => [DatingChallenge::class, ['low_quality_app_matches', 'limited_time', 'poor_match_quality', 'seeking_personalised_service', 'recently_divorced_or_separated']],
    'meet timeline' => [MeetTimeline::class, ['as_soon_as_possible', 'within_6_months', 'within_12_months', 'no_specific_timeline']],
    'investment range' => [InvestmentRange::class, ['gbp_under_4000', 'gbp_4000_7999', 'gbp_8000_11999', 'gbp_12000_16000', 'discuss_options_first']],
    'support level' => [SupportLevel::class, ['professional_handpicked_introductions', 'safe_secure_vetted_database', 'expert_advice', 'unsure']],
]);

it('pins the input type of every question, so a shared match arm cannot silently flip an unrelated field', function () {
    $inputTypes = array_map(
        fn (Question $question): string => $question->inputType()->name,
        Question::cases(),
    );

    expect(array_combine(
        array_map(fn (Question $question) => $question->value, Question::cases()),
        $inputTypes,
    ))->toBe([
        'age_bracket' => 'Text',
        'town' => 'Text',
        'occupation' => 'Text',
        'marital_status' => 'Select',
        'search_goal' => 'Select',
        'dating_challenges' => 'Checkbox',
        'meet_timeline' => 'Select',
        'investment_range' => 'Select',
        'support_level' => 'Checkbox',
        'first_name' => 'Text',
        'email' => 'Email',
        'phone_number' => 'Tel',
    ]);
});
