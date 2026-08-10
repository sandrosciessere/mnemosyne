<?php

namespace Tests\Feature\Library;

use App\Enums\BibliographicStatus;
use App\Enums\IngestionRunStatus;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\Contributor;
use App\Models\Edition;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Models\SystemSetting;
use App\Models\Work;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_generate_ulid_public_ids(): void
    {
        $work = Work::factory()->create();
        $asset = BookAsset::factory()->create();
        $submission = BookSubmission::factory()->create();

        foreach ([$work, $asset, $submission] as $model) {
            $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $model->public_id);
            $this->assertSame('public_id', $model->getRouteKeyName());
        }
    }

    public function test_asset_sha256_is_unique(): void
    {
        $asset = BookAsset::factory()->create();

        $this->expectException(QueryException::class);
        BookAsset::factory()->create(['sha256' => $asset->sha256]);
    }

    public function test_only_one_active_run_per_submission(): void
    {
        $submission = BookSubmission::factory()->approved()->create();
        IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => IngestionRunStatus::Running,
        ]);

        $this->expectException(QueryException::class);
        IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => IngestionRunStatus::Queued,
        ]);
    }

    public function test_terminal_runs_do_not_block_new_runs(): void
    {
        $submission = BookSubmission::factory()->approved()->create();
        IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => IngestionRunStatus::Failed,
        ]);

        $run = IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => IngestionRunStatus::Queued,
        ]);

        $this->assertTrue($run->exists);
        $this->assertSame(2, $submission->runs()->count());
    }

    public function test_work_title_normalization_is_deterministic(): void
    {
        $this->assertSame('il nome della rosa', Work::normalizeTitle('  Il Nome della Rosa!  '));
        $this->assertSame(Work::normalizeTitle('A—B: c'), Work::normalizeTitle('a b c'));
    }

    public function test_edition_relations_round_trip(): void
    {
        $edition = Edition::factory()->create();
        $contributor = Contributor::factory()->create();
        $edition->contributors()->attach($contributor, [
            'role' => 'aut',
            'credited_as' => $contributor->name,
            'position' => 0,
        ]);
        $edition->identifiers()->create([
            'scheme' => 'isbn13',
            'value' => '9780316769488',
            'raw_value' => 'urn:isbn:978-0-316-76948-8',
        ]);

        $this->assertSame(BibliographicStatus::Provisional, $edition->status);
        $this->assertSame('aut', $edition->contributors->first()->pivot->role);
        $this->assertSame('isbn13', $edition->identifiers->first()->scheme);
        $this->assertSame(1, $edition->work->editions()->count());
    }

    public function test_system_setting_round_trip_and_audit_event(): void
    {
        $this->assertFalse(SystemSetting::autoApprovalEnabled());

        SystemSetting::set(SystemSetting::SUBMISSION_AUTO_APPROVAL, true);

        $this->assertTrue(SystemSetting::autoApprovalEnabled());
        $this->assertDatabaseHas('ingestion_events', [
            'type' => 'system.setting_changed',
        ]);
        $event = IngestionEvent::query()->latest('id')->first();
        $this->assertSame(['key' => SystemSetting::SUBMISSION_AUTO_APPROVAL, 'value' => true], $event->payload);
    }

    public function test_contributor_homonyms_are_not_collapsed_by_schema(): void
    {
        // Two different humans named the same: both rows must coexist.
        $first = Contributor::factory()->create([
            'name' => 'John Smith',
            'normalized_name' => Contributor::normalizeName('John Smith'),
        ]);
        $second = Contributor::factory()->create([
            'name' => 'John Smith',
            'normalized_name' => Contributor::normalizeName('John Smith'),
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            2,
            Contributor::query()->where('normalized_name', 'john smith')->count(),
        );
    }

    public function test_content_addressed_storage_path_is_sharded(): void
    {
        $sha = str_repeat('ab', 32);
        $this->assertSame(
            'library/original/sha256/ab/ab/'.$sha.'.epub',
            BookAsset::originalStoragePath($sha),
        );
    }
}
