<?php

namespace mojosef\Leads\Enums;

enum MaritalStatus: string implements AnswerEnum
{
    use HasAnswerLabels;

    case NeverMarried = 'never_married';
    case Divorced = 'divorced';
    case Separated = 'separated';
    case Widowed = 'widowed';

    public static function question(): Question
    {
        return Question::MaritalStatus;
    }
}
