<?php

declare(strict_types=1);

const PATCH_NAME = 'Margin LMS Step 58';

$root = __DIR__;
$payload = $root.DIRECTORY_SEPARATOR.'.margin-step58-payload';
$bootstrapPath = $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$apiRoutesPath = $root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'api.php';
$authRoutesPath = $root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'margin_auth.php';

function failInstall(string $message): never
{
    fwrite(STDERR, "\nINSTALL STOPPED: {$message}\n");
    fwrite(STDERR, "Preflight failures make no changes. If a write failed, restore the timestamped backups.\n");
    fwrite(STDERR, "See STEP58_MANUAL_MERGE.md for a manual merge.\n");
    exit(1);
}

function normalizedPath(string $path): string
{
    return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function backupPath(string $root, string $relative, string $stamp): string
{
    return $root.DIRECTORY_SEPARATOR.'.margin-step58-backups'.DIRECTORY_SEPARATOR.$stamp
        .DIRECTORY_SEPARATOR.normalizedPath($relative);
}

function writeWithBackup(
    string $root,
    string $relative,
    string $contents,
    string $stamp,
    bool $existingIsRequired = false,
): void {
    $target = $root.DIRECTORY_SEPARATOR.normalizedPath($relative);

    if ($existingIsRequired && ! is_file($target)) {
        throw new RuntimeException("Required file not found: {$relative}");
    }

    if (is_file($target)) {
        $backup = backupPath($root, $relative, $stamp);
        if (! is_dir(dirname($backup)) && ! mkdir(dirname($backup), 0777, true) && ! is_dir(dirname($backup))) {
            throw new RuntimeException("Could not create backup directory for {$relative}");
        }
        if (! copy($target, $backup)) {
            throw new RuntimeException("Could not back up {$relative}");
        }
    } elseif (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0777, true) && ! is_dir(dirname($target))) {
        throw new RuntimeException("Could not create directory for {$relative}");
    }

    if (file_put_contents($target, $contents) === false) {
        throw new RuntimeException("Could not write {$relative}");
    }
}

function patchedBootstrap(string $contents): string
{
    if (str_contains($contents, '->statefulApi(') || str_contains($contents, '$middleware->statefulApi(')) {
        return $contents;
    }

    $callbackPattern = '/(->withMiddleware\s*\(\s*function\s*\([^)]*\)\s*(?::\s*void)?\s*\{)/m';
    $count = 0;
    $patched = preg_replace(
        $callbackPattern,
        "$1\n        \$middleware->statefulApi();",
        $contents,
        1,
        $count,
    );

    if ($patched === null) {
        throw new RuntimeException('Could not parse bootstrap/app.php.');
    }

    if ($count === 1) {
        return $patched;
    }

    $insertion = <<<'PHP'
->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {
        $middleware->statefulApi();
    })
    
PHP;

    $count = 0;
    $patched = preg_replace('/->withExceptions\s*\(/', $insertion.'->withExceptions(', $contents, 1, $count);

    if ($patched === null || $count !== 1) {
        throw new RuntimeException('No safe withMiddleware merge point found in bootstrap/app.php.');
    }

    return $patched;
}

function patchedApiRoutes(string $contents): string
{
    $requireLine = "require __DIR__.'/margin_auth.php';";

    if (str_contains($contents, "margin_auth.php")) {
        return $contents;
    }

    preg_match_all(
        '/Route::(?:get|post|put|patch|delete|options|any|match)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/i',
        $contents,
        $matches,
    );

    $paths = array_map(
        static fn (string $path): string => '/'.trim($path, '/'),
        $matches[1] ?? [],
    );
    $authPaths = ['/login', '/logout', '/me'];
    $existingAuthPaths = array_intersect($paths, $authPaths);

    if ($existingAuthPaths !== []) {
        $nonAuthPaths = array_diff($paths, $authPaths);
        $mentionsAuthController = str_contains($contents, 'AuthController');

        // The prior Step 57/58 routes/api.php contained only these three routes.
        // Replacing that auth-only file is safe and removes duplicate stacks.
        if ($nonAuthPaths === [] && count(array_unique($existingAuthPaths)) === 3 && $mentionsAuthController) {
            return "<?php\n\n{$requireLine}\n";
        }

        throw new RuntimeException(
            'routes/api.php mixes existing authentication routes with custom routes; automatic removal is unsafe.'
        );
    }

    $trimmed = rtrim($contents);

    return $trimmed."\n\n{$requireLine}\n";
}

if (! is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
    failInstall('Run this installer from the root of the Laravel backend (the directory containing artisan).');
}

foreach ([$payload, $bootstrapPath, $apiRoutesPath] as $requiredPath) {
    if (! file_exists($requiredPath)) {
        failInstall("Required path not found: {$requiredPath}");
    }
}

try {
    // Preflight every risky merge before writing anything.
    $bootstrapContents = file_get_contents($bootstrapPath);
    $apiRoutesContents = file_get_contents($apiRoutesPath);
    if ($bootstrapContents === false || $apiRoutesContents === false) {
        throw new RuntimeException('Could not read bootstrap/app.php or routes/api.php.');
    }

    $newBootstrap = patchedBootstrap($bootstrapContents);
    $newApiRoutes = patchedApiRoutes($apiRoutesContents);

    $payloadFiles = [
        'app/Http/Controllers/Api/AuthController.php',
        'app/Http/Middleware/EnsureAccountIsActive.php',
        'app/Http/Requests/Auth/LoginRequest.php',
        'routes/margin_auth.php',
        'tests/Feature/Auth/AuthenticationSessionSecurityTest.php',
    ];

    foreach ($payloadFiles as $relative) {
        if (! is_file($payload.DIRECTORY_SEPARATOR.normalizedPath($relative))) {
            throw new RuntimeException("Patch payload is incomplete: {$relative}");
        }
    }

    $stamp = date('Ymd-His');

    writeWithBackup($root, 'bootstrap/app.php', $newBootstrap, $stamp, true);
    writeWithBackup($root, 'routes/api.php', $newApiRoutes, $stamp, true);

    foreach ($payloadFiles as $relative) {
        $source = $payload.DIRECTORY_SEPARATOR.normalizedPath($relative);
        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new RuntimeException("Could not read payload file: {$relative}");
        }
        writeWithBackup($root, $relative, $contents, $stamp);
    }

    if (! is_file($root.DIRECTORY_SEPARATOR.'phpunit.margin.xml')) {
        $template = file_get_contents($root.DIRECTORY_SEPARATOR.'phpunit.margin.xml.dist');
        if ($template === false) {
            throw new RuntimeException('Could not read phpunit.margin.xml.dist.');
        }
        writeWithBackup($root, 'phpunit.margin.xml', $template, $stamp);
    }

    fwrite(STDOUT, PATCH_NAME." installed successfully.\n");
    fwrite(STDOUT, "Backups: .margin-step58-backups/{$stamp}/\n\n");
    fwrite(STDOUT, "Next:\n");
    fwrite(STDOUT, "  php artisan optimize:clear\n");
    fwrite(STDOUT, "  php artisan route:list --path=api/v1 -v\n");
    fwrite(STDOUT, "  php artisan test --configuration phpunit.margin.xml --filter=AuthenticationSessionSecurityTest\n");
} catch (Throwable $exception) {
    failInstall($exception->getMessage());
}

