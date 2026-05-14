<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('generos', function (Blueprint $table) {
            $table->id();
            $table->integer('tmdb_id')->unique();
            $table->text('nome_pt')->unique();
            $table->text('nome_en')->unique();
        });

        Schema::create('estudios', function (Blueprint $table) {
            $table->id();
            $table->text('nome')->unique();
        });

        Schema::create('diretores', function (Blueprint $table) {
            $table->id();
            $table->text('nome')->unique();
        });

        Schema::create('paises', function (Blueprint $table) {
            $table->id();
            $table->text('nome')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paises');
        Schema::dropIfExists('diretores');
        Schema::dropIfExists('estudios');
        Schema::dropIfExists('generos');
    }
};
