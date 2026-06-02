# Admin User Creation

## Command

Create the first production admin explicitly:

```bash
php artisan rateguru:admin:create \
  --email=admin@example.com \
  --username=admin \
  --name="Admin User"
```

The command prompts for a hidden password and confirmation.

## Non-interactive usage

`--password` exists for tests or controlled non-interactive environments:

```bash
php artisan rateguru:admin:create \
  --email=admin@example.com \
  --username=admin \
  --name="Admin User" \
  --password="replace-with-a-strong-unique-password"
```

## Production rules

- Do not use demo admin credentials in production.
- Use a strong unique password.
- The password is hashed before storage.
- The command fails if the email already exists.
- The command fails if the username already exists.
