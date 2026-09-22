<?php

use App\Services\Api\HttpApiClient;

it('merges the private CA on top of the system roots instead of replacing them', function () {
    $private = tempnam(sys_get_temp_dir(), 'ca').'.pem';
    file_put_contents($private, "PRIVATE-CA\n");
    config(['ticketscanner.ca_bundle' => $private]);

    $bundle = (fn () => $this->caBundle())->call(app(HttpApiClient::class));

    $system = ini_get('curl.cainfo') ?: openssl_get_cert_locations()['default_cert_file'];
    expect($bundle)->toBe(storage_path('app/ca-bundle.pem'))
        ->and(file_get_contents($bundle))->toContain('PRIVATE-CA')
        ->and(file_get_contents($bundle))->toContain(file_get_contents($system));

    @unlink($bundle);
    @unlink($private);
});
