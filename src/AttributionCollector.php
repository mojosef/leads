<?php

namespace mojosef\Leads;

use Illuminate\Http\Request;

class AttributionCollector
{
    /**
     * @return array{
     *     attribution: array<string, mixed>,
     *     cookies: array<string, mixed>,
     *     ip_address: ?string,
     *     user_agent: ?string,
     *     previous_url: ?string,
     *     country_code: ?string,
     *     session_id: ?string,
     * }
     */
    public function snapshot(?Request $request = null): array
    {
        $request = $request ?? request();
        $attribution = (array) session('attribution_data', []);
        $cookies = $this->cookies($request, $attribution);

        return [
            'attribution' => $attribution,
            'cookies' => $cookies,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'previous_url' => $this->resolvePreviousUrl($request),
            'country_code' => $request?->server('HTTP_CF_IPCOUNTRY') ?: null,
            'session_id' => $request?->hasSession() ? $request->session()->getId() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @return array<string, mixed>
     */
    private function cookies(?Request $request, array $attribution): array
    {
        $cookies = [
            '_ga' => $request?->cookie('_ga'),
            '_fbp' => $request?->cookie('_fbp'),
            '_fbc' => $request?->cookie('_fbc'),
            'fbclid' => $attribution['fbclid'] ?? $request?->query('fbclid'),
        ];

        if (empty($cookies['_fbc']) && ! empty($cookies['fbclid'])) {
            $cookies['_fbc'] = $this->synthesiseFbc($cookies['fbclid']);
        }

        return array_filter($cookies, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Build a Meta-formatted _fbc cookie value when the cookie itself is absent
     * but we have an fbclid. Format: fb.1.{timestamp_ms}.{fbclid}.
     */
    private function synthesiseFbc(string $fbclid): string
    {
        return sprintf('fb.1.%d.%s', (int) (microtime(true) * 1000), $fbclid);
    }

    private function resolvePreviousUrl(?Request $request): ?string
    {
        if ($request && method_exists($request, 'header') && $referer = $request->header('referer')) {
            return $referer;
        }

        return url()->previous() ?: null;
    }
}
