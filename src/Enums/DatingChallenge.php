<?php

namespace mojosef\Leads\Enums;

enum DatingChallenge: string implements AnswerEnum
{
    use HasAnswerLabels;

    case LowQualityAppMatches = 'low_quality_app_matches';
    case LimitedTime = 'limited_time';
    case PoorMatchQuality = 'poor_match_quality';
    case SeekingPersonalisedService = 'seeking_personalised_service';
    case RecentlyDivorcedOrSeparated = 'recently_divorced_or_separated';

    public static function question(): Question
    {
        return Question::DatingChallenges;
    }
}
