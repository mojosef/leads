<?php

namespace mojosef\Leads\Enums;

enum AgeBracket: string implements AnswerEnum
{
    use HasAnswerLabels;

    case Under30 = 'age_under_30';
    case Age30To39 = 'age_30_39';
    case Age40To49 = 'age_40_49';
    case Age50To59 = 'age_50_59';
    case Age60Plus = 'age_60_plus';

    public static function question(): Question
    {
        return Question::AgeBracket;
    }
}
