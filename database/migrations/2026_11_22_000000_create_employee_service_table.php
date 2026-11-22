<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which employee can perform which service (ISSUE-8, see docs/erd.md).
     * The composite primary key prevents duplicate links and serves lookups
     * by employee; the extra index serves lookups by service.
     *
     * Links cascade on delete: they mean nothing without both sides. Services
     * can only be deleted while they have no bookings (ServiceController).
     */
    public function up(): void
    {
        Schema::create('employee_service', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            $table->primary(['employee_id', 'service_id']);
            $table->index('service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_service');
    }
};
