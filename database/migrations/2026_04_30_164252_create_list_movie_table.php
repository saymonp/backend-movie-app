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
        Schema::create('list_movie', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('list_id')
                ->constrained()
                ->onDelete('cascade');
                
            $table->foreignId('movie_id')
                ->constrained()
                ->onDelete('cascade');

            $table->primary(['list_id', 'movie_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('list_movie');
    }
};
