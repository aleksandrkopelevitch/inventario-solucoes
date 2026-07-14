<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solutions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('vendor_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('category'); // Category enum
            $table->string('directorate')->nullable(); // string livre no MVP (40 valores)
            $table->string('journey')->nullable(); // Journey enum
            $table->string('support_type'); // SupportType enum: internal|third_party|hybrid
            $table->string('environment')->nullable(); // Environment enum: saas|saas_internal|on_premise
            $table->string('cloud')->nullable(); // Cloud enum: azure|gcp
            $table->string('contract_status'); // ContractStatus enum
            $table->text('support_operation_note')->nullable();
            $table->string('criticality')->nullable(); // Criticality enum, editavel
            $table->string('status'); // SolutionStatus enum
            $table->boolean('is_orchestrator')->default(false);
            $table->boolean('has_macro_architecture')->default(false);
            $table->boolean('has_detailed_components')->default(false);
            $table->boolean('has_business_flows')->default(false);
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->index(['category', 'status']);
            $table->index('directorate');
            $table->index('journey');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solutions');
    }
};
