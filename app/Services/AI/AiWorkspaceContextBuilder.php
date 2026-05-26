<?php

declare(strict_types=1);

namespace App\Services\AI;

use Carbon\Carbon;

/**
 * Формирует текстовый контекст из результатов поиска для финального ответа AI.
 */
final class AiWorkspaceContextBuilder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload): string
    {
        $sections = [];

        $calendarMeta = is_array($payload['calendar_meta'] ?? null) ? $payload['calendar_meta'] : [];
        $calendarEvents = is_array($payload['calendar_events'] ?? null) ? $payload['calendar_events'] : [];
        if ($calendarMeta !== [] || $calendarEvents !== []) {
            $sections[] = $this->calendarSection($calendarMeta, $calendarEvents);
        }

        $funnelDeals = is_array($payload['funnel_deals'] ?? null) ? $payload['funnel_deals'] : [];
        if ($funnelDeals !== []) {
            $sections[] = $this->linesSection('Воронки / сделки', $funnelDeals, static fn (array $row): string => sprintf(
                '- %s | воронка «%s», этап «%s»%s%s',
                (string) ($row['name'] ?? 'Без имени'),
                (string) ($row['funnel_name'] ?? '—'),
                (string) ($row['stage_name'] ?? '—'),
                ! empty($row['assignees']) ? ', ответственные: '.implode(', ', array_column($row['assignees'], 'name')) : '',
                isset($row['unread_count']) && (int) $row['unread_count'] > 0 ? ', непрочитанных: '.$row['unread_count'] : '',
            ));
        }

        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        if ($messages !== []) {
            $sections[] = $this->linesSection('Сообщения в чатах', $messages, static fn (array $row): string => sprintf(
                '- [%s] %s: %s',
                (string) ($row['message_at'] ?? '—'),
                (string) ($row['contact_name'] ?? $row['chat_name'] ?? 'Чат'),
                mb_substr((string) ($row['body'] ?? ''), 0, 180),
            ));
        }

        $posts = is_array($payload['department_posts'] ?? null) ? $payload['department_posts'] : [];
        if ($posts !== []) {
            $sections[] = $this->linesSection('Задачи отдела', $posts, static fn (array $row): string => sprintf(
                '- «%s» (%s) | отдел: %s | статус: %s%s',
                (string) ($row['title'] ?? '—'),
                (string) ($row['due_at'] ?? 'без срока'),
                (string) ($row['department_name'] ?? '—'),
                (string) ($row['status'] ?? '—'),
                ! empty($row['assignees']) ? ' | исп.: '.implode(', ', array_column($row['assignees'], 'name')) : '',
            ));
        }

        $employees = is_array($payload['employees'] ?? null) ? $payload['employees'] : [];
        if ($employees !== []) {
            $sections[] = $this->linesSection('Сотрудники', $employees, static fn (array $row): string => sprintf(
                '- %s%s',
                (string) ($row['name'] ?? '—'),
                isset($row['email']) && $row['email'] ? ' ('.$row['email'].')' : '',
            ));
        }

        $contacts = is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];
        if ($contacts !== []) {
            $sections[] = $this->linesSection('Контакты', $contacts, static fn (array $row): string => sprintf(
                '- %s%s%s',
                (string) ($row['name'] ?? '—'),
                ! empty($row['phone_number']) ? ', '.$row['phone_number'] : '',
                ! empty($row['companies']) ? ', компании: '.implode(', ', $row['companies']) : '',
            ));
        }

        $media = is_array($payload['media'] ?? null) ? $payload['media'] : [];
        if ($media !== []) {
            $sections[] = $this->linesSection('Файлы', $media, static fn (array $row): string => sprintf(
                '- %s (%s) — %s',
                (string) ($row['filename'] ?? 'файл'),
                (string) ($row['mime_type'] ?? '—'),
                (string) ($row['contact_name'] ?? $row['chat_name'] ?? 'чат'),
            ));
        }

        return trim(implode("\n\n", array_filter($sections)));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $events
     */
    private function calendarSection(array $meta, array $events): string
    {
        $lines = ['Календарь / записи:'];

        if (($meta['access_denied'] ?? false) === true) {
            $lines[] = 'Доступ запрещён: у вас нет прав смотреть календарь этого сотрудника.';

            return implode("\n", $lines);
        }

        if (($meta['not_found'] ?? false) === true) {
            $lines[] = 'Сотрудник по указанному имени не найден среди доступных вам.';

            return implode("\n", $lines);
        }

        if (is_array($meta['ambiguous'] ?? null) && $meta['ambiguous'] !== []) {
            $lines[] = 'Найдено несколько сотрудников с похожим именем — уточните:';
            foreach ($meta['ambiguous'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = '- '.(string) ($row['name'] ?? '—');
            }

            return implode("\n", $lines);
        }

        $employee = is_string($meta['employee_name'] ?? null) ? $meta['employee_name'] : 'сотрудник';
        $from = is_string($meta['date_from'] ?? null) ? $meta['date_from'] : '';
        $to = is_string($meta['date_to'] ?? null) ? $meta['date_to'] : '';
        $lines[] = "Сотрудник: {$employee}. Период: {$from} — {$to}.";

        if ($events === []) {
            $lines[] = 'Записей нет — в этом периоде календарь свободен.';

            return implode("\n", $lines);
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $start = Carbon::parse((string) ($event['starts_at'] ?? now()))->timezone(config('app.timezone'));
            $end = Carbon::parse((string) ($event['ends_at'] ?? now()))->timezone(config('app.timezone'));
            $allDay = filter_var($event['all_day'] ?? false, FILTER_VALIDATE_BOOL);
            $when = $allDay
                ? $start->toDateString().' (весь день)'
                : $start->format('Y-m-d H:i').' — '.$end->format('H:i');
            $lines[] = sprintf(
                '- %s: «%s»%s',
                $when,
                (string) ($event['title'] ?? 'Запись'),
                ! empty($event['contact']['name']) ? ', клиент: '.$event['contact']['name'] : '',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): string  $formatter
     */
    private function linesSection(string $title, array $rows, callable $formatter): string
    {
        $lines = ["{$title} (".count($rows).'):'];
        foreach (array_slice($rows, 0, 40) as $row) {
            if (is_array($row)) {
                $lines[] = $formatter($row);
            }
        }

        return implode("\n", $lines);
    }
}
