<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Copy value to project_number for existing projects where project_number is null.
     */
    public function up(): void
    {
        // Copy value to project_number for all projects where project_number is null
        DB::table('projects')
            ->whereNull('project_number')
            ->whereNotNull('value')
            ->update(['project_number' => DB::raw('value')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse this data migration
        // The project_number column will retain the copied values
    }
};
