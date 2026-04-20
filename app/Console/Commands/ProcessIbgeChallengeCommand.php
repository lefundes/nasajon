<?php

namespace App\Console\Commands;

use App\Services\ChallengeProcessor;
use App\Services\SupabaseSubmissionService;
use Illuminate\Console\Command;

class ProcessIbgeChallengeCommand extends Command
{
    protected $signature = 'ibge:process
        {--input=storage/app/input.csv : Caminho para input.csv}
        {--output=storage/app/resultado.csv : Caminho para resultado.csv}
        {--token= : ACCESS_TOKEN do Supabase}
        {--submit-url= : URL da Edge Function para envio das estatísticas}
        {--no-submit : Não envia as estatísticas para a API de correção}';

    protected $description = 'Processa municípios com API IBGE, gera resultado.csv, calcula stats e envia para correção';

    public function __construct(
        private readonly ChallengeProcessor $processor,
        private readonly SupabaseSubmissionService $submissionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $inputPath = $this->resolvePath((string) $this->option('input'));
        $outputPath = $this->resolvePath((string) $this->option('output'));

        $this->info('Processando arquivo de entrada...');

        try {
            $result = $this->processor->process($inputPath, $outputPath);
        } catch (\Throwable $e) {
            $this->error('Erro no processamento: '.$e->getMessage());

            return self::FAILURE;
        }

        $stats = $result['stats'];

        $this->info('resultado.csv gerado em: '.$outputPath);
        $this->line(json_encode(['stats' => $stats], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($this->option('no-submit')) {
            $this->warn('Envio para API de correção desabilitado (--no-submit).');

            return self::SUCCESS;
        }

        $submitUrl = (string) ($this->option('submit-url') ?: env('SUPABASE_FUNCTION_URL', 'https://mynxlubykylncinttggu.functions.supabase.co/ibge-submit'));
        $token = (string) ($this->option('token') ?: env('SUPABASE_ACCESS_TOKEN', ''));

        if ($token === '') {
            $this->error('ACCESS_TOKEN ausente. Use --token=... ou configure SUPABASE_ACCESS_TOKEN no .env');

            return self::FAILURE;
        }

        try {
            $payload = $this->submissionService->submit($submitUrl, $token, $stats);
        } catch (\Throwable $e) {
            $this->error('Falha no envio para correção: '.$e->getMessage());

            return self::FAILURE;
        }

        $score = $payload['score'] ?? 'N/A';
        $feedback = $payload['feedback'] ?? '';

        $this->info('Resposta da API de correção:');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Score: '.$score);
        if ($feedback !== '') {
            $this->line('Feedback: '.$feedback);
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
