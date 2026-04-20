<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessIbgeChallengeCommandTest extends TestCase
{
    public function test_command_generates_resultado_csv_and_stats_without_submit(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                [
                    'id' => 3303302,
                    'nome' => 'Niterói',
                    'microrregiao' => [
                        'mesorregiao' => [
                            'UF' => [
                                'sigla' => 'RJ',
                                'regiao' => ['nome' => 'Sudeste'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 3550308,
                    'nome' => 'São Paulo',
                    'microrregiao' => [
                        'mesorregiao' => [
                            'UF' => [
                                'sigla' => 'SP',
                                'regiao' => ['nome' => 'Sudeste'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $inputPath = storage_path('app/test_input.csv');
        $outputPath = storage_path('app/test_resultado.csv');

        file_put_contents($inputPath, "municipio,populacao\nNiteroi,100\nSao Paulo,200\nMunicipio Inexistente,300\n");

        $this->artisan('ibge:process', [
            '--input' => $inputPath,
            '--output' => $outputPath,
            '--no-submit' => true,
        ])
            ->expectsOutputToContain('resultado.csv gerado em:')
            ->assertExitCode(0);

        $this->assertFileExists($outputPath);

        $file = new \SplFileObject($outputPath);
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);

        $rows = [];
        foreach ($file as $csvRow) {
            if (! is_array($csvRow) || $csvRow === [null]) {
                continue;
            }
            $rows[] = $csvRow;
        }

        $this->assertSame('OK', $rows[1][6]);
        $this->assertSame('Niterói', $rows[1][2]);
        $this->assertSame('NAO_ENCONTRADO', $rows[3][6]);
    }
}
