<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One-time migration of plaintext signup PII to Laravel-encrypted values.
 * Re-running is safe: already encrypted rows are skipped.
 * Changing APP_KEY after encryption makes existing rows unreadable.
 */
final class EncryptLegacySignupRequestsCommand extends Command
{
    protected $signature = 'signup-requests:encrypt-legacy {--dry-run : Show counts without writing}';

    protected $description = 'Encrypt existing plaintext PII in tenant_signup_requests (run once after enabling encrypted casts).';

    /** @var list<string> */
    private const PII_COLUMNS = [
        'company_name',
        'bin',
        'desired_slug',
        'contact_name',
        'email',
        'phone',
        'message',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $encrypted = 0;
        $skipped = 0;

        $query = DB::table('tenant_signup_requests')->orderBy('id');

        foreach ($query->cursor() as $row) {
            $updates = [];

            foreach (self::PII_COLUMNS as $column) {
                if (! property_exists($row, $column)) {
                    continue;
                }

                $value = $row->{$column};

                if ($value === null || $value === '') {
                    continue;
                }

                if ($this->isAlreadyEncrypted((string) $value)) {
                    $skipped++;

                    continue;
                }

                $updates[$column] = Crypt::encryptString((string) $value);
            }

            if ($updates === []) {
                continue;
            }

            $encrypted += count($updates);

            if (! $dryRun) {
                DB::table('tenant_signup_requests')
                    ->where('id', $row->id)
                    ->update($updates);
            }
        }

        $this->info($dryRun
            ? "Dry run: would encrypt {$encrypted} field value(s); skipped {$skipped} already encrypted."
            : "Encrypted {$encrypted} field value(s); skipped {$skipped} already encrypted.");

        return self::SUCCESS;
    }

    private function isAlreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
