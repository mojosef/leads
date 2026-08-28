<?php

namespace mojosef\Leads\ContactForm;

use mojosef\Leads\Enums\Question;

/**
 * Resolves the site's composition of the shared contact form from
 * config('leads.contact_form'). Sites control presentation concerns only —
 * whether a question is shown, its display order, whether it is required,
 * and the CRM property name it maps to. Canonical field names and answer
 * values are fixed by the enums and cannot be redefined here.
 */
class FormDefinition
{
    /**
     * Version of the form's business structure, stamped on every CRM payload.
     * Owned by the package — bumped here when the canonical structure
     * changes, never set per site.
     */
    public const SCHEMA_VERSION = 2;

    /**
     * Fields whose Duo property name differs from the canonical field name.
     * Lives in code (not the shipped config) so a site publishing a partial
     * contact_form config section can never accidentally drop the mapping —
     * Laravel's config merge is shallow, defaults here are always present.
     */
    private const CRM_PROPERTY_DEFAULTS = [
        'first_name' => 'fname',
        'phone_number' => 'contact',
    ];

    /**
     * Enabled questions in display order.
     *
     * @return list<Question>
     */
    public function questions(): array
    {
        return collect(Question::cases())
            ->filter(fn (Question $question): bool => $this->isEnabled($question))
            ->sortBy(fn (Question $question): int => $this->order($question))
            ->values()
            ->all();
    }

    public function isEnabled(Question $question): bool
    {
        return (bool) config("leads.contact_form.questions.{$question->value}.enabled", true);
    }

    public function isRequired(Question $question): bool
    {
        return (bool) config("leads.contact_form.questions.{$question->value}.required", true);
    }

    /**
     * Display order. Falls back to the enum declaration order (10, 20, …) so
     * a site only needs to set `order` on the questions it wants to move,
     * with room to slot a question before the first default.
     */
    public function order(Question $question): int
    {
        $default = ((int) array_search($question, Question::cases(), true) + 1) * 10;

        return (int) config("leads.contact_form.questions.{$question->value}.order", $default);
    }

    /**
     * The property name this field is sent to the CRM under. Defaults to the
     * canonical field name; config maps it where Duo's name differs.
     */
    public function crmProperty(Question $question): string
    {
        return (string) config(
            "leads.contact_form.crm_properties.{$question->value}",
            self::CRM_PROPERTY_DEFAULTS[$question->value] ?? $question->value,
        );
    }

    public function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }
}
