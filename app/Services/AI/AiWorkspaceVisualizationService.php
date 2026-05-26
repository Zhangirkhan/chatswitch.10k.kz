<?php

declare(strict_types=1);

namespace App\Services\AI;

final class AiWorkspaceVisualizationService
{
    private const int MAX_ITEMS = 3;

    private const int MAX_LABELS = 24;

    /** @var list<string> */
    private const array CHART_TYPES = ['bar', 'line', 'doughnut', 'pie'];

    /** @var list<string> */
    private const array MERMAID_PREFIXES = [
        'flowchart',
        'graph',
        'sequenceDiagram',
        'classDiagram',
        'stateDiagram',
        'stateDiagram-v2',
        'erDiagram',
        'journey',
        'gantt',
        'pie',
        'mindmap',
        'timeline',
        'gitGraph',
    ];

    /**
     * @param  list<mixed>  $aiVisualizations
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    public function resolve(
        array $aiVisualizations,
        string $message,
        array $contacts,
        array $media,
        bool $ranContacts,
        bool $ranMedia,
    ): array {
        $items = $this->normalizeFromAi($aiVisualizations);
        $wantsDataViz = $this->wantsDataVisualization($message);
        $hasSearchData = $ranContacts || $ranMedia;

        if ($wantsDataViz && $hasSearchData) {
            $autoItems = $this->buildFromSearchResults($contacts, $media, $message);

            if ($autoItems !== [] && ! $this->wantsProcessDiagram($message)) {
                return array_slice($autoItems, 0, self::MAX_ITEMS);
            }

            if ($autoItems !== []) {
                $items = array_values(array_filter(
                    $items,
                    static fn (array $item): bool => ($item['type'] ?? '') !== 'mermaid',
                ));
                $items = array_slice(array_merge($autoItems, $items), 0, self::MAX_ITEMS);
            }
        } elseif ($items === [] && $wantsDataViz && $hasSearchData) {
            $items = $this->buildFromSearchResults($contacts, $media, $message);
        }

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    private function wantsProcessDiagram(string $message): bool
    {
        $lower = mb_strtolower($message);

        if (preg_match('/график|диаграм|chart|гистограм|столбч|кругов|pie chart/u', $lower)) {
            return false;
        }

        return (bool) preg_match(
            '/процесс|flowchart|блок[- ]?схем|mermaid|sequence|этап|воронк|схема/u',
            $lower,
        );
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeFromAi(array $raw): array
    {
        $items = [];

        foreach ($raw as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = strtolower(trim((string) ($entry['type'] ?? '')));

            if ($type === 'chart') {
                $chart = $this->normalizeChart($entry, $index);
                if ($chart !== null) {
                    $items[] = $chart;
                }

                continue;
            }

            if ($type === 'mermaid') {
                $diagram = $this->normalizeMermaid($entry, $index);
                if ($diagram !== null) {
                    $items[] = $diagram;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function normalizeChart(array $entry, int $index): ?array
    {
        $chartType = strtolower(trim((string) ($entry['chart_type'] ?? $entry['chartType'] ?? 'bar')));
        if (! in_array($chartType, self::CHART_TYPES, true)) {
            $chartType = 'bar';
        }

        $labels = $this->stringList($entry['labels'] ?? []);
        if ($labels === []) {
            return null;
        }

        $datasetsRaw = $entry['datasets'] ?? [];
        if (! is_array($datasetsRaw) || $datasetsRaw === []) {
            return null;
        }

        $datasets = [];
        foreach ($datasetsRaw as $ds) {
            if (! is_array($ds)) {
                continue;
            }
            $data = $this->numericList($ds['data'] ?? []);
            if ($data === []) {
                continue;
            }
            if (count($data) !== count($labels)) {
                $data = array_slice($data, 0, count($labels));
                while (count($data) < count($labels)) {
                    $data[] = 0;
                }
            }
            $datasets[] = [
                'label' => trim((string) ($ds['label'] ?? 'Данные')) ?: 'Данные',
                'data' => $data,
            ];
        }

        if ($datasets === []) {
            return null;
        }

        $title = trim((string) ($entry['title'] ?? ''));

        return [
            'id' => 'chart_'.$index.'_'.substr(sha1(json_encode([$labels, $datasets])), 0, 8),
            'type' => 'chart',
            'title' => $title !== '' ? $title : null,
            'chart_type' => $chartType,
            'labels' => array_slice($labels, 0, self::MAX_LABELS),
            'datasets' => $datasets,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function normalizeMermaid(array $entry, int $index): ?array
    {
        $code = trim((string) ($entry['code'] ?? $entry['mermaid'] ?? ''));
        $code = $this->sanitizeMermaidCode($code);
        if ($code === '' || ! $this->isSafeMermaid($code)) {
            return null;
        }

        $title = trim((string) ($entry['title'] ?? ''));

        return [
            'id' => 'mermaid_'.$index.'_'.substr(sha1($code), 0, 8),
            'type' => 'mermaid',
            'title' => $title !== '' ? $title : null,
            'code' => $code,
        ];
    }

    private function sanitizeMermaidCode(string $code): string
    {
        $code = trim($code);

        if (str_starts_with($code, '```')) {
            $code = preg_replace('/^```(?:mermaid)?\s*\n?/i', '', $code) ?? $code;
            $code = preg_replace('/\n?```\s*$/', '', $code) ?? $code;
        }

        return trim($code);
    }

    private function isSafeMermaid(string $code): bool
    {
        if (mb_strlen($code) > 8000) {
            return false;
        }

        $lower = mb_strtolower($code);
        foreach (['<script', 'javascript:', 'onclick', 'onerror', 'iframe'] as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                return false;
            }
        }

        $firstLine = trim(strtok($code, "\n") ?: '');
        foreach (self::MERMAID_PREFIXES as $prefix) {
            if (str_starts_with($firstLine, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function wantsDataVisualization(string $message): bool
    {
        $lower = mb_strtolower($message);

        return (bool) preg_match(
            '/график|диаграм|схем|chart|graph|visuali|визуализ|распредел|статистик|mermaid|flowchart|гистограм|кругов|pie/u',
            $lower,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    private function buildFromSearchResults(array $contacts, array $media, string $message): array
    {
        $items = [];
        $lower = mb_strtolower($message);

        if ($media !== [] && (str_contains($lower, 'файл') || str_contains($lower, 'тип') || str_contains($lower, 'mime') || str_contains($lower, 'документ') || str_contains($lower, 'фото') || $contacts === [])) {
            $byType = [];
            foreach ($media as $row) {
                $mime = is_string($row['mime_type'] ?? null) ? (string) $row['mime_type'] : '';
                $label = $this->mimeCategoryLabel($mime);
                $byType[$label] = ($byType[$label] ?? 0) + 1;
            }
            arsort($byType);
            $items[] = $this->chartFromMap('Распределение файлов по типу', 'doughnut', $byType);
        }

        if ($media !== [] && (str_contains($lower, 'месяц') || str_contains($lower, 'дата') || str_contains($lower, 'время') || str_contains($lower, 'динамик'))) {
            $byMonth = [];
            foreach ($media as $row) {
                $at = is_string($row['message_at'] ?? null) ? substr((string) $row['message_at'], 0, 7) : null;
                if ($at === null || $at === '') {
                    continue;
                }
                $byMonth[$at] = ($byMonth[$at] ?? 0) + 1;
            }
            ksort($byMonth);
            if ($byMonth !== []) {
                $items[] = $this->chartFromMap('Файлы по месяцам', 'bar', $byMonth);
            }
        }

        if ($contacts !== [] && (str_contains($lower, 'контакт') || str_contains($lower, 'клиент') || str_contains($lower, 'непрочит') || $media === [])) {
            $rows = $contacts;
            usort($rows, fn (array $a, array $b): int => ((int) ($b['unread_count'] ?? 0)) <=> ((int) ($a['unread_count'] ?? 0)));
            $top = array_slice($rows, 0, min(10, count($rows)));
            $map = [];
            foreach ($top as $row) {
                $name = trim((string) ($row['name'] ?? 'Клиент'));
                $map[mb_strlen($name) > 22 ? mb_substr($name, 0, 20).'…' : $name] = (int) ($row['unread_count'] ?? 0);
            }
            if (array_sum($map) > 0) {
                $items[] = $this->chartFromMap('Непрочитанные сообщения по контактам', 'bar', $map);
            } else {
                $items[] = $this->chartFromMap('Найденные контакты', 'bar', array_fill_keys(
                    array_map(
                        fn (array $r): string => mb_strlen((string) ($r['name'] ?? 'Клиент')) > 22
                            ? mb_substr((string) ($r['name'] ?? 'Клиент'), 0, 20).'…'
                            : (string) ($r['name'] ?? 'Клиент'),
                        array_slice($contacts, 0, min(10, count($contacts))),
                    ),
                    1,
                ));
            }
        }

        if ($items === [] && $media !== []) {
            $byType = [];
            foreach ($media as $row) {
                $label = $this->mimeCategoryLabel(is_string($row['mime_type'] ?? null) ? (string) $row['mime_type'] : '');
                $byType[$label] = ($byType[$label] ?? 0) + 1;
            }
            $items[] = $this->chartFromMap('Распределение файлов по типу', 'doughnut', $byType);
        }

        if ($items === [] && $contacts !== []) {
            $map = [];
            foreach (array_slice($contacts, 0, min(10, count($contacts))) as $row) {
                $name = trim((string) ($row['name'] ?? 'Клиент'));
                $map[mb_strlen($name) > 22 ? mb_substr($name, 0, 20).'…' : $name] = 1;
            }
            $items[] = $this->chartFromMap('Найденные контакты', 'bar', $map);
        }

        return $items;
    }

    /**
     * @param  array<string, int>  $map
     * @return array<string, mixed>
     */
    private function chartFromMap(string $title, string $chartType, array $map): array
    {
        $labels = array_keys($map);
        $data = array_values($map);

        return [
            'id' => 'auto_'.substr(sha1($title.json_encode($map)), 0, 10),
            'type' => 'chart',
            'title' => $title,
            'chart_type' => $chartType,
            'labels' => array_slice($labels, 0, self::MAX_LABELS),
            'datasets' => [
                [
                    'label' => $title,
                    'data' => array_slice($data, 0, self::MAX_LABELS),
                ],
            ],
        ];
    }

    private function mimeCategoryLabel(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'Фото';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'Видео';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'Аудио';
        }
        if (str_contains($mime, 'pdf')) {
            return 'PDF';
        }
        if (str_contains($mime, 'word') || str_contains($mime, 'document')) {
            return 'Документ';
        }

        return 'Другое';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @return list<int|float>
     */
    private function numericList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_numeric($item)) {
                continue;
            }
            $out[] = (float) $item;
        }

        return $out;
    }
}
