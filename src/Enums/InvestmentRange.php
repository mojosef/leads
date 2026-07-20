<?php

namespace mojosef\Leads\Enums;

enum InvestmentRange: string implements AnswerEnum
{
    use HasAnswerLabels;

    case UnderGbp4000 = 'gbp_under_4000';
    case Gbp4000To7999 = 'gbp_4000_7999';
    case Gbp8000To11999 = 'gbp_8000_11999';
    case Gbp12000To16000 = 'gbp_12000_16000';
    case DiscussOptionsFirst = 'discuss_options_first';

    public static function question(): Question
    {
        return Question::InvestmentRange;
    }
}
