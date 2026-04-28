<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
final class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $digits = '77'.fake()->numerify('#########');

        return [
            'whatsapp_id' => $digits,
            'phone_number' => $digits,
            'name' => fake()->name(),
            'push_name' => fake()->firstName(),
            'is_business' => false,
        ];
    }
}
