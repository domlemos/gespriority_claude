<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'slug' => self::slugPara($name),
        ];
    }

    /**
     * Slug do cargo gerado. A lista do Faker tem "Admin", e o slug `admin` é
     * o que `User::isAdmin()` reconhece: sem o sufixo, um papel "comum" de
     * teste viraria admin ao acaso. Quem quer admin passa `['slug' => 'admin']`.
     */
    public static function slugPara(string $nome): string
    {
        $slug = Str::slug($nome);

        return $slug === 'admin' ? 'admin-gerado' : $slug;
    }
}
