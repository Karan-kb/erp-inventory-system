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
        if (!Schema::hasTable('purchase_additionalbill_details')) {
            Schema::create('purchase_additionalbill_details', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('purchase_additionalbill_id');
                $table->unsignedBigInteger('account_head_id');
                $table->decimal('amount', 10, 2);
                $table->auditFields();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_additionalbill_details');
    }
};
