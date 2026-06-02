# Storage Symlink

## Purpose

Uploaded public images must be accessible through Laravel's public storage symlink.

## Command

```bash
php artisan storage:link
```

## Expected result

```txt
public/storage -> storage/app/public
```

## Permissions

The web or PHP user must be able to write:

```txt
storage/app
storage/app/public
storage/logs
bootstrap/cache
```

## Verification

Upload a test image in local or staging and confirm the generated public URL opens.

## Production warning

Do not chmod 777 blindly. Use correct ownership and groups for the deploy user and web server.
