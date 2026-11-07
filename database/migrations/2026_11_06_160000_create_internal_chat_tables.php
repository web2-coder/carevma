<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $table) {
                $table->id();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('chat_participants')) {
            Schema::create('chat_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('last_read_at')->nullable();
                $table->timestamps();
                $table->unique(['conversation_id','user_id']);
            });
        }
        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->timestamps();
                $table->index(['conversation_id','created_at']);
            });
        }
        if (!Schema::hasTable('ai_prompt_logs')) {
            Schema::create('ai_prompt_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('prompt');
                $table->string('intent')->nullable();
                $table->longText('response')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void {
        Schema::dropIfExists('ai_prompt_logs');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_conversations');
    }
};
