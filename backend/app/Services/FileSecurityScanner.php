<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class FileSecurityScanner
{
    private const ALLOWED = [
        'application/pdf', 'text/plain', 'text/csv',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'image/png', 'image/jpeg',
    ];

    public function validate(UploadedFile $file): void
    {
        if (! $file->isValid() || ! in_array($file->getMimeType(), self::ALLOWED, true)) {
            throw ValidationException::withMessages(['file' => ['This file type is not allowed.']]);
        }

        $command = trim((string) config('margin.clamav_command'));
        if ($command === '') {
            if (config('margin.file_scan_required')) {
                throw ValidationException::withMessages(['file' => ['The security scanner is unavailable.']]);
            }

            return;
        }

        try {
            $process = new Process([$command, '--no-summary', $file->getRealPath()]);
            $process->setTimeout(max(1, (int) config('margin.file_scan_timeout', 30)));
            $process->run();
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages(['file' => ['The security scanner is unavailable.']]);
        }

        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages(['file' => ['The file failed its security scan.']]);
        }
    }
}
