import { describe, expect, it } from 'vitest';
import { repairMermaidCode, stripMermaidFences } from './mermaidCode';

describe('stripMermaidFences', () => {
    it('removes markdown code fences', () => {
        expect(stripMermaidFences('```mermaid\nflowchart TD\n  A --> B\n```')).toBe(
            'flowchart TD\n  A --> B',
        );
    });
});

describe('repairMermaidCode', () => {
    it('quotes flowchart nodes with spaces in id', () => {
        expect(repairMermaidCode('flowchart TD\n  Открыть чат --> Ответить')).toBe(
            'flowchart TD\n  n1["Открыть чат"] --> n2["Ответить"]',
        );
    });

    it('keeps valid node syntax untouched', () => {
        expect(repairMermaidCode('flowchart TD\n  A[Клиент] --> B[Менеджер]')).toBe(
            'flowchart TD\n  A[Клиент] --> B[Менеджер]',
        );
    });

    it('quotes subgraph titles with spaces', () => {
        expect(repairMermaidCode('flowchart TD\n  subgraph Клиенты CRM\n    A --> B\n  end')).toBe(
            'flowchart TD\n  subgraph "Клиенты CRM"\n    A --> B\n  end',
        );
    });
});
