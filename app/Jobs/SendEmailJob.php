<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Resend; // Certifique-se de que a Facade ou classe do SDK está importada corretamente

class SendEmailJob implements ShouldQueue
{
    use Queueable;

    // Configurações de resiliência padrão para produção
    public $tries = 3;
    public $backoff = 60; // Aguarda 1 minuto antes de tentar de novo se a API falhar

    /**
     * O construtor recebe os dados dinâmicos do e-mail.
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $htmlContent,
        public ?string $from = null
    ) {
        $this->from = $from ?? config('mail.from.address');
    }

    /**
     * Executa o envio do e-mail.
     */
    public function handle(): void
    {
        Log::info("Iniciando envio de e-mail para: {$this->to}");

        try {
            $resend = Resend::client(config('services.resend.key'));

            $resend->emails->send([
                'from'    => $this->from,
                'to'      => $this->to,
                'subject' => $this->subject,
                'html'    => $this->htmlContent,
            ]);

            Log::info("E-mail enviado com sucesso para: {$this->to}");
            
        } catch (\Exception $e) {
            Log::error("Falha ao enviar e-mail para {$this->to}: " . $e->getMessage());
            
            // Lança a exception para o Laravel entender que o job falhou e deve entrar no backoff/retry
            throw $e; 
        }
    }
}