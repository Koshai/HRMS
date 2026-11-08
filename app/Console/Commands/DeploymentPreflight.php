<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeploymentPreflight extends Command
{
    protected $signature = 'hrms:preflight';

    protected $description = 'Read-only release configuration and database preflight';

    public function handle(): int
    {
        $checks = [
            'PHP 8.3 or newer' => version_compare((string) phpversion(), '8.3.0', '>='),
            'Required PHP extensions' => $this->extensionsPresent(),
            'Production environment' => config('app.env') === 'production',
            'Debug disabled' => config('app.debug') === false,
            'Persistent application key configured' => $this->validKey(),
            'HTTPS application URL' => str_starts_with((string) config('app.url'), 'https://'),
            'Encrypted sessions' => config('session.encrypt') === true,
            'Secure session cookies' => config('session.secure') === true,
            'MySQL or MariaDB selected' => in_array(config('database.default'), ['mysql', 'mariadb'], true),
            'Supported mail transport selected' => in_array(config('mail.default'), ['smtp', 'sendmail'], true),
            'Mail sender configured' => filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false
                && ! str_ends_with((string) config('mail.from.address'), '@example.com'),
            'Built frontend manifest present' => is_file(public_path('build/manifest.json')),
            'Storage writable' => is_writable(storage_path()),
            'Cache directory writable' => is_writable(base_path('bootstrap/cache')),
            'Statutory activation disabled' => config('compliance.allow_statutory_activation') === false,
        ];

        try {
            DB::select('SELECT 1');
            $checks['Database connection'] = true;
            $checks['Migration table present'] = Schema::hasTable('migrations');
            if ($checks['Migration table present']) {
                $ran = DB::table('migrations')->pluck('migration')->all();
                $files = glob(database_path('migrations/*.php')) ?: [];
                $pending = array_diff(array_map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME), $files), $ran);
                $checks['No pending migrations'] = $pending === [];
            } else {
                $checks['No pending migrations'] = false;
            }
        } catch (\Throwable) {
            $checks['Database connection'] = false;
            $checks['Migration table present'] = false;
            $checks['No pending migrations'] = false;
        }

        foreach ($checks as $name => $passed) {
            $this->line(($passed ? 'PASS' : 'BLOCK').'  '.$name);
        }
        $this->line('Manual gates: web/CLI PHP parity, TLS and public/ document root, mail delivery, backup restore, and role/browser acceptance.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function extensionsPresent(): bool
    {
        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'session', 'tokenizer', 'xml', 'pdo_mysql'] as $extension) {
            if (! extension_loaded($extension)) {
                return false;
            }
        }

        return true;
    }

    private function validKey(): bool
    {
        $key = config('app.key');
        if (! is_string($key)) {
            return false;
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false && strlen($decoded) === 32;
        }

        return strlen($key) === 32;
    }
}
