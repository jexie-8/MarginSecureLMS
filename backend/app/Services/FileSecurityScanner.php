<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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
            return;
        }

        $process = proc_open([$command, '--no-summary', $file->getRealPath()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw ValidationException::withMessages(['file' => ['The security scanner is unavailable.']]);
        }
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }
        if (proc_close($process) !== 0) {
            throw ValidationException::withMessages(['file' => ['The file failed its security scan.']]);
        }
    }
}
