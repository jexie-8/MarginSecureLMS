<?php

namespace Tests\Feature;

use App\Services\FileSecurityScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ArchiveInspectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Test the archive check on its own, without ClamAV.
        config(['margin.clamav_command' => null, 'margin.file_scan_required' => false]);
    }

    public function test_an_ordinary_zip_is_accepted(): void
    {
        $zip = $this->zip([
            'report.txt' => str_repeat("Week 3 lab notes, results and discussion.\n", 200),
            'data.csv' => implode("\n", array_map(fn ($i) => "$i,".($i * 7 % 13), range(1, 500))),
        ]);

        $this->scan($zip);
        $this->addToAssertionCount(1);
    }

    public function test_a_decompression_bomb_is_refused(): void
    {
        // 20 MB of zeros deflates to about 20 KB: a ratio of roughly 1000:1.
        $zip = $this->zip(['bomb.txt' => str_repeat("\0", 20 * 1024 * 1024)]);
        $this->assertLessThan(100 * 1024, strlen($zip));

        $this->assertRejected($zip, 'too large when unpacked');
    }

    public function test_an_archive_with_too_many_files_is_refused(): void
    {
        config(['margin.archive_max_entries' => 5]);
        $files = [];
        foreach (range(1, 6) as $i) {
            $files["file$i.txt"] = "file $i";
        }

        $this->assertRejected($this->zip($files), 'too many files');
    }

    public function test_archives_inside_archives_are_refused(): void
    {
        $inner = $this->zip(['a.txt' => 'hello']);

        $this->assertRejected($this->zip(['inner.zip' => $inner]), 'inside archives');
    }

    public function test_the_unpacked_total_is_capped(): void
    {
        config(['margin.archive_max_unpacked_mb' => 1]);
        // Random data does not compress, so only the total trips the limit.
        $zip = $this->zip(['a.bin' => random_bytes(600 * 1024), 'b.bin' => random_bytes(600 * 1024)], stored: true);

        $this->assertRejected($zip, 'too large when unpacked');
    }

    public function test_a_damaged_archive_is_refused(): void
    {
        $zip = $this->zip(['a.txt' => str_repeat('content ', 100)]);
        // Cut off the table of contents.
        $this->assertRejected(substr($zip, 0, (int) (strlen($zip) * 0.6)), 'could not be read');
    }

    private function scan(string $bytes): void
    {
        app(FileSecurityScanner::class)->validate(UploadedFile::fake()->createWithContent('upload.zip', $bytes));
    }

    private function assertRejected(string $bytes, string $reason): void
    {
        try {
            $this->scan($bytes);
        } catch (ValidationException $e) {
            $this->assertStringContainsString($reason, $e->errors()['file'][0]);

            return;
        }
        $this->fail("The archive was accepted; expected a refusal ($reason).");
    }

    /**
     * Builds a ZIP file in plain PHP (no zip extension needed).
     *
     * @param  array<string, string>  $files
     */
    private function zip(array $files, bool $stored = false): string
    {
        $body = '';
        $directory = '';
        foreach ($files as $name => $content) {
            $data = $stored ? $content : gzdeflate($content, 9);
            $method = $stored ? 0 : 8;
            $crc = crc32($content);
            $offset = strlen($body);
            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, $method, 0, 0, $crc, strlen($data), strlen($content), strlen($name), 0)
                .$name.$data;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, $method, 0, 0, $crc, strlen($data), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset)
                .$name;
        }

        return $body.$directory
            .pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }
}
