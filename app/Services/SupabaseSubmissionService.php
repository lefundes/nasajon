<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class SupabaseSubmissionService
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    public function submit(string $url, string $accessToken, array $stats): array
    {
        $response = $this->http
            ->timeout(20)
            ->withToken($accessToken)
            ->post($url, ['stats' => $stats]);

        if ($response->failed()) {
            throw new RuntimeException('Falha ao enviar estatísticas: '.$response->body());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Resposta inválida da Edge Function.');
        }

        return $payload;
    }
}
