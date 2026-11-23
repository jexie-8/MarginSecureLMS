<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single place that stores and serves uploaded files (course materials,
 * submissions, profile photos).
 *
 * Files live on a private disk chosen by configuration: the server's own
 * private folder in development and the encrypted private S3 bucket in
 * production (FILES_DISK, falling back to FILESYSTEM_DISK). They are never
 * reachable by URL; every download goes through a controller that checks the
 * user's permission first and then streams the file.
 */
class PrivateFiles
{
    public function disk(): Filesystem
    {
        $name = (string) config('margin.files_disk', 'local');

        // A public disk would put uploads at guessable URLs. Refuse it outright.
        if ($name === 'public' || config("filesystems.disks.{$name}.visibility") === 'public') {
            throw new RuntimeException("The '{$name}' disk is public and cannot hold private uploads.");
        }

        return Storage::disk($name);
    }

    /** Stores the upload under a generated name and returns its path. */
    public function store(UploadedFile $file, string $directory): string
    {
        $path = $this->disk()->putFile($directory, $file);

        if ($path === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        return $path;
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /** Streams the file as a download, whichever disk holds it. */
    public function download(string $path, string $downloadName, ?string $mimeType = null): StreamedResponse
    {
        return $this->disk()->download(
            $path,
            $downloadName,
            $mimeType ? ['Content-Type' => $mimeType] : [],
        );
    }

    /** Streams the file for display (profile photos). */
    public function inline(string $path, array $headers = []): StreamedResponse
    {
        return $this->disk()->response($path, null, $headers);
    }
}
