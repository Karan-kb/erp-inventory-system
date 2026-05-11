<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('vouchers')) {
            Schema::create('vouchers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();


                $table->string('voucher_number', 50)->nullable();

                $table->enum('voucher_type', [
                    'opening_stock',
                    'purchase',
                    'purchase_return',
                    'sale',
                    'sales_return',
                    'stock_adjustment',
                    'stock_transfer',
                    'stock_reconciliation',
                    'production',
                    'journal_voucher',
                    'receipt_voucher',
                    'payment_voucher'
                ]);

                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reference_type', 100)->nullable();

                $table->date('date')->nullable();
                $table->string('date_bs', 20)->nullable();

                $table->text('description')->nullable();

                $table->decimal('total_amount', 18, 2)->default(0);

                $table->boolean('is_cancel')->default(false);


                $table->auditFields();


                $table->index('voucher_type');
                $table->index('reference_id');
                $table->index('company_id');
                $table->index('branch_id');
                $table->index('date');

            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
