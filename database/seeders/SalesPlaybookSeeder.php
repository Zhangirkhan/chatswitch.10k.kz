<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SalesPlaybook;
use App\Models\SalesPlaybookStep;
use Illuminate\Database\Seeder;

final class SalesPlaybookSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'slug' => 'b2c_retail',
                'name' => 'B2C Retail',
                'industry_tags' => ['retail', 'b2c'],
                'qualification_fields' => ['requirements', 'budget'],
                'steps' => [
                    ['step_key' => 'ask_requirements', 'prompt_hint' => 'Уточни потребность и сценарий использования.'],
                    ['step_key' => 'ask_budget', 'prompt_hint' => 'Мягко спроси ориентир по бюджету.'],
                    ['step_key' => 'present_offer', 'prompt_hint' => 'Предложи 1–2 варианта с value.'],
                ],
            ],
            [
                'slug' => 'b2b_equipment',
                'name' => 'B2B Equipment',
                'industry_tags' => ['b2b', 'equipment'],
                'qualification_fields' => ['requirements', 'budget', 'decision_maker', 'timeline'],
                'steps' => [
                    ['step_key' => 'ask_requirements', 'prompt_hint' => 'Технические требования и объём.'],
                    ['step_key' => 'ask_budget', 'prompt_hint' => 'Бюджет или диапазон закупки.'],
                    ['step_key' => 'qualify', 'prompt_hint' => 'Кто принимает решение и сроки.'],
                    ['step_key' => 'book_appointment', 'prompt_hint' => 'Предложи демо или КП-встречу.'],
                ],
            ],
            [
                'slug' => 'services_booking',
                'name' => 'Services Booking',
                'industry_tags' => ['services'],
                'qualification_fields' => ['requirements', 'timeline'],
                'steps' => [
                    ['step_key' => 'ask_requirements', 'prompt_hint' => 'Что нужно и когда.'],
                    ['step_key' => 'book_appointment', 'prompt_hint' => 'Предложи слот в календаре.'],
                ],
            ],
            [
                'slug' => 'logistics',
                'name' => 'Logistics',
                'industry_tags' => ['logistics'],
                'qualification_fields' => ['requirements', 'timeline', 'budget'],
                'steps' => [
                    ['step_key' => 'ask_requirements', 'prompt_hint' => 'Маршрут, объём, SLA.'],
                    ['step_key' => 'present_offer', 'prompt_hint' => 'Дай ориентир по срокам и условиям.'],
                ],
            ],
        ];

        foreach ($defaults as $playbookData) {
            $playbook = SalesPlaybook::query()->updateOrCreate(
                ['company_id' => null, 'slug' => $playbookData['slug']],
                [
                    'name' => $playbookData['name'],
                    'industry_tags' => $playbookData['industry_tags'],
                    'qualification_fields' => $playbookData['qualification_fields'],
                    'stage_strategies' => [],
                    'is_active' => true,
                ],
            );

            SalesPlaybookStep::query()->where('sales_playbook_id', $playbook->id)->delete();

            foreach ($playbookData['steps'] as $position => $step) {
                SalesPlaybookStep::query()->create([
                    'sales_playbook_id' => $playbook->id,
                    'position' => $position,
                    'step_key' => $step['step_key'],
                    'prompt_hint' => $step['prompt_hint'],
                    'required_before_next' => [],
                ]);
            }
        }
    }
}
