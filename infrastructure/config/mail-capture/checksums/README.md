# Mail-capture release checksums

Each `*.sha256` file here pins the SHA-256 of every release archive the
installer is permitted to download for the versions declared in
`../versions.env`. The installer refuses to install any archive whose digest
is not listed here (`sha256sum -c`), so an unverified or tampered binary can
never be installed.

## Provenance

- `mailtrap-local-0.2.0.sha256` — copied verbatim (Linux rows only) from the
  upstream release `checksums.txt`:
  <https://github.com/mailtrap/mailtrap-local/releases/download/v0.2.0/checksums.txt>

- `mailpit-1.30.5.sha256` — the v1.30.5 release does not publish a
  `checksums.txt` asset, so these digests were computed with `sha256sum`
  directly from the official GitHub release archives:
  <https://github.com/axllent/mailpit/releases/tag/v1.30.5>

## Updating a pinned version

1. Bump the value in `../versions.env`.
2. Add a new `mailpit-<version>.sha256` / `mailtrap-local-<version>.sha256`
   file with the official Linux `amd64` and `arm64` digests.
3. Re-run `install-mail-capture --check`, then `--apply`.

Only Linux `amd64` and `arm64` are supported install targets, so only those
rows are pinned.
