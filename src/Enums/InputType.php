<?php

namespace mojosef\Leads\Enums;

enum InputType: string
{
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Text = 'text';
    case Email = 'email';
    case Tel = 'tel';
}
