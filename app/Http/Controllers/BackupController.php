<?php

namespace App\Http\Controllers;

use App\Http\Requests\RestoreBackupRequest;
use App\Services\BackupArchive;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $service,
        private readonly BackupArchive $archive,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return view('backup.index', [
            'rowCounts' => $this->service->currentRowCounts(),
        ]);
    }

    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ], [], ['password' => 'backup password']);

        try {
            BackupArchive::assertSupported();
            $path = $this->archive->build($this->service->export(), $request->string('password'));
        } catch (Throwable $e) {
            Log::channel('sync')->error('backup download failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Backup failed: '.$e->getMessage());
        }

        $filename = 'mossfield-backup-'.now()->format('Y-m-d-His').'.mfbackup';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/octet-stream',
        ])->deleteFileAfterSend();
    }

    public function restore(RestoreBackupRequest $request): RedirectResponse
    {
        try {
            BackupArchive::assertSupported();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        // Decrypt + open the archive (wrong password / corrupt file surface here).
        try {
            $opened = $this->archive->open(
                $request->file('backup_file')->getRealPath(),
                $request->string('password'),
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage().' No changes were made.');
        }

        // Load the data (transactional — any failure rolls back).
        try {
            $summary = $this->service->import($opened['payload']);
        } catch (Throwable $e) {
            @unlink($opened['zipPath']);
            Log::channel('sync')->error('backup restore failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Restore failed: '.$e->getMessage().' No changes were made.');
        }

        // Restore images after the DB commit; a failure here is non-fatal.
        $imageWarning = null;
        try {
            $images = $this->archive->extractImages($opened['zipPath']);
        } catch (Throwable $e) {
            $images = 0;
            $imageWarning = ' Data restored, but images could not be written: '.$e->getMessage();
            Log::channel('sync')->error('backup image restore failed', ['error' => $e->getMessage()]);
        } finally {
            @unlink($opened['zipPath']);
        }

        $total = array_sum($summary);
        $message = "Restore complete — {$total} rows loaded across ".count($summary)." tables, {$images} images restored.";

        return redirect()->route('backup.index')->with(
            $imageWarning ? 'error' : 'success',
            $message.($imageWarning ?? '')
        );
    }
}
