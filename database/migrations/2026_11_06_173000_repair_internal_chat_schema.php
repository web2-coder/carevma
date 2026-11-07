<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $table) {
                $table->id();
                $table->timestamps();
            });
        } else {
            Schema::table('chat_conversations', function (Blueprint $table) {
                if (!Schema::hasColumn('chat_conversations', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('chat_conversations', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (!Schema::hasTable('chat_participants')) {
            Schema::create('chat_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('last_read_at')->nullable();
                $table->timestamps();
                $table->unique(['conversation_id', 'user_id'], 'chat_participants_conversation_user_unique');
            });
        } else {
            Schema::table('chat_participants', function (Blueprint $table) {
                if (!Schema::hasColumn('chat_participants', 'conversation_id')) {
                    $table->unsignedBigInteger('conversation_id')->nullable();
                }
                if (!Schema::hasColumn('chat_participants', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                if (!Schema::hasColumn('chat_participants', 'last_read_at')) {
                    $table->timestamp('last_read_at')->nullable();
                }
                if (!Schema::hasColumn('chat_participants', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('chat_participants', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->timestamps();
                $table->index(['conversation_id', 'created_at'], 'chat_messages_conversation_created_index');
            });
        } else {
            Schema::table('chat_messages', function (Blueprint $table) {
                if (!Schema::hasColumn('chat_messages', 'conversation_id')) {
                    $table->unsignedBigInteger('conversation_id')->nullable();
                }
                if (!Schema::hasColumn('chat_messages', 'sender_id')) {
                    $table->unsignedBigInteger('sender_id')->nullable();
                }
                if (!Schema::hasColumn('chat_messages', 'body')) {
                    $table->text('body')->nullable();
                }
                if (!Schema::hasColumn('chat_messages', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('chat_messages', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
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
        } else {
            Schema::table('ai_prompt_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('ai_prompt_logs', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                if (!Schema::hasColumn('ai_prompt_logs', 'prompt')) {
                    $table->text('prompt')->nullable();
                }
                if (!Schema::hasColumn('ai_prompt_logs', 'intent')) {
                    $table->string('intent')->nullable();
                }
                if (!Schema::hasColumn('ai_prompt_logs', 'response')) {
                    $table->longText('response')->nullable();
                }
                if (!Schema::hasColumn('ai_prompt_logs', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('ai_prompt_logs', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Repair migration is intentionally non-destructive.
    }
};
