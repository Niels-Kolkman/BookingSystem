<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something that can be booked. Admins manage services in the admin area
 * (ServiceController). Duration plus buffer decide how long an employee is
 * blocked per booking, which the slot calculation builds on.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $duration_minutes
 * @property int $buffer_minutes
 * @property int $price_cents
 * @property bool $is_active
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'duration_minutes',
        'buffer_minutes',
        'price_cents',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Only services customers can book. Inactive services stay in the
     * database so existing bookings keep pointing at them.
     *
     * @param  Builder<Service>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Minutes an employee is unavailable for one booking of this service:
     * the appointment itself plus the buffer after it.
     */
    public function blockedMinutes(): int
    {
        return $this->duration_minutes + $this->buffer_minutes;
    }

    /**
     * Price for display, e.g. "€ 25,00".
     */
    public function formattedPrice(): string
    {
        return '€ '.number_format($this->price_cents / 100, 2, ',', '.');
    }
}
