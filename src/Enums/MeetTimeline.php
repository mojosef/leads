<?php

namespace mojosef\Leads\Enums;

enum MeetTimeline: string implements AnswerEnum
{
    use HasAnswerLabels;

    case AsSoonAsPossible = 'as_soon_as_possible';
    case WithinSixMonths = 'within_6_months';
    case WithinTwelveMonths = 'within_12_months';
    case NoSpecificTimeline = 'no_specific_timeline';

    public static function question(): Question
    {
        return Question::MeetTimeline;
    }
}
