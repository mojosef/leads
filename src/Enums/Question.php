<?php

namespace mojosef\Leads\Enums;

/**
 * Canonical contact-form field names. The backed values are submitted to Duo
 * and referenced by analytics across the fleet — they are a permanent
 * contract and must never change. Visible wording lives in translations.
 */
enum Question: string
{
    case AgeBracket = 'age_bracket';
    case Town = 'town';
    case Occupation = 'occupation';
    case MaritalStatus = 'marital_status';
    case SearchGoal = 'search_goal';
    case DatingChallenges = 'dating_challenges';
    case MeetTimeline = 'meet_timeline';
    case InvestmentRange = 'investment_range';
    case SupportLevel = 'support_level';
    case FirstName = 'first_name';
    case Email = 'email';
    case PhoneNumber = 'phone_number';

    public function inputType(): InputType
    {
        return match ($this) {
            self::AgeBracket,
            self::MaritalStatus,
            self::SearchGoal,
            self::MeetTimeline,
            self::InvestmentRange => InputType::Select,
            self::SupportLevel,
            self::DatingChallenges => InputType::Checkbox,
            self::Town,
            self::Occupation,
            self::FirstName => InputType::Text,
            self::Email => InputType::Email,
            self::PhoneNumber => InputType::Tel,
        };
    }

    /**
     * The fixed answer set for this question, or null for free-text fields.
     *
     * @return class-string<AnswerEnum&\BackedEnum>|null
     */
    public function answerEnum(): ?string
    {
        return match ($this) {
            self::AgeBracket => AgeBracket::class,
            self::MaritalStatus => MaritalStatus::class,
            self::SearchGoal => SearchGoal::class,
            self::DatingChallenges => DatingChallenge::class,
            self::MeetTimeline => MeetTimeline::class,
            self::InvestmentRange => InvestmentRange::class,
            self::SupportLevel => SupportLevel::class,
            default => null,
        };
    }

    public function hasFixedAnswers(): bool
    {
        return $this->answerEnum() !== null;
    }

    /**
     * The visible, site-overridable question wording.
     */
    public function label(): string
    {
        return __("contact-form::form.{$this->value}.question");
    }
}
