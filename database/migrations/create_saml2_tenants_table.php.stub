<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saml2_tenants')) {
            Schema::create('saml2_tenants', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->unique();
                $table->string('key')->nullable()->unique();
                $table->string('idp_entity_id');
                $table->string('idp_login_url');
                $table->string('idp_logout_url')->nullable();
                $table->text('idp_x509_cert')->nullable();
                $table->string('idp_metadata_url')->nullable();
                $table->string('relay_state_url')->nullable();
                $table->string('name_id_format')->default('persistent');
                $table->json('settings')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });

            return;
        }

        // An existing saml2_tenants table with the same core columns is upgraded in place.
        $missing = fn (string $column): bool => ! Schema::hasColumn('saml2_tenants', $column);

        Schema::table('saml2_tenants', function (Blueprint $table) use ($missing) {
            if ($missing('idp_metadata_url')) {
                $table->string('idp_metadata_url')->nullable();
            }
            if ($missing('relay_state_url')) {
                $table->string('relay_state_url')->nullable();
            }
            if ($missing('name_id_format')) {
                $table->string('name_id_format')->default('persistent');
            }
            if ($missing('settings')) {
                $table->json('settings')->nullable();
            }
        });

        Schema::table('saml2_tenants', function (Blueprint $table) {
            $table->string('idp_logout_url')->nullable()->change();
            $table->text('idp_x509_cert')->nullable()->change();
            $table->json('metadata')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saml2_tenants');
    }
};
