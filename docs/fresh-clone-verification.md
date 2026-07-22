# Fresh Clone Verification

Use this checklist to verify RateGuru from a clean clone.

```bash
git clone https://github.com/menvil/rateguru.git rateguru-check
cd rateguru-check

brew install php
php -v
composer install
cp .env.example .env
php artisan key:generate

brew install postgresql@18
brew services start postgresql@18
createuser --createdb --login rateguru
psql postgres -c "ALTER ROLE rateguru PASSWORD 'rateguru';"
createdb --owner=rateguru rateguru
createdb --owner=rateguru rateguru_test
php artisan migrate

npm install
npm run build

composer test
php artisan serve
```

Expected result:

- `composer install` passes.
- `php -v` reports PHP 8.5 or newer.
- Homebrew PostgreSQL 18.4 becomes healthy.
- Migrations pass.
- `npm run build` passes.
- Tests pass.
- Home page opens.
- `/admin` redirects guest users or asks for authentication.
