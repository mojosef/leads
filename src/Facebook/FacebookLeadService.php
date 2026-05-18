<?php

namespace mojosef\Leads\Facebook;

use mojosef\Leads\Models\Lead;
use Esign\ConversionsApi\Facades\ConversionsApi;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\UserData;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FacebookLeadService
{
    /**
     * Send a single 'Lead' event to the Facebook Conversions API.
     * The lead's fb_event_id is reused as the CAPI event_id so the browser
     * pixel event (fired on the thank-you page) and this server event
     * deduplicate against each other in Meta.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the CAPI call fails
     */
    public function sendLeadEvent(Lead $lead): array
    {
        $event = (new Event)
            ->setEventName('Lead')
            ->setEventId($lead->fb_event_id)
            ->setEventTime($lead->created_at?->timestamp ?? time())
            ->setEventSourceUrl($lead->previous_url ?? '')
            ->setUserData($this->buildUserData($lead));

        ConversionsApi::addEvent($event);

        $captured = null;
        $error = null;

        try {
            ConversionsApi::sendEvents()
                ->then(
                    function ($response) use (&$captured) {
                        $captured = is_array($response) ? $response : ['ok' => true];
                    },
                    function ($exception) use (&$error) {
                        $error = $exception instanceof Throwable
                            ? $exception
                            : new RuntimeException((string) $exception);
                    }
                )
                ->wait();
        } catch (Throwable $e) {
            $error = $e;
        }

        if ($error !== null) {
            Log::warning('Facebook CAPI Lead event failed', [
                'lead_id' => $lead->id,
                'fb_event_id' => $lead->fb_event_id,
                'error' => $error->getMessage(),
            ]);

            throw new RuntimeException('FB CAPI Lead event failed: '.$error->getMessage(), 0, $error);
        }

        return $captured ?? ['ok' => true];
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
            $data->setPhone($lead->contact);
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
