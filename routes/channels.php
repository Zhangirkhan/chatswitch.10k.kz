<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.{chatId}', fn () => true);
Broadcast::channel('chats.list', fn () => true);
Broadcast::channel('whatsapp-status', fn () => true);
