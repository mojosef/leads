<?php

namespace mojosef\Leads\ContactForm;

use LogicException;
use mojosef\Leads\Enums\Question;

/**
 * Drop-in validation wiring for Livewire Form objects (or components) that
 * capture the shared contact form. Sources rules, messages, and attribute
 * names from the package's FormValidator so every site validates against
 * the same canonical values, while the host class keeps full control of
 * markup and flow.
 *
 * For stepped forms, override stepFields() and call validateStep($n).
 *
 * The host class must provide a validate() method (Livewire's Form and
 * Component both do).
 */
trait ValidatesContactForm
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge_recursive(app(FormValidator::class)->rules(), $this->additionalRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return app(FormValidator::class)->messages();
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return app(FormValidator::class)->attributes();
    }

    /**
     * Validate a single step of a multi-step form against the package rules
     * for that step's fields (including checkbox element rules). Throws
     * rather than silently passing when the step has no fields defined —
     * a misconfigured step must never let unvalidated data through.
     */
    public function validateStep(int $step): void
    {
        $fields = $this->stepFields()[$step] ?? [];

        if ($fields === []) {
            throw new LogicException(
                "No contact-form fields defined for step [$step]. Define them in stepFields() or a \$stepFields property."
            );
        }

        $rules = app(FormValidator::class)->rulesFor(...$fields);

        $this->validate($rules, $this->messages(), $this->validationAttributes());
    }

    /**
     * Which canonical fields belong to each step. Override this method in
     * stepped forms — or simply declare a `protected array $stepFields`
     * property, which is picked up automatically. Single-step forms can
     * ignore both.
     *
     * @return array<int, list<Question|string>>
     */
    protected function stepFields(): array
    {
        return property_exists($this, 'stepFields') ? (array) $this->stepFields : [];
    }

    /**
     * Extra site-level rules merged onto the package rules — e.g.
     * ['first_name' => ['min:3']]. Use only to tighten free-text fields;
     * the canonical enum rules always remain in force.
     *
     * @return array<string, list<mixed>>
     */
    protected function additionalRules(): array
    {
        return [];
    }
}
