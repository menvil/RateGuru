# RateGuru backup operations

## Offsite backup procedure

Run the complete local and offsite backup cycle for the required environment:

```bash
sudo /home/www/rateguru/bin/backup-cycle --environment staging
sudo /home/www/rateguru/bin/backup-cycle --environment production
```

The cycle creates a local backup, invokes `offsite-backup`, and applies offsite
retention. The upload uses `rclone copy --immutable`, so an existing remote
object is never overwritten with different content.

### Recovering from an immutable partial upload

If an interrupted upload leaves a stale remote object whose content differs
from the local backup, later `--immutable` retries will fail. Inspect the
timestamped remote backup directory, confirm that it is the incomplete upload,
and perform manual cleanup of that remote directory before rerunning
`offsite-backup`. Never remove a remote directory that has already passed the
offsite restore test.
