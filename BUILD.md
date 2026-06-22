# BUILD.md — Plugin Packaging Guide

This document describes how to produce a distributable WordPress plugin ZIP for `gd-workflow-bridge-pro` (the plugin core only). It covers local builds, CI packaging, and a short security checklist.

## Goal

Produce `release/gd-workflow-bridge-pro.zip` containing only the plugin code and its PHP dependencies (`vendor/`), suitable for installation via the WordPress admin or `wp-cli`.

## What to include

- `gd-workflow-bridge-pro.php` (plugin entry)
- `includes/`, `assets/`, `languages/`, `templates/`
- `vendor/` (after `composer install --no-dev`)
- `readme.txt`, `composer.json`, `composer.lock`, `phinx.php`, `phpcs.xml`

## What to exclude

- `license-server/`, `services/`, `infra/`, `tests/`, `.github/`
- `keys/`, `.env`, private PEM files, or any runtime secrets
- Docker, CI, and deployment artifacts

The provided build scripts already exclude these paths.

## Prerequisites

- Git
- `zip` (Linux/macOS) or PowerShell `Compress-Archive` (Windows)
- Composer (preferred) or Docker (to run Composer in a container)
- Optional: `wp` (WP-CLI) for local install testing

## Build (Linux / macOS)

1. (Optional) Bump version in `gd-workflow-bridge-pro.php` and `composer.json`.
2. Vendor dependencies:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

3. Build the ZIP:

```bash
chmod +x ./build/package-plugin.sh
./build/package-plugin.sh
```

The produced archive is `release/gd-workflow-bridge-pro.zip`.

## Build (Windows PowerShell)

1. Vendor dependencies (PowerShell):

```powershell
pwsh -NoProfile -ExecutionPolicy Bypass -Command "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction"
```

2. Build:

```powershell
.\build\package-plugin.ps1
```

## If you don't have Composer locally: use Docker

```powershell
$pwd = (Get-Location).Path
docker run --rm -v "${pwd}:/app" -w /app composer:2 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

On Linux/macOS the same command works (replace PowerShell variable syntax with `$(pwd)`):

```bash
docker run --rm -v "$(pwd):/app" -w /app composer:2 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

## Test installation (WP-CLI)

Install and activate the ZIP into a WordPress instance (local or Docker):

```bash
wp plugin install release/gd-workflow-bridge-pro.zip --force --activate
```

Or using the official WordPress CLI Docker image:

```bash
docker run --rm -v "$(pwd)/release:/tmp/release" wordpress:6.3-cli wp plugin install /tmp/release/gd-workflow-bridge-pro.zip --activate --allow-root
```

## CI (GitHub Actions)

The repository includes `.github/workflows/package-plugin.yml` which:

- runs `composer install --no-dev` on Ubuntu
- executes `./build/package-plugin.sh` and uploads the ZIP as an artifact
- when a tag is pushed (e.g. `v1.2.3`) it creates a GitHub Release and attaches the ZIP

This ensures releases built on CI include `vendor/` even if Composer is not present locally.

## Security & Release Checklist

- Ensure no private keys or secrets are present in the repo before packaging:

```bash
git grep -I --line-number -e 'PRIVATE_KEY' -e 'PRIVATE.pem' -e 'TOKEN' -e 'SECRET' || true
```

- Remove or rotate any test keys in `license-server/keys` (not included by default in the build script).
- Do NOT commit private keys into the repository. For production, mount keys from a secure location.
- Verify the ZIP does not contain `keys/` or `.env` before publishing:

```bash
unzip -l release/gd-workflow-bridge-pro.zip | grep -E "(^|/)keys(/|$)|\.env" || true
```

## Releasing

1. Tag the release: `git tag -a vX.Y.Z -m "Release vX.Y.Z"`
2. Push tag: `git push origin --tags`
3. The GitHub Actions workflow will run and create a Release with the ZIP attached.

## Troubleshooting

- If `composer` is not found locally, use the Docker approach above.
- If the ZIP is missing `vendor/`, ensure `composer install` completed successfully prior to packaging.

## Contact

Author: Aikin Annu — aikinannu@gmail.com
