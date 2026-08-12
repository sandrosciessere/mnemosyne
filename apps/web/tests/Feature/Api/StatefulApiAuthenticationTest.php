<?php

namespace Tests\Feature\Api;

use App\Models\BookAccessGrant;
use App\Models\User;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

/**
 * AUTH1 regression: an authenticated first-party browser session must be
 * able to call /api/v1 (Sanctum stateful SPA authentication via
 * Middleware::statefulApi() in bootstrap/app.php).
 *
 * FIDELITY: these tests deliberately avoid actingAs()/withSession().
 * Both prime in-process singletons (the memoized SessionGuard / the
 * session store), which is exactly why the original suite passed while
 * real browsers got 401: authentication never had to flow through the
 * API middleware stack. Here authentication exists ONLY in a persisted
 * session file plus an encrypted session cookie, and the memoized
 * guards/session store are flushed after login — so the API request
 * authenticates if and only if the stateful middleware (EncryptCookies →
 * StartSession → web guard) actually runs, as in a real browser. These
 * tests fail with 401 when statefulApi() is removed.
 */
class StatefulApiAuthenticationTest extends TestCase
{
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sessions must survive outside in-process singletons for the
        // cookie round-trip to be meaningful (array driver lives only in
        // the store instance we intentionally flush).
        config(['session.driver' => 'file']);
        File::ensureDirectoryExists(config('session.files'));

        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();
    }

    /**
     * Real browser login: POST /login through the web stack, session
     * persisted to file, then all memoized auth/session state flushed so
     * only the returned cookie can authenticate later requests.
     */
    private function loginViaBrowser(User $user): string
    {
        // Fresh "browser profile": drop cookies and in-process auth
        // state persisted by earlier requests in this test (the login
        // route is guest-only and guards are memoized per app instance).
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web'); // sanctum requests switch the default guard
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $response->assertRedirect();

        $sessionId = $this->app['session']->driver()->getId();

        // Simulate a fresh server process for the next request: no
        // memoized authenticated guard, no primed session store.
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        return $sessionId;
    }

    /** Same-origin browser API call: session cookie + Origin/Referer. */
    private function browserJson(string $method, string $uri, string $sessionId, array $data = [])
    {
        return $this
            ->withCredentials() // json() requests drop cookies otherwise
            ->withCookie(config('session.cookie'), $sessionId)
            ->withHeaders([
                'Origin' => config('app.url'),
                'Referer' => config('app.url').'/admin/retrieval/debugger',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->json($method, $uri, $data);
    }

    public function test_browser_session_authenticates_retrieval_search(): void
    {
        $admin = User::factory()->admin()->create();
        $sessionId = $this->loginViaBrowser($admin);

        $response = $this->browserJson('POST', '/api/v1/retrieval/search', $sessionId, [
            'query' => 'qualcosa',
        ]);

        // Authenticated: never 401. (409 NO_ACTIVE_GENERATION is the
        // correct domain answer when nothing is indexed.)
        $this->assertNotSame(401, $response->status());
        $response->assertStatus(409)->assertJsonPath('error.code', 'NO_ACTIVE_GENERATION');
    }

    public function test_browser_session_full_retrieval_roundtrip_with_unicode_exact_provenance(): void
    {
        // Full API → exact retriever → exact_matches → EvidenceSpan →
        // canonical path, session-authenticated, with a length-changing
        // Unicode fold (İ) before the target literal (C1 smoke, §50).
        $built = $this->buildArtifacts([
            0 => [
                ['text' => 'İstanbul e İzmir aprono il capitolo; the MATCH target sits here, dopo le città turche.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);

        $admin = User::factory()->admin()->create();
        $sessionId = $this->loginViaBrowser($admin);

        $response = $this->browserJson('POST', '/api/v1/retrieval/search', $sessionId, [
            'query' => 'match',
            'mode' => 'exact',
            'rerank' => false,
        ]);

        $response->assertOk();
        $hit = $response->json('data.0');
        $this->assertNotNull($hit, 'exact search must find the fixture');

        $match = $hit['exact_matches'][0];
        $this->assertSame('MATCH', $match['text']);
        $this->assertSame(
            'MATCH',
            mb_substr($built['canonical'], $match['canonical_start'], $match['canonical_end'] - $match['canonical_start']),
        );
        $this->assertNotEmpty($hit['evidence_spans']);
    }

    public function test_browser_session_authenticates_existing_milestone_one_api(): void
    {
        $user = User::factory()->create();
        $sessionId = $this->loginViaBrowser($user);

        $response = $this->browserJson('GET', '/api/v1/user', $sessionId);

        $response->assertOk();
        $this->assertSame($user->email, $response->json('email'));

        // And an M1 admin API route honors the session for admins.
        $admin = User::factory()->admin()->create();
        $adminSession = $this->loginViaBrowser($admin);
        $this->browserJson('GET', '/api/v1/admin/ingestion-runs', $adminSession)->assertOk();
    }

    public function test_anonymous_api_request_gets_401_json(): void
    {
        $response = $this->postJson('/api/v1/retrieval/search', ['query' => 'x']);

        $response->assertUnauthorized();
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }

    public function test_session_authentication_does_not_grant_admin_or_acl_bypass(): void
    {
        $owner = User::factory()->create();
        $built = $this->buildArtifacts([
            0 => [['text' => 'Contenuto riservato al proprietario del volume, indicizzato per il retrieval.']],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        (new BookAccessGrant)->forceFill([
            'user_id' => $owner->id,
            'book_asset_id' => $built['asset']->id,
            'source' => 'submission',
        ])->save();

        $stranger = User::factory()->create();
        $sessionId = $this->loginViaBrowser($stranger);

        // Admin endpoints stay closed to normal users.
        $this->browserJson('GET', '/api/v1/admin/ingestion-runs', $sessionId)->assertForbidden();

        // Scope fail-closed semantics survive the auth change.
        $this->browserJson('POST', '/api/v1/retrieval/search', $sessionId, [
            'query' => 'contenuto riservato',
            'scope' => ['book_asset_ids' => [$built['asset']->public_id]],
        ])->assertStatus(403)->assertJsonPath('error.code', 'SCOPE_NOT_ACCESSIBLE');

        // Unscoped search sees nothing it has no grant for.
        $empty = $this->browserJson('POST', '/api/v1/retrieval/search', $sessionId, [
            'query' => 'contenuto riservato',
            'mode' => 'exact',
        ]);
        $empty->assertOk();
        $this->assertSame([], $empty->json('data'));
    }

    public function test_bearer_token_authentication_still_works(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/user');

        $response->assertOk();
        $this->assertSame($user->email, $response->json('email'));
    }
}
