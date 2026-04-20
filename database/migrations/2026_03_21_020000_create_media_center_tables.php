<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_center_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('title');
            $table->longText('source_text');
            $table->string('language', 12)->default('vi');
            $table->string('main_character_name')->nullable();
            $table->text('main_character_profile')->nullable();
            $table->json('characters_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamps();
        });

        Schema::create('media_center_sentences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_center_project_id');
            $table->integer('sentence_index')->default(0);
            $table->longText('sentence_text');
            $table->longText('tts_text')->nullable();
            $table->text('image_prompt')->nullable();
            $table->text('video_prompt')->nullable();
            $table->text('character_notes')->nullable();
            $table->string('tts_provider', 30)->default('google');
            $table->string('tts_voice_gender', 20)->default('female');
            $table->string('tts_voice_name')->nullable();
            $table->decimal('tts_speed', 4, 2)->default(1.00);
            $table->string('tts_audio_path')->nullable();
            $table->string('image_provider', 30)->default('gemini');
            $table->string('image_path')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('media_center_project_id')
                ->references('id')
                ->on('media_center_projects')
                ->onDelete('cascade');
            $table->index(['media_center_project_id', 'sentence_index'], 'media_center_project_sentence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_center_sentences');
        Schema::dropIfExists('media_center_projects');
    }
};
