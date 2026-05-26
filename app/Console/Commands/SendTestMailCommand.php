<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

final class SendTestMailCommand extends Command
{
    protected $signature = 'mail:test {email : Адрес получателя}';

    protected $description = 'Отправить тестовое письмо через текущий MAIL_MAILER (Resend API, SMTP, log…)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        $this->info("Mailer: {$mailer}");
        $this->info("From: {$from}");

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log — письмо попадёт в storage/logs, а не на почту.');
        }

        if ($mailer === 'resend' && empty(config('services.resend.key'))) {
            $this->error('RESEND_API_KEY не задан в .env');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                'Тестовое письмо Accel. Если вы видите это — почта настроена.',
                fn ($message) => $message->to($email)->subject('Accel — тест почты'),
            );
        } catch (\Throwable $e) {
            $this->error('Ошибка: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Отправлено на {$email}");

        return self::SUCCESS;
    }
}
