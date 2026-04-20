# Como Rodar

```bash
cd nasajon
composer install
cp .env.example .env
php artisan ibge:process --token=SEU_ACCESS_TOKEN
```

Saídas:

- `storage/app/resultado.csv`
- `stats`, `score` e `feedback` no console

Teste (PHPUnit):

```bash
./vendor/bin/phpunit
```
