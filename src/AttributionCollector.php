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
            '_ga' => $this->rawCookie($request, '_ga'),
            '_fbp' => $this->rawCookie($request, '_fbp'),
            '_fbc' => $this->rawCookie($request, '_fbc'),
            'fbclid' => $attribution['fbclid'] ?? $request?->query('fbclid'),
            '_gcl_aw' => $this->rawCookie($request, '_gcl_aw'),
            'gclid' => $attribution['gclid'] ?? $request?->query('gclid'),
        ];

        if (empty($cookies['_fbc']) && ! empty($cookies['fbclid'])) {
            $cookies['_fbc'] = $this->synthesiseFbc($cookies['fbclid']);
        }

        return array_filter($cookies, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Read a third-party analytics cookie, falling back to the raw request
     * header when Laravel's EncryptCookies middleware has stripped it.
     *
     * These cookies (_ga, _fbp, _fbc, _gcl_aw) are written by gtag / the Meta
     * pixel in the browser, so they are never Laravel-encrypted. If the
     * consuming app hasn't excluded them, EncryptCookies fails to decrypt them
     * and nulls them out of $request->cookies. PHP's $_COOKIE superglobal is
     * populated straight from the Cookie header and that middleware never
     * touches it, so it still holds the real value.
     */
    private function rawCookie(?Request $request, string $name): ?string
    {
        $value = $request?->cookie($name);

        if (($value === null || $value === '') && isset($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
            $value = $_COOKIE[$name];
        }

        return is_string($value) && $value !== '' ? $value : null;
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
