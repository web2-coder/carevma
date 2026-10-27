<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // User relation
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // 🔹 Basic Information
            $table->string('profile_picture')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');

            // 🔹 Personal Details
            $table->date('date_of_birth')->nullable();
            $table->integer('age')->nullable();
            $table->enum('gender', ['male','female','other'])->nullable();
            $table->string('marital_status')->nullable();
            $table->text('about_me_notes')->nullable();
            $table->string('upload_documents')->nullable();

            // 🔹 HR Work Information
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('location')->nullable();
            $table->unsignedBigInteger('employment_type_id')->nullable();
            $table->unsignedBigInteger('shift_type_id')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
            $table->unsignedBigInteger('employee_status_id')->nullable();

            $table->decimal('salary_pkr', 12, 2)->nullable();
            $table->decimal('salary_usd', 12, 2)->nullable();

            $table->string('source_of_hire')->nullable();
            $table->date('date_of_joining')->nullable();
            $table->date('date_of_regularisation')->nullable();

            $table->unsignedBigInteger('expertise_id')->nullable();
            $table->integer('break_allowed_hours')->nullable();

            // 🔹 Hierarchy
            $table->unsignedBigInteger('reporting_manager_id')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('employment_type_id')->references('id')->on('employment_types')->nullOnDelete();
            $table->foreign('shift_type_id')->references('id')->on('shift_types')->nullOnDelete();
            $table->foreign('designation_id')->references('id')->on('designations')->nullOnDelete();
            $table->foreign('employee_status_id')->references('id')->on('employee_statuses')->nullOnDelete();
            $table->foreign('expertise_id')->references('id')->on('expertises')->nullOnDelete();
            $table->foreign('reporting_manager_id')->references('id')->on('reporting_managers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};