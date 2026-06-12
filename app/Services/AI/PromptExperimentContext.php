<?php

declare(strict_types=1);

namespace App\Services\AI;

final readonly class PromptExperimentContext
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public int $experimentId,
        public string $variantKey,
        public array $config,
    ) {}

    public function promptAddon(): string
    {
        return trim((string) ($this->config['prompt_addon'] ?? ''));
    }

    public function temperatureOverride(): ?float
    {
        if (! isset($this->config['temperature'])) {
            return null;
        }

        return (float) $this->config['temperature'];
    }
}
