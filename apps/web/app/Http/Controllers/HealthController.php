<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'mnemosyne-web',
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        foreach (config('mnemosyne.readiness_checks') as $check) {
            $ok = match ($check) {
                'db' => $this->checkDatabase(),
                'redis' => $this->checkRedis(),
                'storage' => $this->checkStorage(),
                default => true,
            };

            $checks[$check] = $ok ? 'ok' : 'failed';
            $healthy = $healthy && $ok;
        }

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'mnemosyne-web',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkStorage(): bool
    {
        $path = config('mnemosyne.data_path');

        return is_string($path) && is_dir($path) && is_writable($path);
    }
}
