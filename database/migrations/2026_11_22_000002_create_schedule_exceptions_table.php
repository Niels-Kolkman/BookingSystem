<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Date-specific deviations from the weekly schedule (ISSUE-10, see
     * docs/erd.md): time off (`unavailable`) or extra hours (`extra_hours`).
     * Dates and times are local wall-clock values (Europe/Amsterdam). Both
     * times null means the whole day (only for `unavailable`).
     *
     * Dutch public holidays are not stored here: they are calculated in code
     * (App\Scheduling\DutchHolidays).
     */
    public function up(): void
    {
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['unavailable', 'extra_hours']);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
    }
};
