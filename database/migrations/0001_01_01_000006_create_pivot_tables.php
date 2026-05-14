<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('movie_generos', function (Blueprint $table) {
            $table->foreignId('movie_id')->constrained('movies')->onDelete('cascade');
            $table->foreignId('genero_id')->constrained('generos')->onDelete('cascade');
            $table->primary(['movie_id', 'genero_id']);
        });

        Schema::create('movie_estudios', function (Blueprint $table) {
            $table->foreignId('movie_id')->constrained('movies')->onDelete('cascade');
            $table->foreignId('estudio_id')->constrained('estudios')->onDelete('cascade');
            $table->primary(['movie_id', 'estudio_id']);
        });

        Schema::create('movie_diretores', function (Blueprint $table) {
            $table->foreignId('movie_id')->constrained('movies')->onDelete('cascade');
            $table->foreignId('diretor_id')->constrained('diretores')->onDelete('cascade');
            $table->primary(['movie_id', 'diretor_id']);
        });

        Schema::create('movie_paises', function (Blueprint $table) {
            $table->foreignId('movie_id')->constrained('movies')->onDelete('cascade');
            $table->foreignId('pais_id')->constrained('paises')->onDelete('cascade');
            $table->primary(['movie_id', 'pais_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_paises');
        Schema::dropIfExists('movie_diretores');
        Schema::dropIfExists('movie_estudios');
        Schema::dropIfExists('movie_generos');
    }
};
