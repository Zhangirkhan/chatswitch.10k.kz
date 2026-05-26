<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;

final class HealthController extends Controller
{
    /**
     * Простой health-check, доступен на любом хосте (лендинг, супер-админка, тенант).
     * Используется Laravel'ом и фронтовым composable useConnectionStatus для проверки связи.
     */
    public function __invoke(): Response
    {
        Event::dispatch(new DiagnosingHealth);

        return response('OK', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
