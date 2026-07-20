<?php

namespace mojosef\Leads\Enums;

enum SupportLevel: string implements AnswerEnum
{
    use HasAnswerLabels;

    case PersonalisedGuidance = 'personalised_guidance';
    case DedicatedProactiveSearch = 'dedicated_proactive_search';
    case HighestSupport = 'highest_support';
    case Unsure = 'unsure';

    public static function question(): Question
    {
        return Question::SupportLevel;
    }
}
