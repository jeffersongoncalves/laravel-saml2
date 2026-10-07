<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateCertificateCommand extends Command
{
    protected $signature = 'saml2:generate-cert
        {--path=storage/saml2 : Directory (relative to the project root) for sp.crt and sp.key}
        {--days=3650 : Validity in days}
        {--cn= : Certificate common name (defaults to the APP_URL host)}
        {--force : Overwrite existing files}
        {--openssl-config= : Path to an openssl.cnf (needed on Windows builds without one)}';

    protected $description = 'Generate a self-signed Service Provider certificate and private key';

    public function handle(): int
    {
        $path = $this->option('path');
        $directory = rtrim(is_string($path) && $path !== '' ? $path : 'storage/saml2', '/');
        $crt = "{$directory}/sp.crt";
        $key = "{$directory}/sp.key";

        if (! $this->option('force') && (File::exists(base_path($crt)) || File::exists(base_path($key)))) {
            $this->components->error("{$crt} or {$key} already exists. Use --force to overwrite (the IdP must then trust the new certificate).");

            return self::FAILURE;
        }

        $cn = $this->option('cn');
        $cn = is_string($cn) && $cn !== '' ? $cn : (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT) ?: 3650;

        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $config = $this->option('openssl-config');

        if (is_string($config) && $config !== '') {
            $options['config'] = $config;
        }

        $privateKey = openssl_pkey_new($options);
        $csr = $privateKey ? openssl_csr_new(['commonName' => $cn], $privateKey, $options) : false;
        $certificate = $privateKey && $csr instanceof \OpenSSLCertificateSigningRequest
            ? openssl_csr_sign($csr, null, $privateKey, $days, $options)
            : false;

        if (! $privateKey || ! $certificate || ! openssl_x509_export($certificate, $crtPem) || ! openssl_pkey_export($privateKey, $keyPem, null, $options)) {
            $this->components->error('OpenSSL failed: '.(openssl_error_string() ?: 'unknown error'));

            return self::FAILURE;
        }

        File::ensureDirectoryExists(base_path($directory));
        File::put(base_path($crt), $crtPem);
        File::put(base_path($key), $keyPem);
        @chmod(base_path($key), 0600);

        $this->components->info('Certificate generated. Add to your .env:');
        $this->line("  SAML2_SP_X509_CERT={$crt}");
        $this->line("  SAML2_SP_PRIVATE_KEY={$key}");
        $this->newLine();
        $this->components->warn('Keep sp.key out of version control.');

        return self::SUCCESS;
    }
}
