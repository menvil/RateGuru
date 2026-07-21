# RateGuru infrastructure

Project-specific infrastructure for RateGuru.

## Contents

- deployment and rollback scripts;
- local and offsite backup scripts;
- Nginx configuration;
- PHP-FPM pools;
- Supervisor queue workers;
- cron configuration;
- sudoers and SSH restrictions;
- environment variable templates;
- operational runbooks.

## Secrets are not stored here

Never commit:

- real `.env` files;
- PostgreSQL passwords;
- private SSH keys;
- `authorized_keys`;
- Backblaze credentials;
- `rclone.conf`;
- Basic Auth password files;
- PostgreSQL dumps;
- uploaded media.

## Deployment configuration

Install the non-secret deployment configuration before installing or running
the scripts that source `scripts/common`:

```bash
sudo install -d -o root -g root -m 0755 /home/www/rateguru/config
sudo install -o root -g root -m 0640 \
    infrastructure/templates/deployment.conf.example \
    /home/www/rateguru/config/deployment.conf
```

The runtime configuration must be a regular file owned by root:root and must
not be writable by group or others. Modes such as `0600`, `0640`, and `0644`
are accepted.
