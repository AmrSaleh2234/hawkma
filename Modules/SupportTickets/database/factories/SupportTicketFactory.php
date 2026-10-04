<?php

namespace Modules\SupportTickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SupportTickets\Models\SupportTicket;

class SupportTicketFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = SupportTicket::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [];
    }
}
