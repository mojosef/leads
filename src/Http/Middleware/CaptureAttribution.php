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

        $attributionData['referrer'] = $request->headers->get('referer');
        $attributionData['landing_page'] = $request->fullUrl();
        $attributionData['timestamp'] = now()->toISOString();
        $attributionData['user_agent'] = $request->userAgent();
        $attributionData['ip_address'] = $request->ip();

        if (! empty($attributionData) && $request->hasSession()) {
            session(['attribution_data' => $attributionData]);
        }
    }
}
