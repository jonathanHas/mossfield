<?php

namespace App\Http\Controllers;

use App\Http\Requests\RestoreBackupRequest;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BackupController extends Controller
{
    public function __construct(private readonly BackupService $service) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return view('backup.index', [
            'rowCounts' => $this->service->currentRowCounts(),
            'appKeyFingerprint' => BackupService::appKeyFingerprint(),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $payload = $this->service->export();
        $filename = 'mossfield-backup-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function restore(RestoreBackupRequest $request): RedirectResponse
    {
        $contents = file_get_contents($request->file('backup_file')->getRealPath());
        $payload = json_decode($contents, true);

        if (! is_array($payload)) {
            return back()->with('error', 'The uploaded file is not valid JSON.');
        }

        // A different APP_KEY means the encrypted Customer contact fields in the
        // backup cannot be decrypted after restore — block unless acknowledged.
        $backupFingerprint = $payload['meta']['app_key_fingerprint'] ?? null;
        $keyMismatch = $backupFingerprint !== BackupService::appKeyFingerprint();

        if ($keyMismatch && ! $request->boolean('acknowledge_key_mismatch')) {
            return back()->with('error', 'This backup was made with a different APP_KEY. '
                .'Customer contact details will be unreadable after restore. '
                .'Tick the acknowledgement box to proceed anyway.');
        }

        try {
            $summary = $this->service->import($payload);
        } catch (Throwable $e) {
            Log::channel('sync')->error('backup restore failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Restore failed: '.$e->getMessage().' No changes were made.');
        }

        $total = array_sum($summary);
        $message = "Restore complete — {$total} rows loaded across ".count($summary).' tables.';
        if ($keyMismatch) {
            $message .= ' Warning: backup used a different APP_KEY, so encrypted customer contact details may be unreadable.';
        }

        return redirect()->route('backup.index')->with('success', $message);
    }
}
