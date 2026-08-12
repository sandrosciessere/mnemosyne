<?php

namespace Tests\Feature\Answers;

use App\Models\User;
use App\Services\Answers\GroundedAnswerOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

/**
 * Hostile source/model content: the application NEVER converts book or
 * model text to HTML. Server side it stays raw data (JSON); client side
 * every surface renders plain React text nodes.
 */
class HostileContentRenderingTest extends TestCase
{
    use BuildsAnswerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();
    }

    public function test_hostile_source_and_model_text_stay_inert_data_through_the_api(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->hostileBook($user);

        $run = $this->makeRun($user, 'registro del porto', [$built['asset']->id]);

        // Hostile MODEL output too: the claim text carries markup.
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Il registro annotava <script>alert("claim-xss")</script> merci.', ['E3']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E3']));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $response = $this->actingAs($user)->getJson('/api/v1/answers/'.$run->public_id)->assertOk();

        // The payload is JSON data: markup arrives intact as TEXT (JSON
        // string), never pre-rendered/interpreted server-side...
        $claimText = $response->json('data.claims.0.text');
        $this->assertStringContainsString('<script>', $claimText);

        // ...and the persisted evidence excerpt is byte-exact hostile
        // source (provenance over sanitization; rendering is the safe
        // layer).
        $excerpts = array_column($response->json('data.citations'), 'excerpt');
        $this->assertTrue(
            (bool) array_filter($excerpts, fn ($excerpt) => str_contains($excerpt, '<script>alert("xss")</script>')),
        );

        // Reader payload: node text is raw data too.
        $reader = $this->actingAs($user)->get(
            '/library/books/'.$built['asset']->public_id.'/reader',
        )->assertOk();
        $nodes = $reader->viewData('page')['props']['current_section']['nodes'];
        $hostileNode = collect($nodes)->first(fn ($node) => str_contains($node['text'], '<script>'));
        $this->assertNotNull($hostileNode);
    }

    public function test_answer_and_reader_pages_never_use_dangerous_html_injection(): void
    {
        // Static release gate: the M3 surfaces must render untrusted
        // text as React text nodes only.
        $files = [
            resource_path('js/pages/search.tsx'),
            resource_path('js/pages/library/reader.tsx'),
            resource_path('js/pages/admin/answers/index.tsx'),
            resource_path('js/pages/admin/answers/show.tsx'),
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $this->assertStringNotContainsString(
                'dangerouslySetInnerHTML',
                (string) file_get_contents($file),
                basename($file).' must not inject HTML',
            );
        }
    }
}
