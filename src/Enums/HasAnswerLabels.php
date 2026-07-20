<?php

namespace mojosef\Leads\Enums;

/**
 * Default AnswerEnum::label() implementation: resolve the visible wording
 * from the contact-form translation namespace so consuming sites can
 * override it in lang/vendor/contact-form/{locale}/form.php without ever
 * touching the canonical backed value.
 */
trait HasAnswerLabels
{
    public function label(): string
    {
        return __('contact-form::form.'.static::question()->value.'.answers.'.$this->value);
    }
}
