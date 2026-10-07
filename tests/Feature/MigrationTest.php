<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JeffersonGoncalves\LaravelSaml2\Tests\Support\FakeIdp;

it('upgrades an existing saml2_tenants table in place without losing rows', function () {
    Schema::drop('saml2_tenants');
    Schema::create('saml2_tenants', function (Blueprint $table) {
        $table->increments('id');
        $table->uuid('uuid');
        $table->string('key')->nullable();
        $table->string('idp_entity_id');
        $table->string('idp_login_url');
        $table->string('idp_logout_url');
        $table->text('idp_x509_cert');
        $table->json('metadata');
        $table->timestamps();
        $table->softDeletes();
    });
    DB::table('saml2_tenants')->insert([
        'uuid' => 'b2dae2e6-e814-4553-a3a5-a56ddaca1110', 'key' => 'legacy', 'idp_entity_id' => 'e',
        'idp_login_url' => 'l', 'idp_logout_url' => 'o', 'idp_x509_cert' => 'c', 'metadata' => '[]',
    ]);

    (require __DIR__.'/../../database/migrations/create_saml2_tenants_table.php')->up();

    expect(Schema::hasColumns('saml2_tenants', ['idp_metadata_url', 'relay_state_url', 'name_id_format', 'settings']))->toBeTrue()
        ->and(DB::table('saml2_tenants')->value('name_id_format'))->toBe('persistent')
        ->and(DB::table('saml2_tenants')->value('key'))->toBe('legacy');

    // Columns that used to be required are optional now.
    FakeIdp::tenant(['key' => 'new', 'idp_logout_url' => null]);
    expect(DB::table('saml2_tenants')->count())->toBe(2);
});
