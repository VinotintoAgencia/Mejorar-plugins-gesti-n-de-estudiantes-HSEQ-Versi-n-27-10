# Vendor dependencies

Composer dependencies (notably `mpdf/mpdf` and its `setasign/fpdi` dependency) could not be installed directly in this environment because outbound access to GitHub/Packagist is blocked (403 during download).

## How to populate `vendor/`
1. On a machine with internet access, run:
   ```bash
   composer install --no-dev --prefer-dist --no-progress
   ```
   This will download `mpdf/mpdf` v6.1.3 and place the autoloader under `vendor/`.
2. Zip or copy the resulting `vendor/` directory into this project at the same relative path.
3. Commit the populated `vendor/` tree (along with `composer.lock`) so environments without internet can load the autoloader.

If installation still fails in a connected environment, ensure PHP has OpenSSL and allowlist `https://api.github.com` for Composer downloads.
