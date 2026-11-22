<?php

namespace App\Models;

use App\Enums\ScheduleExceptionType;
use App\Scheduling\DutchHolidays;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleExceptionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

/**
 * A date-specific deviation from the weekly schedule of an employee
 * (ISSUE-10): time off (`unavailable`, whole day or a range) or extra hours
 * (`extra_hours`, always a range). See docs/erd.md and DaySchedule for how
 * they combine with the weekly schedule and public holidays.
 *
 * `date` is a local calendar date (Europe/Amsterdam), stored as "Y-m-d".
 * Times are local "HH:MM", both null for a whole day.
 *
 * @property int $id
 * @property int $employee_id
 * @property CarbonImmutable $date
 * @property ScheduleExceptionType $type
 * @property string|null $starts_at "HH:MM"
 * @property string|null $ends_at "HH:MM"
 * @property string|null $reason
 */
class ScheduleException extends Model
{
    /** @use HasFactory<ScheduleExceptionFactory> */
    use HasFactory;

    /**
     * `employee_id` is not fillable: exceptions are created through
     * `$employee->scheduleExceptions()`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'type',
        'starts_at',
        'ends_at',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ScheduleExceptionType::class,
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isWholeDay(): bool
    {
        return $this->starts_at === null || $this->ends_at === null;
    }

    /**
     * The affected time of day; the whole day when no times are set.
     */
    public function range(): TimeRange
    {
        return $this->isWholeDay()
            ? TimeRange::wholeDay()
            : TimeRange::fromTimes((string) $this->starts_at, (string) $this->ends_at);
    }

    /**
     * "Hele dag" or "09:00 - 12:30".
     */
    public function timeLabel(): string
    {
        return $this->isWholeDay() ? 'Hele dag' : "{$this->starts_at} - {$this->ends_at}";
    }

    /**
     * Exceptions on one local date.
     *
     * @param  Builder<ScheduleException>  $query
     */
    #[Scope]
    protected function onDate(Builder $query, DateTimeInterface $date): void
    {
        $query->where('date', $date->format('Y-m-d'));
    }

    /**
     * Exceptions on or after a local date, in date and time order.
     *
     * @param  Builder<ScheduleException>  $query
     */
    #[Scope]
    protected function fromDate(Builder $query, DateTimeInterface $date): void
    {
        $query->where('date', '>=', $date->format('Y-m-d'))
            ->orderBy('date')
            ->orderBy('starts_at');
    }

    /**
     * Stored as "Y-m-d" on SQLite and MySQL alike, so plain string
     * comparisons in queries work; read as midnight in Europe/Amsterdam.
     *
     * @return Attribute<CarbonImmutable, DateTimeInterface|string>
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): CarbonImmutable => CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), DutchHolidays::TIMEZONE)
                ?: throw new UnexpectedValueException("Invalid date \"{$value}\"."),
            set: fn (DateTimeInterface|string $value): string => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : CarbonImmutable::parse($value, DutchHolidays::TIMEZONE)->format('Y-m-d'),
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function startsAt(): Attribute
    {
        return self::nullableTimeAttribute();
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function endsAt(): Attribute
    {
        return self::nullableTimeAttribute();
    }

    /**
     * Same storage as WorkingHour: written as "HH:MM:00", read as "HH:MM".
     *
     * @return Attribute<string|null, string|null>
     */
    private static function nullableTimeAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : WorkingHour::normalizeTime($value),
            set: fn (?string $value): ?string => $value === null || $value === '' ? null : WorkingHour::normalizeTime($value).':00',
        );
    }
}
