<?php

namespace mojosef\Leads\Models;

use mojosef\Leads\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class Lead extends Model
{
    use HasUlids;

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
            'fb_response' => 'array',
            'fb_eligible' => 'boolean',
            'sent_to_duo_at' => 'datetime',
            'fb_synced_at' => 'datetime',
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
     * Whether this lead should fire a Facebook 'Lead' event (browser pixel and
     * server CAPI). True only when the lead carried Facebook click attribution
     * at creation; the value is frozen on the row so every app agrees.
     */
    public function isFacebookEligible(): bool
    {
        return (bool) $this->fb_eligible;
    }

    public function markSending(): void
    {
        $this->forceFill(['status' => self::STATUS_SENDING])->save();
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    public function markSent(int $httpStatus, array $responseBody): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'sent_to_duo_at' => now(),
            'duo_http_status' => $httpStatus,
            'duo_response_body' => $responseBody,
            'duo_response_id' => $responseBody['id'] ?? $responseBody['lead_id'] ?? null,
        ])->save();
    }

    public function markFailed(Throwable $e): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'attempts' => $this->attempts + 1,
            'last_error_at' => now(),
            'last_error' => mb_substr($e->getMessage(), 0, 5000),
        ])->save();
    }

    public function incrementAttempts(): void
    {
        $this->forceFill([
            'attempts' => $this->attempts + 1,
            'last_error_at' => now(),
        ])->save();
    }

    public function markFbSynced(array $response): void
    {
        $this->forceFill([
            'fb_synced_at' => now(),
            'fb_response' => $response,
        ])->save();
    }

    /**
     * Record a failed CAPI attempt without setting fb_synced_at, so the lead
     * stays in the resend pool and an operator can inspect why it failed.
     */
    public function markFbFailed(Throwable $e): void
    {
        $this->forceFill([
            'fb_response' => [
                'error' => mb_substr($e->getMessage(), 0, 5000),
                'failed_at' => now()->toIso8601String(),
            ],
        ])->save();
    }

    public function hasCompletedSection(string $section): bool
    {
        return ! empty(($this->payload ?? [])[$section.'_completed_at']);
    }

    /**
     * Build the outbound payload for the Duo CRM /lead/create endpoint.
     * Merges the form's raw payload (so custom fields like dob/gender/
     * accepts_email_marketing reach Duo unchanged) with the structured
     * top-level columns and cookie-derived url_parameters.
     *
     * Forms can override `external_token` by including it in their payload —
     * useful for legacy flows like the questionnaire that key off an HMAC
     * token. By default the Lead ULID is sent.
     *
     * @return array<string, mixed>
     */
    public function buildDuoPayload(): array
    {
        $payload = $this->payload ?? [];
        $cookies = $this->cookies ?? [];
        $attribution = $this->attribution ?? [];

        $urlParameters = $payload['url_parameters'] ?? [];
        $urlParameters['google_client_id'] = $cookies['_ga'] ?? ($urlParameters['google_client_id'] ?? '');
        $urlParameters['google_gclid'] = $cookies['gclid'] ?? ($urlParameters['google_gclid'] ?? '');
        $urlParameters['facebook_fbp'] = $cookies['_fbp'] ?? ($urlParameters['facebook_fbp'] ?? '');
        $urlParameters['facebook_fbc'] = $cookies['_fbc'] ?? ($urlParameters['facebook_fbc'] ?? '');

        $structured = array_filter([
            'office' => $this->office_id,
            'fname' => $this->fname,
            'lname' => $this->lname,
            'email' => $this->email,
            'contact' => $this->contact,
            'town' => $this->town,
            'page_referrer' => $this->previous_url,
            'location_header' => $this->country_code,
        ], static fn ($value) => $value !== null && $value !== '');

        $rawPayload = $payload;
        unset($rawPayload['url_parameters'], $rawPayload['external_token']);

        return array_merge(
            $rawPayload,
            $structured,
            [
                'url_parameters' => $urlParameters,
                'attribution' => $attribution,
                'external_token' => $payload['external_token'] ?? $this->id,
            ],
        );
    }
}
