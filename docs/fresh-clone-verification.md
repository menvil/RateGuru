# Fresh Clone Verification

Use this checklist to verify RateGuru from a clean clone.

```bash
git clone https://github.com/menvil/rateguru.git rateguru-check
cd rateguru-check

composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate

npm install
npm run build

composer test
php artisan serve
```

Expected result:

- `composer install` passes.
- Migrations pass.
- `npm run build` passes.
- Tests pass.
- Home page opens.
- `/admin` redirects guest users or asks for authentication.
