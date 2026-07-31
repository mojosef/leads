<?php

namespace mojosef\Leads\ContactForm;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        return $this->rulesForDeclaredProperties(
            array_merge_recursive(app(FormValidator::class)->rules(), $this->additionalRules()),
        );
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

        $rules = $this->rulesForDeclaredProperties(app(FormValidator::class)->rulesFor(...$fields));

        $this->validate($rules, $this->messages(), $this->validationAttributes());
    }

    /**
     * Validate the whole form and return the canonical CRM payload — the
     * array to hand to LeadPipeline::complete().
     * Always use this over passing $this->validate() to the pipeline
     * directly: it applies the CRM property mapping (first_name => fname,
     * phone_number => contact) and stamps form_schema_version.
     *
     * @return array<string, mixed>
     */
    public function validatedCrmPayload(): array
    {
        return app(CrmMapper::class)->map($this->validate());
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
     * Drop rules for fields the host class does not declare as a property.
     * A form's declared properties define which of the site's questions it
     * asks — forms capturing only a subset are expected, and Livewire throws
     * when asked to validate a property that doesn't exist. Drops are logged
     * at debug level for diagnosing a field that unexpectedly isn't
     * validating.
     *
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, list<mixed>>
     */
    protected function rulesForDeclaredProperties(array $rules): array
    {
        [$kept, $dropped] = collect($rules)->partition(
            fn (array $fieldRules, string $key): bool => property_exists($this, Str::before($key, '.')),
        );

        if ($dropped->isNotEmpty()) {
            Log::debug(
                'Contact-form validation rules dropped for fields with no matching form property.',
                ['form' => static::class, 'fields' => $dropped->keys()->all()],
            );
        }

        return $kept->all();
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
