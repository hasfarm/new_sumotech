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
        if (Schema::hasTable('api_usages')) {
            return;
        }

        Schema::create('api_usages', function (Blueprint $table) {
            $table->id();
            $table->string('api_type');
            $table->string('api_endpoint')->nullable();
            $table->string('purpose');
            $table->text('description')->nullable();
            $table->string('status')->default('success');
            $table->text('error_message')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->integer('tokens_used')->nullable();
            $table->integer('characters_used')->nullable();
            $table->decimal('duration_seconds', 10, 2)->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();

            $table->index('api_type');
            $table->index('purpose');
            $table->index('status');
            $table->index('project_id');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep original behavior: do not drop table in safeguard rollback.
    }
};
