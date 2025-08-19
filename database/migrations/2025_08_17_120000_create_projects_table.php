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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('agent_id')->constrained('users')->onDelete('cascade');
            $table->string('city');
            $table->text('description')->nullable();
            $table->string('contract_number')->unique();
            $table->date('contract_date');
            $table->decimal('contract_amount', 15, 2);
            $table->decimal('agent_rate', 8, 2);
            $table->enum('agent_rate_type', ['percentage', 'fixed']);
            $table->date('planned_completion');
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
