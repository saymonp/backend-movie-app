<?php

namespace Tests\Feature;

use App\Jobs\ProcessMovieImagesJob;
use App\Jobs\SendEmailJob;
use App\Http\Controllers\LoginController;
use App\Models\Movie;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SendEmailJob::class)]
class SendEmailJobTest extends TestCase
{
    /**
     * Cenário 1: Caminho feliz. O filme é baixado com sucesso, persistido no banco 
     * com status 'processado', e os sub-jobs de imagem são despachados.
     */
    public function test_deve_despachar_job_de_enviar_email(): void
    {
        Queue::fake([SendEmailJob::class]);

        SendEmailJob::dispatch(
            'lemaka7085@nuitx.com',
            'Bem-vindo ao Catálogo de Filmes!',
            '<h1>Olá Lemaka!</h1><p>Seu cadastro foi realizado com sucesso.</p>'
        );

        Queue::assertPushed(SendEmailJob::class, function ($job) {
            return $job->to === 'lemaka7085@nuitx.com' && $job->subject === 'Bem-vindo ao Catálogo de Filmes!';
        });
    }
}
