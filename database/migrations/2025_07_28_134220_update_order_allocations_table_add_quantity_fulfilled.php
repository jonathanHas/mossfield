<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_allocations', function (Blueprint $table) {
            // Add quantity_fulfilled column
            $table->integer('quantity_fulfilled')->default(0)->after('quantity_allocated');
            
            // Add notes column for allocation notes
            $table->text('notes')->nullable()->after('fulfilled_at');
            
            // Drop the old is_fulfilled boolean column if it exists
            if (Schema::hasColumn('order_allocations', 'is_fulfilled')) {
                $table->dropColumn('is_fulfilled');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_allocations', function (Blueprint $table) {
            $table->dropColumn(['quantity_fulfilled', 'notes']);
            $table->boolean('is_fulfilled')->default(false);
        });
    }
};