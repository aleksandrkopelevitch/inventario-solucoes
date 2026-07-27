<?php

use App\Rules\PublicUrl;
use Illuminate\Support\Facades\Validator;

function validatesPublicUrl(string $url): bool
{
    return Validator::make(['url' => $url], ['url' => [new PublicUrl]])->passes();
}

it('accepts a URL whose host is a public IP literal', function () {
    // An IP literal skips DNS resolution entirely, so this never touches the
    // network — it only exercises the FILTER_VALIDATE_IP allow path.
    expect(validatesPublicUrl('http://1.1.1.1/image.png'))->toBeTrue();
});

it('rejects private, loopback and link-local IP-literal hosts', function (string $url) {
    expect(validatesPublicUrl($url))->toBeFalse();
})->with([
    'loopback'                     => 'http://127.0.0.1/x.png',
    'link-local / cloud metadata'  => 'http://169.254.169.254/latest/meta-data/',
    'private RFC1918 (10/8)'       => 'http://10.0.0.5/x.png',
    'private RFC1918 (192.168/16)' => 'http://192.168.1.1/x.png',
    'IPv6 loopback'                => 'http://[::1]/x.png',
]);

it('rejects a URL with no resolvable host', function () {
    expect(validatesPublicUrl('http:///no-host'))->toBeFalse();
});
