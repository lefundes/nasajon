# Desafio Técnico IBGE - Laravel 12

Solução em Laravel 12 para processar `input.csv`, enriquecer municípios com a API de localidades do IBGE, gerar `resultado.csv`, calcular estatísticas e enviar o resultado para a Edge Function de correção do Supabase.

## Objetivo Atendido

- Ler `storage/app/input.csv`
- Resolver nomes com tolerância a acentos/caixa/erros de digitação
- Enriquecer com `municipio_ibge`, `uf`, `regiao`, `id_ibge`
- Gerar `storage/app/resultado.csv`
- Calcular `stats` no formato exigido
- Enviar `stats` com `Authorization: Bearer <ACCESS_TOKEN>`

## Arquitetura da Solução

- Entrada CLI: `app/Console/Commands/ProcessIbgeChallengeCommand.php`
- Regra de processamento CSV + estatísticas: `app/Services/ChallengeProcessor.php`
- Integração e matching com IBGE: `app/Services/IbgeMunicipioMatcher.php`
- Envio para correção Supabase: `app/Services/SupabaseSubmissionService.php`

A aplicação foi implementada como comando Artisan (`batch job`), sem interface web, por aderência direta ao formato do desafio.

## Pré-requisitos

- PHP 8.3+
- Composer

## Configuração

```bash
cp .env.example .env
```

Defina no `.env`:

```env
SUPABASE_FUNCTION_URL=https://mynxlubykylncinttggu.functions.supabase.co/ibge-submit
SUPABASE_ACCESS_TOKEN=
```

## Execução e Validação

```bash
composer install
cp .env.example .env
php artisan ibge:process --token=SEU_ACCESS_TOKEN
./vendor/bin/phpunit
```

Opções úteis de execução:

```bash
php artisan ibge:process --no-submit
php artisan ibge:process --input=storage/app/input.csv --output=storage/app/resultado.csv
```

Validações esperadas:

- `storage/app/resultado.csv` gerado com as colunas exigidas
- `stats` exibido no console
- resposta da Edge Function com `score` e `feedback` (quando executado com token)
- testes automatizados com `PHPUnit` passando (`OK`)

## Formato de Saída

`resultado.csv` contém:

- `municipio_input`
- `populacao_input`
- `municipio_ibge`
- `uf`
- `regiao`
- `id_ibge`
- `status` (`OK`, `NAO_ENCONTRADO`, `ERRO_API`, `AMBIGUO`)

No console, o comando imprime:

- JSON de `stats`
- resposta da Edge Function (`score`, `feedback`, componentes)
