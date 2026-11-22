<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Recurring weekly schedule per employee (ISSUE-9, see docs/erd.md).
     * Several rows per employee and weekday allow split shifts, e.g.
     * 09:00-12:30 and 13:30-17:00. Times are local wall-clock time
     * (Europe/Amsterdam), not UTC.
     *
     * Rows cascade when their employee is deleted: a schedule means nothing
     * without the employee.
     */
    public function up(): void
    {
        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // ISO-8601: 1 = Monday ... 7 = Sunday (App\Enums\Weekday).
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['employee_id', 'weekday']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('working_hours');
    }
};
