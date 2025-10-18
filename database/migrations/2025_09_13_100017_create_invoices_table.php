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
                $table->string('title');
                $table->text('comment')->nullable();
                $table->string('document');
                $table->string('status')->default('pending'); // pending/approved/rejected/completed
                $table->string('current_role')->default('accounts_1st');
                $table->timestamps();
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
