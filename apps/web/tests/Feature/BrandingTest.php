<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product branding regressions: no Laravel Starter Kit product branding
 * in user-visible chrome; Mnemosyne mark/favicon assets exist and are
 * referenced.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_starter_kit_branding_in_frontend_sources_or_build(): void
    {
        $sources = array_merge(
            glob(resource_path('js/components/*.tsx')) ?: [],
            glob(resource_path('js/layouts/**/*.tsx')) ?: [],
            glob(resource_path('js/pages/*.tsx')) ?: [],
            [resource_path('js/app.tsx'), resource_path('views/app.blade.php')],
        );

        foreach ($sources as $file) {
            $this->assertStringNotContainsString(
                'Laravel Starter Kit',
                (string) file_get_contents($file),
                basename($file).' must not carry starter-kit product branding',
            );
        }

        foreach (glob(public_path('build/assets/*.js')) ?: [] as $bundle) {
            $this->assertStringNotContainsString('Laravel Starter Kit', (string) file_get_contents($bundle), basename($bundle));
        }
    }

    public function test_favicon_assets_exist_and_are_referenced(): void
    {
        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertStringContainsString('<svg', (string) file_get_contents(public_path('favicon.svg')));

        $blade = (string) file_get_contents(resource_path('views/app.blade.php'));
        $this->assertStringContainsString('favicon.svg', $blade);
        $this->assertStringContainsString('Mnemosyne', $blade);
        $this->assertStringNotContainsString("config('app.name', 'Laravel')", $blade);
    }

    public function test_app_mark_component_is_original_and_wordmark_is_mnemosyne(): void
    {
        $logo = (string) file_get_contents(resource_path('js/components/app-logo.tsx'));
        $this->assertStringContainsString('Mnemosyne', $logo);

        $icon = (string) file_get_contents(resource_path('js/components/app-logo-icon.tsx'));
        // The Laravel starter icon path signature must be gone.
        $this->assertStringNotContainsString('M17.2 5.63325L8.6 0.855469', $icon);
        $this->assertStringContainsString('<svg', $icon);
    }

    public function test_auth_and_app_pages_render_with_mnemosyne_title(): void
    {
        config(['app.name' => 'Mnemosyne']);

        $this->get('/login')->assertOk()->assertSee('Mnemosyne');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/search')->assertOk();
    }
}
