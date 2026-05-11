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
        if (!Schema::hasTable('voucher_details')) {
            Schema::create('voucher_details', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();

                $table->unsignedBigInteger('voucher_id');
                $table->unsignedBigInteger('account_head_id');

                $table->string('narration', 255)->nullable();
                $table->string('doc_no', 255)->nullable();

                $table->decimal('debit', 18, 8)->default(0);
                $table->decimal('credit', 18, 8)->default(0);

                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('taxable_amount', 18, 2)->default(0);
                $table->decimal('non_taxable_amount', 18, 2)->default(0);
                $table->decimal('vat_rate', 5, 2)->default(13.00);

                $table->enum('payment_mode', [
                    'cash',
                    'bank',
                    'credit',
                    'cheque',
                    'upi'
                ])->nullable();

                $table->unsignedBigInteger('payment_account_id')->nullable();



                $table->auditFields();


                $table->index('voucher_id');
                $table->index('account_head_id');
                $table->index('company_id');
                $table->index('branch_id');

            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_details');
    }
};
