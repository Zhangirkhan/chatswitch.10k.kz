<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Database\Factories\Concerns\UsesTenantCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
final class ContactFactory extends Factory
{
    use UsesTenantCompany;

    protected $model = Contact::class;

    public function definition(): array
    {
        $digits = '77'.fake()->numerify('#########');

        return [
            'company_id' => $this->tenantCompanyId(),
            'whatsapp_id' => $digits.'@c.us',
            'phone_number' => $digits,
            'name' => fake()->name(),
            'push_name' => fake()->firstName(),
            'is_business' => false,
        ];
    }
}
