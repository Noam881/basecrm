<?php

use App\Integrations\Monday\AssetDownloader;
use App\Integrations\Monday\AssetDownloadFailed;
use App\Integrations\Monday\AssetLocation;

class FictionalAssetDownloader extends AssetDownloader
{
    public array $addresses = ['93.184.216.34'];

    public array $chunks = ['abc'];

    public array $headers = ["HTTP/2 200\r\n", "Content-Length: 3\r\n", "\r\n"];

    public array $options = [];

    public int $calls = 0;

    public int $status = 200;

    public string $connected = '93.184.216.34';

    public float $time = 0;

    public float $dnsDelay = 0;

    public bool $fail = false;

    public function __construct()
    {
        parent::__construct(['files.monday.com']);
    }

    protected function resolve(string $host): array
    {
        $this->time += $this->dnsDelay;

        return $this->addresses;
    }

    protected function monotonic(): float
    {
        return $this->time;
    }

    protected function transfer(#[SensitiveParameter] string $url, array $options): array
    {
        $this->calls++;
        $this->options = $options;
        if ($this->fail) {
            throw new RuntimeException('Never expose fictional URL signature or source bytes.');
        }
        foreach ($this->headers as $header) {
            if (strlen($header) !== $options[CURLOPT_HEADERFUNCTION](null, $header)) {
                return ['ok' => false, 'status' => $this->status, 'address' => $this->connected];
            }
        }
        foreach ($this->chunks as $chunk) {
            if (strlen($chunk) !== $options[CURLOPT_WRITEFUNCTION](null, $chunk)) {
                return ['ok' => false, 'status' => $this->status, 'address' => $this->connected];
            }
        }

        return ['ok' => true, 'status' => $this->status, 'address' => $this->connected];
    }
}

function fictionalAsset(string $url = 'https://files.monday.com/fictional-source?signature=fictional-capability', int $size = 3): AssetLocation
{
    return AssetLocation::fromSource('12345', 'Shira Levi proposal.pdf', 'pdf', $size, $url);
}

it('downloads exact bounded bytes with pinned TLS address and no implicit source credentials', function () {
    $downloader = new FictionalAssetDownloader;
    expect($downloader->download(fictionalAsset(), 10))->toBe('abc');
    $options = $downloader->options;
    expect($options[CURLOPT_RESOLVE])->toBe(['files.monday.com:443:93.184.216.34'])
        ->and($options[CURLOPT_FOLLOWLOCATION])->toBeFalse()->and($options[CURLOPT_SSL_VERIFYPEER])->toBeTrue()
        ->and($options[CURLOPT_SSL_VERIFYHOST])->toBe(2)->and($options[CURLOPT_PROXY])->toBe('')
        ->and($options[CURLOPT_NETRC])->toBe(CURL_NETRC_IGNORED)->and($options[CURLOPT_RETURNTRANSFER])->toBeFalse()
        ->and($options[CURLOPT_HTTPHEADER])->toBe(['Accept: application/octet-stream', 'Accept-Encoding: identity'])
        ->and(array_key_exists(CURLOPT_USERPWD, $options))->toBeFalse()
        ->and(array_key_exists(CURLOPT_COOKIE, $options))->toBeFalse()
        ->and(array_key_exists(CURLOPT_REFERER, $options))->toBeFalse();
});

it('rejects unsafe URLs without opening a connection', function (string $url) {
    $downloader = new FictionalAssetDownloader;
    expect(fn () => $downloader->download(fictionalAsset($url), 10))->toThrow(AssetDownloadFailed::class, 'source_host_unapproved');
    expect($downloader->calls)->toBe(0);
})->with(['http://files.monday.com/a', 'https://files.monday.com.evil.example/a', 'https://user@files.monday.com/a',
    'https://files.monday.com:8443/a', 'https://files.monday.com/a#fragment', 'https://127.0.0.1/a', 'https://files.monday.com./a']);

it('refuses any nonpublic DNS answer including mixed and mapped responses', function (array $addresses) {
    $downloader = new FictionalAssetDownloader;
    $downloader->addresses = $addresses;
    expect(fn () => $downloader->download(fictionalAsset(), 10))->toThrow(AssetDownloadFailed::class, 'source_address_rejected');
    expect($downloader->calls)->toBe(0);
})->with([[['127.0.0.1']], [['169.254.169.254']], [['100.64.0.1']], [['::1']], [['fc00::1']], [['::ffff:8.8.8.8']],
    [['64:ff9b::7f00:1']], [['64:ff9b:1::7f00:1']], [['93.184.216.34', '192.168.0.1']]]);

it('rejects missing DNS and expired resolution before any file request', function () {
    $downloader = new FictionalAssetDownloader;
    $downloader->addresses = [];
    expect(fn () => $downloader->download(fictionalAsset(), 10))->toThrow(AssetDownloadFailed::class, 'source_dns_unavailable');
    $downloader->addresses = ['93.184.216.34'];
    $downloader->dnsDelay = 21;
    expect(fn () => $downloader->download(fictionalAsset(), 10))->toThrow(AssetDownloadFailed::class, 'source_transfer_timeout');
    expect($downloader->calls)->toBe(0);
});

it('subtracts resolution time and checks the actual connected address', function () {
    $downloader = new FictionalAssetDownloader;
    $downloader->dnsDelay = 7;
    expect($downloader->download(fictionalAsset(), 10))->toBe('abc')->and($downloader->options[CURLOPT_TIMEOUT_MS])->toBe(13000);
    $downloader->connected = '8.8.8.8';
    expect(fn () => $downloader->download(fictionalAsset(), 10))->toThrow(AssetDownloadFailed::class, 'source_address_rejected');
});

it('pins global IPv6 addresses using the required bracket syntax', function () {
    $downloader = new FictionalAssetDownloader;
    $downloader->addresses = ['2606:4700:4700::1111'];
    $downloader->connected = '2606:4700:4700:0:0:0:0:1111';
    expect($downloader->download(fictionalAsset(), 10))->toBe('abc')
        ->and($downloader->options[CURLOPT_RESOLVE])->toBe(['files.monday.com:443:[2606:4700:4700::1111]']);
});

it('refuses oversized declarations headers and streaming chunks plus incomplete bytes', function () {
    $downloader = new FictionalAssetDownloader;
    expect(fn () => $downloader->download(fictionalAsset(size: 11), 10))->toThrow(AssetDownloadFailed::class, 'source_asset_invalid');
    expect($downloader->calls)->toBe(0);
    $downloader->headers = ["Content-Length: 100000000000000000000000000\r\n"];
    expect(fn () => $downloader->download(fictionalAsset(), 10))->toThrow(AssetDownloadFailed::class, 'source_size_mismatch');
    $downloader->headers = [];
    $downloader->chunks = ['ab', 'cd'];
    expect(fn () => $downloader->download(fictionalAsset(), 10))->toThrow(AssetDownloadFailed::class, 'source_size_mismatch');
    $downloader->chunks = ['a'];
    expect(fn () => $downloader->download(fictionalAsset(), 10))->toThrow(AssetDownloadFailed::class, 'source_size_mismatch');
});

it('never follows redirects and drops underlying error context', function () {
    $downloader = new FictionalAssetDownloader;
    $downloader->status = 302;
    expect(fn () => $downloader->download(fictionalAsset(), 10))->toThrow(AssetDownloadFailed::class, 'source_transfer_failed');
    $downloader->fail = true;
    try {
        $downloader->download(fictionalAsset(), 10);
    } catch (AssetDownloadFailed $error) {
        expect($error->getPrevious())->toBeNull()->and($error->getMessage())->toBe('source_transfer_failed');
    }
});

it('keeps names and signed locations out of debug JSON and serialization', function () {
    $location = fictionalAsset();
    expect(json_encode($location))->toBe('{}');
    ob_start();
    var_dump($location);
    $debug = ob_get_clean();
    expect($debug)->not->toContain('signature', 'Shira', 'files.monday.com');
    expect(fn () => serialize($location))->toThrow(LogicException::class);
});
