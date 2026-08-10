<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public vhost enforces a strict Content-Security-Policy
 * (script-src 'self', style-src 'self' 'unsafe-inline', default-src
 * 'self'): the app must ship no inline <script> and reference no
 * third-party origins, or every page is blank in the browser.
 */
class CspComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_contain_no_inline_scripts_or_external_origins(): void
    {
        foreach (['/login', '/'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();

            // Every <script> must load a same-origin file via src=…
            preg_match_all('/<script\b[^>]*>/i', $html, $tags);
            foreach ($tags[0] as $tag) {
                $this->assertMatchesRegularExpression(
                    '/\bsrc="[^"]+"/',
                    $tag,
                    "inline <script> found on {$uri} — blocked by the host CSP: {$tag}",
                );
            }

            // No third-party stylesheets/fonts/scripts (fonts.bunny.net etc.).
            $this->assertDoesNotMatchRegularExpression(
                '/(?:src|href)="https?:\/\/(?!mnemosyne\.shellrent\.com|localhost)/',
                $html,
                "external origin referenced on {$uri} — blocked by the host CSP",
            );
        }
    }

    public function test_bundled_ziggy_routes_are_fresh(): void
    {
        // The route list ships in the bundle (resources/js/ziggy.js).
        // Every named app route must be present — if this fails, run:
        // php artisan ziggy:generate resources/js/ziggy.js
        $generated = file_get_contents(resource_path('js/ziggy.js'));

        $missing = collect(app('router')->getRoutes()->getRoutesByName())
            ->keys()
            ->reject(fn (string $name) => str_starts_with($name, 'horizon.'))
            ->reject(fn (string $name) => str_contains($generated, '"'.$name.'"'))
            ->values();

        $this->assertSame(
            [],
            $missing->all(),
            'Route names missing from resources/js/ziggy.js — regenerate it: '.$missing->implode(', '),
        );
    }
}
