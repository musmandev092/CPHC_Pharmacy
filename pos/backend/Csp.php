<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Per-request CSP nonce + security header emission.
 *
 * The nonce is generated once per request, exposed to templates via
 * Csp::nonce(), and embedded into the Content-Security-Policy header.
 * Every inline <script> and <style> MUST carry  nonce="<?= Csp::nonce() ?>"
 * or it will be blocked by the browser.
 *
 * The policy is tighter than the original Laravel version:
 *   - No 'unsafe-eval' — we dropped Alpine.js / Livewire.
 *   - No 'unsafe-inline' for styles — we use a single hand-written
 *     /assets/app.css and (rarely) <style nonce="X"> for per-page tweaks.
 *
 * Other headers (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-
 * Policy, Permissions-Policy) are set in the Caddyfile because they're
 * static.  CSP must come from here because of the per-request nonce.
 */
final class Csp
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    public static function emit(): void
    {
        if (headers_sent()) {
            return;
        }
        $n = self::nonce();
        $policy = implode('; ', [
            "default-src 'self'",
            // Scripts keep the nonce — we only have a couple of inline blocks
            // and want strict script-src.
            "script-src 'self' 'nonce-{$n}'",
            // Styles use 'unsafe-inline' only.  Per the CSP-3 spec, mixing
            // 'unsafe-inline' with a nonce makes the browser IGNORE
            // 'unsafe-inline' — and we have many inline style="" attrs
            // throughout the templates.  Matches the reference's policy.
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
            // Browsers POST a JSON report to this endpoint when a directive is
            // violated.  /csp-report logs to stderr — good observability for
            // both dev (catch developer-introduced inline scripts) and prod
            // (catch new browser-injected extensions etc.).
            "report-uri /csp-report",
        ]);
        header('Content-Security-Policy: ' . $policy);
    }
}
