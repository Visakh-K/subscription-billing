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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->timestamp('billing_period_start');
            $table->timestamp('billing_period_end');
            $table->decimal('base_amount', 10, 2);
            $table->decimal('overage_amount', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['draft', 'issued', 'paid'])->default('draft');
            $table->timestamps();

            $table->index(['merchant_id', 'billing_period_start']);
            $table->unique(
                ['subscription_id', 'billing_period_start', 'billing_period_end'],
                'invoices_subscription_period_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
