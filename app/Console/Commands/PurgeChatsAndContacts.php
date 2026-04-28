<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\MessageMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PurgeChatsAndContacts extends Command
{
    protected $signature = 'chats:purge
                            {--force : Выполнить без запроса подтверждения (обязательно в production)}
                            {--dry-run : Показать счётчики, ничего не удалять}';

    protected $description = 'Удаляет все чаты (сообщения, реакции, вложения, назначения), файлы медиа с диска local и всех контактов.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (app()->environment('production') && ! $dry && ! $force) {
            $this->error('В production добавьте --force (операция необратима).');

            return self::FAILURE;
        }

        $counts = [
            'chats' => Chat::query()->count(),
            'contacts' => Contact::query()->count(),
            'message_media' => MessageMedia::query()->count(),
        ];

        $this->table(array_keys($counts), [array_values($counts)]);

        if ($dry) {
            $this->info('Dry-run: изменений нет.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Удалить все чаты, связанные данные и всех контактов?', false)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        $deletedFiles = 0;
        $missingFiles = 0;

        DB::transaction(function () use (&$deletedFiles, &$missingFiles): void {
            $disk = Storage::disk('local');

            MessageMedia::query()->orderBy('id')->chunkById(500, function ($chunk) use ($disk, &$deletedFiles, &$missingFiles): void {
                foreach ($chunk as $media) {
                    $path = $media->disk_path;
                    if ($path === null || $path === '') {
                        continue;
                    }
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                        $deletedFiles++;
                    } else {
                        $missingFiles++;
                    }
                }
            });

            Chat::query()->delete();
            Contact::query()->delete();
        });

        $this->info("Файлов медиа удалено с диска: {$deletedFiles} (отсутствовало на диске: {$missingFiles}).");
        $this->info('Таблицы chats (каскадом messages и др.) и contacts очищены.');

        return self::SUCCESS;
    }
}
