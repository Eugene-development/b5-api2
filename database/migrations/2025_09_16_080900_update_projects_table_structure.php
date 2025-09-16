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
        Schema::table('projects', function (Blueprint $table) {
            // Rename existing fields to match the required structure
            $table->renameColumn('name', 'value');

            // Add missing fields
            $table->string('phone')->nullable()->after('city');
            $table->boolean('is_active')->default(true)->after('status');

            // Update existing fields
            $table->text('description')->nullable()->change();
            $table->string('city')->nullable()->change();

            // Rename agent fields
            $table->renameColumn('agent_rate', 'agent_percentage');
            $table->renameColumn('planned_completion', 'planned_completion_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('value', 'name');
            $table->dropColumn(['phone', 'is_active']);
            $table->renameColumn('agent_percentage', 'agent_rate');
            $table->renameColumn('planned_completion_date', 'planned_completion');
        });
    }
};
