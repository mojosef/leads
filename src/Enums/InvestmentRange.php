<?php

namespace mojosef\Leads\Enums;

enum InvestmentRange: string implements AnswerEnum
{
    use HasAnswerLabels;

    case ReadyToInvest = 'ready_to_invest';
    case UnderstandOptionsFirst = 'understand_options_first';
    case ExploringNotReady = 'exploring_not_ready';

    public static function question(): Question
    {
        return Question::InvestmentRange;
    }
}
