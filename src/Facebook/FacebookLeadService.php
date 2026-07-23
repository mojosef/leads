<?php

namespace mojosef\Leads\Facebook;

use mojosef\Leads\Models\Lead;
use FacebookAds\Api;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;
use Illuminate\Support\Facades\Log;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use RuntimeException;
use Throwable;

class FacebookLeadService
{
    /**
     * Send a 'Lead' event to the Facebook Conversions API. Credentials are
     * looked up per Lead site so the admin app can process leads for
     * multiple brands in one process — each call to the FB API uses the
     * pixel + token for that specific site.
     *
     * The Lead's `event_id` is sent as the CAPI event_id, deduplicating
     * against the browser pixel `Lead` event fired on the thank-you page.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException on CAPI failure (caller decides whether to retry)
     */
    public function sendLeadEvent(Lead $lead): array
    {
        $credentials = $this->credentialsForSite($lead->site);

        if ($credentials === null) {
            Log::warning('FacebookLeadService: no credentials for site, skipping CAPI', [
                'site' => $lead->site,
                'lead_id' => $lead->id,
            ]);

            return ['skipped' => true, 'reason' => 'no_credentials'];
        }

        $event = (new Event)
            ->setEventName('Lead')
            ->setEventId($lead->event_id)
            ->setEventTime($lead->created_at?->timestamp ?? time())
            ->setEventSourceUrl($lead->previous_url ?? '')
            ->setActionSource('website')
            ->setUserData($this->buildUserData($lead));

        return $this->dispatchEvent($event, $credentials, $lead);
    }

    /**
     * Look up CAPI credentials for a Lead's site. Falls back to the global
     * esign/laravel-conversions-api config for backwards compatibility with
     * single-tenant setups (the elect-club site currently uses this path
     * until the admin app takes over delivery).
     *
     * @return array{access_token: string, pixel_id: string, test_code: ?string}|null
     */
    public function credentialsForSite(string $site): ?array
    {
        $perSite = config("leads.facebook.sites.$site");

        if (is_array($perSite) && ! empty($perSite['access_token']) && ! empty($perSite['pixel_id'])) {
            return [
                'access_token' => (string) $perSite['access_token'],
                'pixel_id' => (string) $perSite['pixel_id'],
                'test_code' => $perSite['test_code'] ?? null,
            ];
        }

        $globalToken = config('conversions-api.access_token');
        $globalPixel = config('conversions-api.pixel_id');

        if (! empty($globalToken) && ! empty($globalPixel)) {
            return [
                'access_token' => (string) $globalToken,
                'pixel_id' => (string) $globalPixel,
                'test_code' => config('conversions-api.test_code') ?: null,
            ];
        }

        return null;
    }

    /**
     * Execute the CAPI request. Extracted as a protected method so tests
     * can subclass + stub without going over the wire.
     *
     * @param  array{access_token: string, pixel_id: string, test_code: ?string}  $credentials
     * @return array<string, mixed>
     */
    protected function dispatchEvent(Event $event, array $credentials, Lead $lead): array
    {
        try {
            // The SDK keeps a singleton Api with the access token. Re-init
            // before each request so sequential leads for different sites
            // each use their own credentials.
            Api::init(null, null, $credentials['access_token']);

            $request = new EventRequest($credentials['pixel_id']);
            $request->setEvents([$event]);

            if (! empty($credentials['test_code'])) {
                $request->setTestEventCode($credentials['test_code']);
            }

            $response = $request->execute();

            return [
                'events_received' => method_exists($response, 'getEventsReceived')
                    ? $response->getEventsReceived()
                    : null,
                'fbtrace_id' => method_exists($response, 'getFbtraceId')
                    ? $response->getFbtraceId()
                    : null,
            ];
        } catch (Throwable $e) {
            Log::warning('Facebook CAPI Lead event failed', [
                'lead_id' => $lead->id,
                'site' => $lead->site,
                'event_id' => $lead->event_id,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('FB CAPI Lead event failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function buildUserData(Lead $lead): UserData
    {
        $cookies = $lead->cookies ?? [];
        [$first, $last] = $this->splitName((string) $lead->fname, (string) $lead->lname);

        $data = (new UserData)
            ->setClientIpAddress($lead->ip_address ?: null)
            ->setClientUserAgent($lead->user_agent ?: null);

        if ($lead->email) {
            $data->setEmail($lead->email);
        }
        if ($lead->contact) {
            $data->setPhone($this->normalizePhone((string) $lead->contact, $lead->country_code));
        }
        if ($first !== '') {
            $data->setFirstName($first);
        }
        if ($last !== '') {
            $data->setLastName($last);
        }
        if ($lead->town) {
            $data->setCity($lead->town);
        }
        if ($lead->country_code) {
            $data->setCountryCode($lead->country_code);
        }
        if (! empty($cookies['_fbp'])) {
            $data->setFbp($cookies['_fbp']);
        }
        if (! empty($cookies['_fbc'])) {
            $data->setFbc($cookies['_fbc']);
        }

        return $data;
    }

    /**
     * Normalize a phone number to E.164 so Meta can match it against its own
     * country-code-prefixed records. A bare UK "07123456789" hashes to a
     * different value than Meta's "447123456789" and silently fails to match;
     * the FB SDK only strips symbols, it never adds the dialing code.
     *
     * The Lead's Cloudflare country_code is the region hint, falling back to
     * GB (the fleet is UK-centric). Numbers already in international form
     * (+44…) parse correctly regardless of the hint. If the number can't be
     * parsed or isn't valid, we return it unchanged so the SDK still sends a
     * best-effort value rather than dropping the phone signal entirely.
     */
    private function normalizePhone(string $phone, ?string $countryCode): string
    {
        $region = strtoupper($countryCode ?: 'GB');

        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse($phone, $region);

            if ($util->isValidNumber($parsed)) {
                return $util->format($parsed, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
            // Unparseable input — fall through to the raw value.
        }

        return $phone;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fname, string $lname): array
    {
        if ($lname !== '') {
            return [$fname, $lname];
        }

        $parts = preg_split('/\s+/', trim($fname)) ?: [];

        return [
            (string) ($parts[0] ?? ''),
            (string) (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : ''),
        ];
    }
}
