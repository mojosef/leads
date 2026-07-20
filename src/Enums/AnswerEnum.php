<?php

namespace mojosef\Leads\Enums;

/**
 * Contract shared by every fixed answer set. The backed value of each case is
 * the canonical value — a permanent contract between the sites, the package,
 * Duo and analytics. Only the translated label may vary per site.
 */
interface AnswerEnum
{
    /**
     * The question this answer set belongs to. Anchors the translation keys
     * under contact-form::form.{question}.answers.{value}.
     */
    public static function question(): Question;

    /**
     * The visible, site-overridable wording for this answer.
     */
    public function label(): string;
}
