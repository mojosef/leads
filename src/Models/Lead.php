<?php

namespace mojosef\Leads\Models;

use mojosef\Leads\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Builder;
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

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isFacebookEligible(): bool
    {
        if ($this->fb_eligible) {
            return true;
        }

        if (! empty($this->cookies['fbclid'] ?? null)) {
            return true;
        }

        $fbSourceIds = (array) config('leads.facebook.lead_source_ids', []);

        return $this->lead_source_id !== null && in_array((int) $this->lead_source_id, $fbSourceIds, true);
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
     * Build the outbound payload for the Duo CRM /lead/create endpoint.
     * Mirrors the legacy form shape so Duo's attribution dashboards keep working.
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
        $urlParameters['facebook_fbp'] = $cookies['_fbp'] ?? ($urlParameters['facebook_fbp'] ?? '');
        $urlParameters['facebook_fbc'] = $cookies['_fbc'] ?? ($urlParameters['facebook_fbc'] ?? '');

        return array_filter([
            'prospect_queue_id' => $this->prospect_queue_id,
            'office' => $this->office_id,
            'fname' => $this->fname,
            'lname' => $this->lname,
            'email' => $this->email,
            'contact' => $this->contact,
            'message' => $payload['message'] ?? null,
            'page_referrer' => $this->previous_url,
            'contact_time' => $payload['contact_time'] ?? null,
            'town' => $this->town,
            'occupation' => $payload['occupation'] ?? null,
            'lead_source_id' => $this->lead_source_id,
            'location_header' => $this->country_code,
            'url_parameters' => $urlParameters,
            'attribution' => $attribution,
            'external_token' => $this->id,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
