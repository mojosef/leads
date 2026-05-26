<?php

namespace mojosef\Leads\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureAttribution
{
    private const UTM_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    private const CLICK_IDS = [
        'gclid',
        'fbclid',
        'ttclid',
        'msclkid',
    ];

    /**
     * Caps on the catch-all query bucket. The data is user-controlled, so we
     * bound how much of it can be stuffed into the session (and ultimately the
     * lead row) on the first request of a session.
     */
    private const MAX_EXTRA_PARAMS = 25;

    private const MAX_VALUE_LENGTH = 500;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ! session()->has('attribution_data')) {
            $this->captureAttribution($request);
        }

        return $next($request);
    }

    private function captureAttribution(Request $request): void
    {
        $attributionData = [];

        foreach (self::UTM_PARAMS as $param) {
            if ($request->filled($param)) {
                $attributionData[$param] = $request->get($param);
            }
        }

        foreach (self::CLICK_IDS as $clickId) {
            if ($request->filled($clickId)) {
                $attributionData[$clickId] = $request->get($clickId);
            }
        }

        if ($extra = $this->extraQueryParams($request)) {
            $attributionData['query'] = $extra;
        }

        $attributionData['referrer'] = $request->headers->get('referer');
        $attributionData['landing_page'] = $request->fullUrl();
        $attributionData['timestamp'] = now()->toISOString();
        $attributionData['user_agent'] = $request->userAgent();
        $attributionData['ip_address'] = $request->ip();

        if (! empty($attributionData) && $request->hasSession()) {
            session(['attribution_data' => $attributionData]);
        }
    }

    /**
     * Catch-all for any query-string param that isn't already captured as a
     * known UTM tag or click ID — e.g. a "variants" param carrying template
     * data. Kept under its own `query` sub-key so user-supplied params can't
     * clobber the reserved top-level keys (timestamp, ip_address, ...), and
     * capped so a flood of params can't bloat the row.
     *
     * Array-style params (?x[]=a&x[]=b) are skipped to keep the bucket flat
     * and predictable; comma-joined scalars (?variants=a,b) come through fine.
     *
     * @return array<string, scalar>
     */
    private function extraQueryParams(Request $request): array
    {
        $known = array_merge(self::UTM_PARAMS, self::CLICK_IDS);
        $extra = [];

        foreach ($request->query() as $key => $value) {
            if (in_array($key, $known, true) || ! is_scalar($value)) {
                continue;
            }

            $extra[$key] = is_string($value)
                ? mb_substr($value, 0, self::MAX_VALUE_LENGTH)
                : $value;

            if (count($extra) >= self::MAX_EXTRA_PARAMS) {
                break;
            }
        }

        return $extra;
    }
}
