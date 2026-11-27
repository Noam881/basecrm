<?php

namespace App\Integrations\Monday;

/** Fixed categories only; never chains backend errors, URLs, headers, source names or bytes. */
final class AssetDownloadFailed extends \RuntimeException
{
    private const CATEGORIES = ['source_asset_invalid', 'source_host_unapproved', 'source_dns_unavailable', 'source_address_rejected',
        'source_transport_unavailable', 'source_transfer_failed', 'source_transfer_timeout', 'source_size_mismatch'];

    public function __construct(string $category)
    {
        parent::__construct(in_array($category, self::CATEGORIES, true) ? $category : 'source_transfer_failed');
    }
}
