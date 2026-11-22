<?php

namespace App\Http\Requests\Admin;

use App\Enums\Weekday;
use App\Models\WorkingHour;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Replace the weekly schedule of an employee (ISSUE-9). Access is limited to
 * admins by the `role` middleware on the route group.
 *
 * Input: `blocks[<weekday 1-7>][<n>][starts_at|ends_at]`, times as "HH:MM".
 * A row with both times empty is ignored, so the form can offer spare rows.
 * Weekdays without filled rows are days off.
 */
class ScheduleRequest extends FormRequest
{
    public const MAX_BLOCKS_PER_DAY = 6;

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
        $weekdays = implode(',', array_map(fn (Weekday $day): int => $day->value, Weekday::cases()));

        return [
            // Only weekday numbers are accepted as keys.
            'blocks' => ['array:'.$weekdays],
            'blocks.*' => ['array', 'max:'.self::MAX_BLOCKS_PER_DAY],
            'blocks.*.*' => ['array:starts_at,ends_at'],
            'blocks.*.*.starts_at' => ['nullable', 'string', 'regex:'.self::TIME_PATTERN, 'required_with:blocks.*.*.ends_at'],
            'blocks.*.*.ends_at' => ['nullable', 'string', 'regex:'.self::TIME_PATTERN, 'required_with:blocks.*.*.starts_at'],
        ];
    }

    /**
     * Checks that need the whole day: every block ends after it starts and
     * blocks on the same day do not overlap. Blocks may touch (12:30-12:30),
     * which leaves no gap but is not an error. Runs only when every time is
     * well-formed.
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

                foreach (self::filledRowsPerDay($data['blocks'] ?? []) as $weekday => $rows) {
                    $valid = [];

                    foreach ($rows as $index => [$start, $end]) {
                        if (WorkingHour::toMinutes($end) <= WorkingHour::toMinutes($start)) {
                            $validator->errors()->add(
                                "blocks.{$weekday}.{$index}.ends_at",
                                'De eindtijd moet na de begintijd liggen.',
                            );

                            continue;
                        }

                        $valid[$index] = [WorkingHour::toMinutes($start), WorkingHour::toMinutes($end)];
                    }

                    foreach ($valid as $index => [$start, $end]) {
                        foreach ($valid as $otherIndex => [$otherStart, $otherEnd]) {
                            if ($otherIndex !== $index && $start < $otherEnd && $end > $otherStart) {
                                $validator->errors()->add(
                                    "blocks.{$weekday}.{$index}.starts_at",
                                    'Dit blok overlapt met een ander blok op dezelfde dag.',
                                );

                                break;
                            }
                        }
                    }
                }
            },
        ];
    }

    /**
     * The validated blocks, sorted per weekday and start time.
     *
     * @return list<array{weekday: Weekday, starts_at: string, ends_at: string}>
     */
    public function blocks(): array
    {
        $blocks = [];

        foreach (self::filledRowsPerDay($this->validated('blocks', [])) as $weekday => $rows) {
            foreach ($rows as [$start, $end]) {
                $blocks[] = [
                    'weekday' => Weekday::from($weekday),
                    'starts_at' => WorkingHour::normalizeTime($start),
                    'ends_at' => WorkingHour::normalizeTime($end),
                ];
            }
        }

        usort($blocks, fn (array $a, array $b): int => [$a['weekday']->value, $a['starts_at']] <=> [$b['weekday']->value, $b['starts_at']]);

        return $blocks;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blocks.array' => 'Het rooster bevat een ongeldige weekdag.',
            'blocks.*.array' => 'De blokken van deze dag zijn ongeldig.',
            'blocks.*.max' => 'Je kunt maximaal :max blokken per dag invullen.',
            'blocks.*.*.array' => 'Dit blok is ongeldig.',
            'blocks.*.*.starts_at.regex' => 'Vul de begintijd in als UU:MM, bijvoorbeeld 09:00.',
            'blocks.*.*.ends_at.regex' => 'Vul de eindtijd in als UU:MM, bijvoorbeeld 17:00.',
            'blocks.*.*.starts_at.string' => 'Vul de begintijd in als UU:MM, bijvoorbeeld 09:00.',
            'blocks.*.*.ends_at.string' => 'Vul de eindtijd in als UU:MM, bijvoorbeeld 17:00.',
            'blocks.*.*.starts_at.required_with' => 'Vul ook een begintijd in.',
            'blocks.*.*.ends_at.required_with' => 'Vul ook een eindtijd in.',
        ];
    }

    /**
     * Rows with both times filled, per weekday number, keeping the row index
     * so errors land on the right input. Only call this with input that
     * passed the structural rules.
     *
     * @param  array<int|string, array<int|string, array{starts_at?: string|null, ends_at?: string|null}>>  $input
     * @return array<int, array<int, array{0: string, 1: string}>>
     */
    private static function filledRowsPerDay(array $input): array
    {
        $days = [];

        foreach ($input as $weekday => $rows) {
            foreach ($rows as $index => $row) {
                $start = $row['starts_at'] ?? null;
                $end = $row['ends_at'] ?? null;

                if ($start !== null && $end !== null) {
                    $days[(int) $weekday][(int) $index] = [$start, $end];
                }
            }
        }

        return $days;
    }
}
