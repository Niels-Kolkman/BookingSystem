<?php

namespace App\Http\Requests\Admin;

use App\Enums\ScheduleExceptionType;
use App\Models\WorkingHour;
use App\Scheduling\DutchHolidays;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add a schedule exception for an employee (ISSUE-10). Access is limited to
 * admins by the `role` middleware on the route group.
 *
 * Input: `type` (unavailable|extra_hours), `date` and optional `until`
 * (Y-m-d, local) for a range of days such as a holiday week, optional
 * `starts_at`/`ends_at` ("HH:MM") and `reason`. Without times an
 * `unavailable` exception covers the whole day; extra hours always need
 * times. A range creates one exception per day.
 */
class ScheduleExceptionRequest extends FormRequest
{
    /** Longest range of days in one go, e.g. a long leave. */
    public const MAX_DAYS = 62;

    /** "09:00" or "9:00", 00:00 up to 23:59. */
    private const TIME_PATTERN = '/^([01]?\d|2[0-3]):[0-5]\d$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ScheduleExceptionType::class)],
            // Past dates are pointless: no slots are offered there.
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::today()],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'starts_at' => ['nullable', 'string', 'regex:'.self::TIME_PATTERN, 'required_with:ends_at', 'required_if:type,'.ScheduleExceptionType::ExtraHours->value],
            'ends_at' => ['nullable', 'string', 'regex:'.self::TIME_PATTERN, 'required_with:starts_at', 'required_if:type,'.ScheduleExceptionType::ExtraHours->value],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The end time must be after the start time, and a range of days may be
     * at most MAX_DAYS long. Runs only when every field is well-formed.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $data = $validator->getData();

                if (filled($data['starts_at'] ?? null) && filled($data['ends_at'] ?? null)
                    && WorkingHour::toMinutes($data['ends_at']) <= WorkingHour::toMinutes($data['starts_at'])) {
                    $validator->errors()->add('ends_at', 'De eindtijd moet na de begintijd liggen.');
                }

                if (filled($data['until'] ?? null)
                    && CarbonImmutable::parse($data['date'])->diffInDays(CarbonImmutable::parse($data['until'])) >= self::MAX_DAYS) {
                    $validator->errors()->add('until', 'Je kunt maximaal '.self::MAX_DAYS.' dagen tegelijk invoeren.');
                }
            },
        ];
    }

    /**
     * Every local date in the validated range, as "Y-m-d".
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $from = (string) $this->validated('date');
        $until = $this->validated('until') ?: $from;

        $dates = [];
        foreach (CarbonPeriod::create($from, $until) as $day) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Attributes shared by every exception created from this request.
     *
     * @return array{type: ScheduleExceptionType, starts_at: string|null, ends_at: string|null, reason: string|null}
     */
    public function exceptionAttributes(): array
    {
        $start = $this->validated('starts_at');
        $end = $this->validated('ends_at');
        $reason = $this->validated('reason');

        return [
            'type' => ScheduleExceptionType::from((string) $this->validated('type')),
            'starts_at' => filled($start) ? WorkingHour::normalizeTime((string) $start) : null,
            'ends_at' => filled($end) ? WorkingHour::normalizeTime((string) $end) : null,
            'reason' => filled($reason) ? trim((string) $reason) : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Kies het soort uitzondering.',
            'type.enum' => 'Kies het soort uitzondering.',
            'date.required' => 'Vul een datum in.',
            'date.date_format' => 'Vul de datum in als JJJJ-MM-DD.',
            'date.after_or_equal' => 'De datum mag niet in het verleden liggen.',
            'until.date_format' => 'Vul de datum in als JJJJ-MM-DD.',
            'until.after_or_equal' => 'De einddatum moet op of na de begindatum liggen.',
            'starts_at.regex' => 'Vul de begintijd in als UU:MM, bijvoorbeeld 09:00.',
            'ends_at.regex' => 'Vul de eindtijd in als UU:MM, bijvoorbeeld 17:00.',
            'starts_at.string' => 'Vul de begintijd in als UU:MM, bijvoorbeeld 09:00.',
            'ends_at.string' => 'Vul de eindtijd in als UU:MM, bijvoorbeeld 17:00.',
            'starts_at.required_with' => 'Vul ook een begintijd in.',
            'ends_at.required_with' => 'Vul ook een eindtijd in.',
            'starts_at.required_if' => 'Vul de begintijd van de extra uren in.',
            'ends_at.required_if' => 'Vul de eindtijd van de extra uren in.',
            'reason.string' => 'De omschrijving is ongeldig.',
            'reason.max' => 'De omschrijving mag maximaal :max tekens zijn.',
        ];
    }

    /**
     * Today in Europe/Amsterdam, so "today" does not flip at 01:00 local time.
     */
    public static function today(): string
    {
        return CarbonImmutable::now(DutchHolidays::TIMEZONE)->toDateString();
    }
}
