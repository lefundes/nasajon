<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use RuntimeException;

class IbgeMunicipioMatcher
{
    private const IBGE_API_URL = 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchMunicipios(): array
    {
        $response = $this->http->timeout(30)->retry(2, 500)->get(self::IBGE_API_URL);

        if ($response->failed()) {
            throw new RuntimeException('Falha ao consultar API do IBGE.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Resposta da API do IBGE em formato inesperado.');
        }

        return array_map(function (array $item): array {
            return [
                'nome' => $item['nome'] ?? null,
                'id' => $item['id'] ?? null,
                'uf' => $item['microrregiao']['mesorregiao']['UF']['sigla'] ?? null,
                'regiao' => $item['microrregiao']['mesorregiao']['UF']['regiao']['nome'] ?? null,
            ];
        }, $payload);
    }

    /**
     * @param  array<int, array<string, mixed>>  $municipios
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function buildIndex(array $municipios): array
    {
        $index = [];

        foreach ($municipios as $municipio) {
            if (! isset($municipio['nome'])) {
                continue;
            }

            $key = $this->normalize((string) $municipio['nome']);
            $index[$key][] = $municipio;
        }

        return $index;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $index
     * @return array{status: string, match: array<string, mixed>|null}
     */
    public function match(string $inputName, array $index): array
    {
        $normalizedInput = $this->normalize($inputName);

        if (isset($index[$normalizedInput])) {
            $exactMatches = $index[$normalizedInput];

            if (count($exactMatches) > 1) {
                // Desempate determinístico para nomes oficiais duplicados no IBGE.
                // Prioriza o maior ID (normalmente municípios mais conhecidos/antigos no cadastro consolidado).
                usort($exactMatches, fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

                return ['status' => 'OK', 'match' => $exactMatches[0]];
            }

            return ['status' => 'OK', 'match' => $exactMatches[0]];
        }

        return $this->fuzzyMatch($normalizedInput, $index);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $index
     * @return array{status: string, match: array<string, mixed>|null}
     */
    private function fuzzyMatch(string $normalizedInput, array $index): array
    {
        $inputCompact = str_replace(' ', '', $normalizedInput);
        $bestKey = null;
        $bestDistance = PHP_INT_MAX;
        $bestSimilarity = 0.0;
        $distanceTies = 0;

        foreach (array_keys($index) as $key) {
            $keyCompact = str_replace(' ', '', $key);
            $distance = levenshtein($inputCompact, $keyCompact);
            similar_text($inputCompact, $keyCompact, $similarity);

            if ($distance < $bestDistance || ($distance === $bestDistance && $similarity > $bestSimilarity)) {
                $bestDistance = $distance;
                $bestSimilarity = $similarity;
                $bestKey = $key;
                $distanceTies = 1;
            } elseif ($distance === $bestDistance && abs($similarity - $bestSimilarity) < 0.1) {
                $distanceTies++;
            }
        }

        if ($bestKey === null) {
            return ['status' => 'NAO_ENCONTRADO', 'match' => null];
        }

        $isReliable = $bestDistance <= 2 || ($bestDistance <= 3 && $bestSimilarity >= 82.0);

        if (! $isReliable) {
            return ['status' => 'NAO_ENCONTRADO', 'match' => null];
        }

        if ($distanceTies > 1 || count($index[$bestKey]) > 1) {
            return ['status' => 'AMBIGUO', 'match' => null];
        }

        return ['status' => 'OK', 'match' => $index[$bestKey][0]];
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9 ]/', ' ')
            ->squish()
            ->toString();
    }
}
