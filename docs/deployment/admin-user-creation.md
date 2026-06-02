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

Set the `ADMIN_PASSWORD` environment variable to skip the interactive prompt:

```bash
ADMIN_PASSWORD="replace-with-a-strong-unique-password" php artisan rateguru:admin:create \
  --email=admin@example.com \
  --username=admin \
  --name="Admin User"
```

## Production rules

- Do not use demo admin credentials in production.
- Use a strong unique password.
- The password is hashed before storage.
- The command fails if the email already exists.
- The command fails if the username already exists.
