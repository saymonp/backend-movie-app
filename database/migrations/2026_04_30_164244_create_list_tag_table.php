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
        Schema::create('list_tag', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('list_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('tag_id')
                ->constrained()
                ->onDelete('cascade');

            $table->primary(['list_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('list_tag');
    }
};
