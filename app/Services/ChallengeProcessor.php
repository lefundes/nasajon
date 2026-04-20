<?php

namespace App\Services;

use RuntimeException;
use SplFileObject;

class ChallengeProcessor
{
    public function __construct(private readonly IbgeMunicipioMatcher $matcher) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, stats: array<string, mixed>}
     */
    public function process(string $inputPath, string $outputPath): array
    {
        $rows = $this->readInput($inputPath);

        try {
            $municipios = $this->matcher->fetchMunicipios();
            $index = $this->matcher->buildIndex($municipios);
            $processed = $this->processRows($rows, $index);
        } catch (\Throwable) {
            $processed = array_map(function (array $row): array {
                return [
                    'municipio_input' => $row['municipio_input'],
                    'populacao_input' => $row['populacao_input'],
                    'municipio_ibge' => '',
                    'uf' => '',
                    'regiao' => '',
                    'id_ibge' => '',
                    'status' => 'ERRO_API',
                ];
            }, $rows);
        }

        $this->writeOutput($outputPath, $processed);

        return [
            'rows' => $processed,
            'stats' => $this->calculateStats($processed),
        ];
    }

    /**
     * @return array<int, array{municipio_input: string, populacao_input: int}>
     */
    private function readInput(string $inputPath): array
    {
        if (! file_exists($inputPath)) {
            throw new RuntimeException("Arquivo de entrada não encontrado: {$inputPath}");
        }

        $file = new SplFileObject($inputPath);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $rows = [];
        $line = 0;

        foreach ($file as $csvRow) {
            if (! is_array($csvRow) || $csvRow === [null] || count($csvRow) < 2) {
                continue;
            }

            $line++;

            if ($line === 1) {
                continue;
            }

            $municipio = trim((string) $csvRow[0]);
            $populacao = (int) trim((string) $csvRow[1]);

            if ($municipio === '') {
                continue;
            }

            $rows[] = [
                'municipio_input' => $municipio,
                'populacao_input' => $populacao,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{municipio_input: string, populacao_input: int}>  $rows
     * @param  array<string, array<int, array<string, mixed>>>  $index
     * @return array<int, array<string, mixed>>
     */
    private function processRows(array $rows, array $index): array
    {
        $processed = [];

        foreach ($rows as $row) {
            $matchResult = $this->matcher->match($row['municipio_input'], $index);
            $match = $matchResult['match'];

            $processed[] = [
                'municipio_input' => $row['municipio_input'],
                'populacao_input' => $row['populacao_input'],
                'municipio_ibge' => $match['nome'] ?? '',
                'uf' => $match['uf'] ?? '',
                'regiao' => $match['regiao'] ?? '',
                'id_ibge' => $match['id'] ?? '',
                'status' => $matchResult['status'],
            ];
        }

        return $processed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeOutput(string $outputPath, array $rows): void
    {
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = new SplFileObject($outputPath, 'w');
        $file->fputcsv([
            'municipio_input',
            'populacao_input',
            'municipio_ibge',
            'uf',
            'regiao',
            'id_ibge',
            'status',
        ]);

        foreach ($rows as $row) {
            $file->fputcsv([
                $row['municipio_input'],
                $row['populacao_input'],
                $row['municipio_ibge'],
                $row['uf'],
                $row['regiao'],
                $row['id_ibge'],
                $row['status'],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function calculateStats(array $rows): array
    {
        $stats = [
            'total_municipios' => count($rows),
            'total_ok' => 0,
            'total_nao_encontrado' => 0,
            'total_erro_api' => 0,
            'pop_total_ok' => 0,
            'medias_por_regiao' => [],
        ];

        $sumByRegion = [];
        $countByRegion = [];

        foreach ($rows as $row) {
            $status = $row['status'];

            if ($status === 'OK') {
                $stats['total_ok']++;
                $stats['pop_total_ok'] += (int) $row['populacao_input'];

                $region = (string) ($row['regiao'] ?? '');
                if ($region !== '') {
                    $sumByRegion[$region] = ($sumByRegion[$region] ?? 0) + (int) $row['populacao_input'];
                    $countByRegion[$region] = ($countByRegion[$region] ?? 0) + 1;
                }
            } elseif ($status === 'NAO_ENCONTRADO' || $status === 'AMBIGUO') {
                $stats['total_nao_encontrado']++;
            } elseif ($status === 'ERRO_API') {
                $stats['total_erro_api']++;
            }
        }

        foreach ($sumByRegion as $region => $sum) {
            $stats['medias_por_regiao'][$region] = round($sum / $countByRegion[$region], 2);
        }

        ksort($stats['medias_por_regiao']);

        return $stats;
    }
}
