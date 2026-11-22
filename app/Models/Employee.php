<?php

namespace App\Models;

use App\Enums\Weekday;
use App\Scheduling\DaySchedule;
use Database\Factories\EmployeeFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bookable profile of a user with the employee role. See docs/erd.md.
 *
 * @property int $id
 * @property int $user_id
 * @property string $display_name
 * @property bool $is_active
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * `user_id` is not fillable: linking a profile to a login is done
     * explicitly by an admin, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'display_name',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Services this employee can perform (ISSUE-8). Admins manage the links
     * on the service form.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    /**
     * Recurring weekly schedule (ISSUE-9): blocks of local time per weekday,
     * in time order. Admins manage it on the schedule form.
     *
     * @return HasMany<WorkingHour, $this>
     */
    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class)
            ->orderBy('weekday')
            ->orderBy('starts_at');
    }

    /**
     * Date-specific deviations from the weekly schedule (ISSUE-10): time off
     * and extra hours, in date and time order.
     *
     * @return HasMany<ScheduleException, $this>
     */
    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class)
            ->orderBy('date')
            ->orderBy('starts_at');
    }

    /**
     * The blocks this employee normally works on the given weekday, in time
     * order; empty on a day off. This is the regular schedule only; use
     * scheduleOn() for a concrete date.
     *
     * @return Collection<int, WorkingHour>
     */
    public function workingHoursOn(Weekday $weekday): Collection
    {
        return $this->workingHours
            ->filter(fn (WorkingHour $block): bool => $block->weekday === $weekday)
            ->values();
    }

    /**
     * The hours this employee works on a concrete local date: the weekly
     * schedule with Dutch public holidays and schedule exceptions applied
     * (ISSUE-10, see DaySchedule). The slot calculation (ISSUE-11) starts
     * here and still has to subtract existing bookings.
     */
    public function scheduleOn(DateTimeInterface $localDate): DaySchedule
    {
        return DaySchedule::for($this, $localDate);
    }

    /**
     * Whether this employee can be booked for the given service: the
     * employee is active and linked to it. Booking creation (ISSUE-12) and
     * the slot calculation (ISSUE-11) must check this.
     */
    public function canPerform(Service $service): bool
    {
        return $this->is_active
            && $this->services()->whereKey($service->getKey())->exists();
    }

    /**
     * Only employees customers can book.
     *
     * @param  Builder<Employee>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Employees linked to the given service.
     *
     * @param  Builder<Employee>  $query
     */
    #[Scope]
    protected function offering(Builder $query, Service $service): void
    {
        $query->whereHas('services', fn (Builder $q) => $q->whereKey($service->getKey()));
    }
}
