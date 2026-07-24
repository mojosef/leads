<?php

namespace mojosef\Leads\Enums;

enum SupportLevel: string implements AnswerEnum
{
    use HasAnswerLabels;

    case ProfessionalHandpickedIntroductions = 'professional_handpicked_introductions';
    case SafeSecureVettedDatabase = 'safe_secure_vetted_database';
    case ExpertAdvice = 'expert_advice';
    case Unsure = 'unsure';

    public static function question(): Question
    {
        return Question::SupportLevel;
    }
}
