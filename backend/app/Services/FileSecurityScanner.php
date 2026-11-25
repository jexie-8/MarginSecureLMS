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

        $this->inspectArchive((string) $file->getRealPath());

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

    /**
     * Decompression-bomb check for ZIP files, including .docx and .xlsx, which
     * are ZIP containers. It reads only the archive's table of contents (the
     * central directory) and never unpacks anything, so the check itself cannot
     * be overloaded. Anything it cannot read with confidence is refused.
     */
    private function inspectArchive(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->rejectArchive('This archive could not be read.');
        }

        try {
            if (fread($handle, 4) !== "PK\x03\x04") {
                return; // Not a ZIP container.
            }

            $size = (int) fstat($handle)['size'];
            // The end-of-central-directory record is the last 22 bytes plus an
            // optional comment of at most 65,535 bytes.
            $tailLength = min($size, 22 + 65535);
            fseek($handle, $size - $tailLength);
            $tail = (string) fread($handle, $tailLength);
            $eocdAt = strrpos($tail, "PK\x05\x06");
            if ($eocdAt === false || strlen($tail) - $eocdAt < 22) {
                $this->rejectArchive('This archive could not be read.');
            }

            $eocd = unpack('vdisk/vcdDisk/ventriesHere/ventries/VcdSize/VcdOffset', substr($tail, $eocdAt + 4, 16));
            if ($eocd['entries'] === 0xFFFF || $eocd['cdSize'] === 0xFFFFFFFF || $eocd['cdOffset'] === 0xFFFFFFFF) {
                // ZIP64: only needed for archives far beyond the upload limit.
                $this->rejectArchive('This archive is too large when unpacked.');
            }
            if ($eocd['disk'] !== 0 || $eocd['cdDisk'] !== 0 || $eocd['entriesHere'] !== $eocd['entries']) {
                $this->rejectArchive('Split archives are not allowed.');
            }

            $maxEntries = (int) config('margin.archive_max_entries', 2000);
            if ($eocd['entries'] > $maxEntries) {
                $this->rejectArchive('This archive contains too many files.');
            }
            if ($eocd['cdOffset'] + $eocd['cdSize'] > $size) {
                $this->rejectArchive('This archive could not be read.');
            }

            fseek($handle, $eocd['cdOffset']);
            $directory = $eocd['cdSize'] > 0 ? (string) fread($handle, $eocd['cdSize']) : '';
        } finally {
            fclose($handle);
        }

        $maxTotal = (int) config('margin.archive_max_unpacked_mb', 256) * 1024 * 1024;
        $maxRatio = (int) config('margin.archive_max_ratio', 100);
        $total = 0;
        $offset = 0;
        $seen = 0;

        while ($offset < strlen($directory)) {
            if (strlen($directory) - $offset < 46 || substr($directory, $offset, 4) !== "PK\x01\x02") {
                $this->rejectArchive('This archive could not be read.');
            }
            $entry = unpack('Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength', substr($directory, $offset + 20, 14));
            $name = substr($directory, $offset + 46, $entry['nameLength']);
            $offset += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];
            $seen++;

            if ($entry['compressed'] === 0xFFFFFFFF || $entry['size'] === 0xFFFFFFFF) {
                $this->rejectArchive('This archive is too large when unpacked.');
            }
            if (preg_match('/\.(zip|jar|7z|rar|gz|tgz|bz2|xz|tar|cab|iso)$/i', $name) === 1) {
                $this->rejectArchive('Archives inside archives are not allowed.');
            }

            $total += $entry['size'];
            // Real documents compress about 2 to 20 times; a bomb compresses
            // hundreds or thousands of times. Small entries are left alone.
            $suspicious = $entry['size'] >= 1024 * 1024
                && ($entry['compressed'] === 0 || $entry['size'] / $entry['compressed'] > $maxRatio);
            if ($suspicious || $total > $maxTotal) {
                $this->rejectArchive('This archive is too large when unpacked.');
            }
        }

        if ($seen !== $eocd['entries']) {
            $this->rejectArchive('This archive could not be read.');
        }
    }

    private function rejectArchive(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
