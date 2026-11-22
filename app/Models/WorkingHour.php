<?php

namespace App\Models;

use App\Enums\Weekday;
use Database\Factories\WorkingHourFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One block of the recurring weekly schedule of an employee (ISSUE-9), e.g.
 * "Monday 09:00-12:30". A day with a lunch break has two blocks. See
 * docs/erd.md.
 *
 * Times are local wall-clock time (Europe/Amsterdam) and always read as
 * "HH:MM". A block ends on the day it starts: night shifts past midnight
 * are not supported.
 *
 * @property int $id
 * @property int $employee_id
 * @property Weekday $weekday
 * @property string $starts_at "HH:MM"
 * @property string $ends_at "HH:MM"
 */
class WorkingHour extends Model
{
    /** @use HasFactory<WorkingHourFactory> */
    use HasFactory;

    /**
     * `employee_id` is not fillable: blocks are created through
     * `$employee->workingHours()`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'weekday',
        'starts_at',
        'ends_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function startsAt(): Attribute
    {
        return self::timeAttribute();
    }

    /**
     * @return Attribute<string, string>
     */
    protected function endsAt(): Attribute
    {
        return self::timeAttribute();
    }

    /**
     * Minutes since midnight at which the block starts, e.g. 540 for 09:00.
     */
    public function startMinutes(): int
    {
        return self::toMinutes($this->starts_at);
    }

    /**
     * Minutes since midnight at which the block ends.
     */
    public function endMinutes(): int
    {
        return self::toMinutes($this->ends_at);
    }

    /**
     * "09:00 - 12:30".
     */
    public function label(): string
    {
        return "{$this->starts_at} - {$this->ends_at}";
    }

    /**
     * "09:00" -> 540.
     */
    public static function toMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', self::normalizeTime($time));

        return (int) $hours * 60 + (int) $minutes;
    }

    /**
     * Accepts "9:00", "09:00" or "09:00:00" and returns "09:00". Seconds are
     * dropped: the schedule works in whole minutes.
     */
    public static function normalizeTime(string $time): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($time), $matches) !== 1) {
            throw new InvalidArgumentException("Invalid time \"{$time}\", expected HH:MM.");
        }

        return sprintf('%02d:%s', (int) $matches[1], $matches[2]);
    }

    /**
     * Stored as "HH:MM:00" so SQLite and MySQL (TIME) hold the same value,
     * read back as "HH:MM".
     *
     * @return Attribute<string, string>
     */
    private static function timeAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => self::normalizeTime($value),
            set: fn (string $value): string => self::normalizeTime($value).':00',
        );
    }
}
