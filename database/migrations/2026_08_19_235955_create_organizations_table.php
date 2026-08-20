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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('slug', 160)->unique();

            $table->string('email', 190)->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path', 255)->nullable();

            $table->string('status', 30)
                ->default('trial')
                ->index();

            $table->dateTime('trial_ends_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
