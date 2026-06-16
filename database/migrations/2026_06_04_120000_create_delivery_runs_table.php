<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chilled delivery runs — fixed weekly routes (e.g. "Tuesday · Dublin").
     * Customers are assigned to a run with a stop position; the chilled run
     * sheet pairs each stop with the customer's order for the run's date.
     */
    public function up(): void
    {
        Schema::create('delivery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // route label, e.g. "Dublin"
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 1=Mon..7=Sun (ISO); null = whole-week ("w/c") run
            $table->string('driver')->nullable();
            $table->string('capacity_note')->nullable(); // e.g. "80 crates of milk is the max for delivery."
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_runs');
    }
};
