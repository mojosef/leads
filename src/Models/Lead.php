<?php

namespace mojosef\Leads\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use mojosef\Leads\Models\Scopes\SiteScope;

class Lead extends Model
{
    use HasUlids;

    /**
     * The package only ever writes `draft` and `pending`. Later transitions
     * (`sending` / `sent` / `failed` / `discarded`) belong to Duo, which
     * ingests pending rows straight from the shared database; the constants
     * remain here so fleet apps read the shared schema consistently.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISCARDED = 'discarded';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new SiteScope);
    }

    public function getConnectionName(): ?string
    {
        return config('leads.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attribution' => 'array',
            'cookies' => 'array',
            'duo_response_body' => 'array',
            'fb_eligible' => 'boolean',
            'sent_to_duo_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * @deprecated The column was renamed to `event_id` (the same token now
     * serves as the Meta CAPI event_id and the Google Ads order_id). This
     * shim keeps `$lead->fb_event_id` working while fleet sites migrate.
     */
    protected function fbEventId(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->event_id);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Whether this lead should fire a Facebook 'Lead' event (browser pixel,
     * and the CAPI event Duo sends). True only when the lead carried Facebook
     * click attribution at creation; the value is frozen on the row so every
     * app agrees.
     */
    public function isFacebookEligible(): bool
    {
        return (bool) $this->fb_eligible;
    }

    public function hasCompletedSection(string $section): bool
    {
        return ! empty(($this->payload ?? [])[$section.'_completed_at']);
    }
}
