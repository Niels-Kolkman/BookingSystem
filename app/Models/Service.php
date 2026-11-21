<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Something that can be booked. Managing services is ISSUE-7.
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
}
