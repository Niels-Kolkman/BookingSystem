<?php

namespace App\Http\Requests\Admin;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a service. Access is limited to admins by the `role`
 * middleware on the route group.
 */
class ServiceRequest extends FormRequest
{
    public const MAX_DURATION_MINUTES = 480;

    public const MAX_BUFFER_MINUTES = 120;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = $this->route('service');

        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique(Service::class, 'name')->ignore($service instanceof Service ? $service->id : null),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:'.self::MAX_DURATION_MINUTES],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_BUFFER_MINUTES],
            // Euros with an optional comma or dot and at most two decimals,
            // e.g. "25", "25,5" or "25.50". Stored as cents.
            'price' => ['required', 'string', 'regex:/^\d{1,4}([.,]\d{1,2})?$/'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.regex' => 'Vul de prijs in euro\'s in, bijvoorbeeld 25 of 25,50 (maximaal 9999,99).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'naam',
            'description' => 'omschrijving',
            'duration_minutes' => 'duur',
            'buffer_minutes' => 'buffertijd',
            'price' => 'prijs',
        ];
    }

    /**
     * Attributes for the Service model, with the price converted to cents.
     *
     * @return array{name: string, description: string|null, duration_minutes: int, buffer_minutes: int, price_cents: int, is_active: bool}
     */
    public function serviceAttributes(): array
    {
        /** @var array{name: string, description: string|null, duration_minutes: int|string, buffer_minutes: int|string, price: string, is_active?: bool} $data */
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'duration_minutes' => (int) $data['duration_minutes'],
            'buffer_minutes' => (int) $data['buffer_minutes'],
            'price_cents' => self::toCents($data['price']),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /**
     * "25,5" -> 2550. String based, so no float rounding errors.
     */
    public static function toCents(string $price): int
    {
        [$euros, $cents] = array_pad(explode('.', str_replace(',', '.', $price), 2), 2, '');

        return (int) $euros * 100 + (int) str_pad($cents, 2, '0');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'price' => trim((string) $this->input('price')),
            // An unchecked checkbox is not sent at all.
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
