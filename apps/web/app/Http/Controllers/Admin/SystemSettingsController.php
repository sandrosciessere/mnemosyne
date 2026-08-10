<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Ingestion\StageExecutor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/system', [
            'settings' => [
                'submission_auto_approval' => SystemSetting::autoApprovalEnabled(),
            ],
            // Environment-controlled values are display-only: changing them
            // requires a .env change and a stack restart.
            'environment' => [
                'max_upload_bytes' => (int) config('mnemosyne.ingestion.max_upload_bytes'),
                'ingestion_concurrency' => (int) config('mnemosyne.ingestion.concurrency'),
                'stale_after_minutes' => (int) config('mnemosyne.ingestion.stale_after_minutes'),
                'submissions_per_hour' => (int) config('mnemosyne.ingestion.rate_limits.submissions_per_hour'),
            ],
            'pipeline' => [
                'pipeline_version' => (string) config('mnemosyne.ingestion.pipeline_version'),
                'stage_handlers' => StageExecutor::HANDLER_VERSIONS,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'submission_auto_approval' => ['required', 'boolean'],
        ]);

        SystemSetting::set(
            SystemSetting::SUBMISSION_AUTO_APPROVAL,
            (bool) $validated['submission_auto_approval'],
            $request->user(),
        );

        return back()->with('success', 'Setting updated.');
    }
}
