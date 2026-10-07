<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Tests\Support;

use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use JeffersonGoncalves\LaravelSaml2\Saml2;
use OneLogin\Saml2\Utils;

/**
 * A throwaway Identity Provider: real RSA key, real signatures.
 */
class FakeIdp
{
    public const ENTITY_ID = 'https://idp.example.com/metadata';

    public const SSO_URL = 'https://idp.example.com/sso';

    public const SLO_URL = 'https://idp.example.com/slo';

    /** @var array{key: string, cert: string}|null */
    protected static ?array $keypair = null;

    /**
     * @return array{key: string, cert: string}
     */
    public static function keypair(): array
    {
        if (static::$keypair) {
            return static::$keypair;
        }

        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];

        if (PHP_OS_FAMILY === 'Windows' && is_file(__DIR__.'/openssl.cnf')) {
            $config['config'] = __DIR__.'/openssl.cnf';
        }

        $key = openssl_pkey_new($config);
        $csr = openssl_csr_new(['commonName' => 'idp.example.com'], $key, $config);
        $x509 = openssl_csr_sign($csr, null, $key, 365, $config);
        openssl_pkey_export($key, $keyPem, null, $config);
        openssl_x509_export($x509, $certPem);

        return static::$keypair = ['key' => $keyPem, 'cert' => $certPem];
    }

    public static function certificate(): string
    {
        return static::keypair()['cert'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function tenant(array $attributes = []): Saml2Tenant
    {
        return Saml2Tenant::query()->create($attributes + [
            'key' => 'acme',
            'idp_entity_id' => self::ENTITY_ID,
            'idp_login_url' => self::SSO_URL,
            'idp_logout_url' => self::SLO_URL,
            'idp_x509_cert' => self::certificate(),
        ]);
    }

    /**
     * Base64 encoded, signed SAMLResponse for the tenant ACS URL.
     *
     * @param  array<string, list<string>>  $attributes
     */
    public static function response(
        Saml2Tenant $tenant,
        string $nameId = 'jane',
        array $attributes = ['email' => ['Jane.Doe@Example.com'], 'groups' => ['admins', 'devs']],
        ?string $assertionId = null,
        ?string $audience = null,
        bool $sign = true,
    ): string {
        $urls = app(Saml2::class)->serviceProviderUrls($tenant);
        $acs = $urls['acs'];
        $audience ??= $urls['entity_id'];
        $assertionId ??= '_a'.bin2hex(random_bytes(10));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $before = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        $after = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

        $attributeXml = '';
        foreach ($attributes as $name => $values) {
            $attributeXml .= '<saml:Attribute Name="'.$name.'">';
            foreach ($values as $value) {
                $attributeXml .= '<saml:AttributeValue>'.htmlspecialchars($value).'</saml:AttributeValue>';
            }
            $attributeXml .= '</saml:Attribute>';
        }

        $xml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r{$assertionId}" Version="2.0" IssueInstant="{$now}" Destination="{$acs}"><saml:Issuer>{$tenant->idp_entity_id}</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status><saml:Assertion ID="{$assertionId}" Version="2.0" IssueInstant="{$now}"><saml:Issuer>{$tenant->idp_entity_id}</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:2.0:nameid-format:persistent">{$nameId}</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="{$after}" Recipient="{$acs}"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="{$before}" NotOnOrAfter="{$after}"><saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="{$now}" SessionIndex="_session123"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement><saml:AttributeStatement>{$attributeXml}</saml:AttributeStatement></saml:Assertion></samlp:Response>
XML;

        if ($sign) {
            $xml = Utils::addSign($xml, static::keypair()['key'], static::certificate());
        }

        return base64_encode($xml);
    }

    /**
     * HTTP-Redirect encoded IdP-initiated LogoutRequest.
     */
    public static function logoutRequest(Saml2Tenant $tenant, string $nameId = 'jane', string $sessionIndex = '_session123'): string
    {
        $sls = app(Saml2::class)->serviceProviderUrls($tenant)['sls'];
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $xml = <<<XML
<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_lr1" Version="2.0" IssueInstant="{$now}" Destination="{$sls}"><saml:Issuer>{$tenant->idp_entity_id}</saml:Issuer><saml:NameID>{$nameId}</saml:NameID><samlp:SessionIndex>{$sessionIndex}</samlp:SessionIndex></samlp:LogoutRequest>
XML;

        return base64_encode((string) gzdeflate($xml));
    }

    /**
     * IdP metadata document; pass two certificates to simulate a key rollover.
     */
    public static function metadata(string ...$certificates): string
    {
        $certificates = $certificates ?: [static::certificate()];
        $keys = '';

        foreach ($certificates as $certificate) {
            $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $certificate);
            $keys .= '<md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>'.$body.'</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>';
        }

        $entity = self::ENTITY_ID;
        $sso = self::SSO_URL;
        $slo = self::SLO_URL;

        return <<<XML
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="{$entity}"><md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">{$keys}<md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$slo}"/><md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat><md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$sso}"/></md:IDPSSODescriptor></md:EntityDescriptor>
XML;
    }
}
