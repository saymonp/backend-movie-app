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
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->integer('tmdb_id')->unique();
            $table->text('imdb_id')->nullable();
            $table->text('titulo_original');

            // BR
            $table->text('titulo_br')->nullable();
            $table->text('descricao_br')->nullable();
            $table->text('tagline_br')->nullable();

            // EN
            $table->text('titulo_en')->nullable();
            $table->text('descricao_en')->nullable();
            $table->text('tagline_en')->nullable();

            $table->decimal('rating', 3, 1)->nullable();
            $table->integer('duracao')->nullable();
            $table->text('lingua_origem')->nullable();
            $table->bigInteger('revenue')->nullable();
            $table->decimal('popularity', 12, 4)->nullable();

            // Imagens
            $table->text('poster_path_br')->nullable();
            $table->text('poster_thumb_br')->nullable()->index(); // Index incluído aqui
            $table->text('backdrop_path')->nullable();
            $table->text('poster_path_us')->nullable();
            $table->text('poster_thumb_us')->nullable();

            $table->text('homepage')->nullable();
            $table->text('status')->nullable();

            // Slugs e Datas
            $table->text('slug_pt')->unique()->nullable();
            $table->text('slug_en')->unique()->nullable();
            $table->date('release_date')->nullable();

            // FK Coleção
            $table->foreignId('colecao_id')->nullable()->constrained('colecoes')->onDelete('set null');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            // Índices de busca
            $table->index('titulo_br');
            $table->index('titulo_en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
