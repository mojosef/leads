<?php

namespace mojosef\Leads\Enums;

enum SearchGoal: string implements AnswerEnum
{
    use HasAnswerLabels;

    case Marriage = 'marriage';
    case LifePartner = 'life_partner';
    case LongTermRelationship = 'long_term_relationship';
    case Exploring = 'exploring';

    public static function question(): Question
    {
        return Question::SearchGoal;
    }
}
