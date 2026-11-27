# Monday file transport

`AssetLocation` represents a fresh server-derived asset location. Its owning adapter must prove the selected account, source item/column/history occurrence and asset ID before construction. The location is ephemeral; filenames and signed URLs are hidden from debug/JSON output, and serialization is refused. It is not an authorization grant or a file-format decision.

`AssetDownloader::download($location, $maximumBytes)` returns exact file bytes in memory. Each owning adapter must authorize before and after the call, validate the actual filename/MIME, write through its native private storage service and clear its temporary plaintext input. Vault additionally requires its actual authenticated Request and unchanged fixed unlock deadline. No source transport may substitute for those checks.

The transport requires PHP cURL. Deployment-controlled `services.monday.asset_hosts` is an exact-host allowlist, defaulting to `files.monday.com`; wildcard, literal-IP and non-HTTPS destinations are refused. Source API tokens, cookies, proxy credentials and referrers are never forwarded. Every DNS answer must be globally routable; mapped/NAT64 encodings are denied. One checked address is pinned through `CURLOPT_RESOLVE`, TLS verifies the original hostname, and the actual connected address must match. Redirects and retries are disabled.

Set `MONDAY_ASSET_HOSTS` in the installation's private environment as a comma-separated list of exact lowercase hostnames. For a deployment whose authenticated source metadata confirms the observed Monday S3 provider host, use:

```dotenv
MONDAY_ASSET_HOSTS=files.monday.com,files-monday-com.s3.amazonaws.com
```

The example environment keeps `files.monday.com` as the default. Approve additional hosts from verified source metadata; use hostnames only, without schemes, paths, signed URLs, query strings or wildcard S3 domains. Whitespace around comma-separated entries is trimmed. Empty entries and malformed hosts remain in the parsed list so the downloader's existing validation rejects them; its nonempty-list and 20-host bounds remain authoritative.

Rebuild Laravel's configuration cache after changing this setting and restart long-lived PHP processes or workers so they use the reviewed list. The default injected downloader reads this configuration, including the Vault file flow. Adapters supplying an explicit reviewed plan allowlist use that list. Existing source-ID authorization, Vault unlocks, public-DNS checks, pinned TLS, byte limits and redirect restrictions apply to every approved hostname.

Headers are capped at64KiB; body callbacks stop before exceeding the declared size or adapter cap (maximum100MiB). Only HTTP200 with an exact declared-size body succeeds. Data stays in RAM, without an implicit temporary download file. A twenty-second monotonic transfer budget includes elapsed DNS resolution; connection and low-speed limits are five/two seconds. The system resolver can block, but an expired resolution budget starts no file request. Wrappers must leave sufficient unlock time and recheck current authority before accepting bytes. Backend errors are replaced by fixed categories with no chained URL/header/body context.

Monday documents `public_url` as a temporary download capability and demonstrates the nested file occurrence→asset projection. This implementation retains the application's pinned API schema as authority and does not claim a URL alone is a migrated file. See [Monday Files](https://developer.monday.com/api-reference/reference/files-1), [cURL address pinning](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html) and [bounded write callbacks](https://curl.se/libcurl/c/CURLOPT_WRITEFUNCTION.html).

The unit suite uses synthetic metadata and a fake transfer seam. It covers transport options, IPv4/IPv6 pinning, private/mixed/NAT64 DNS rejection, expired budgets, headers/chunks, redirects, exact size and private error handling. It makes no DNS/source/network/database calls and is not a real-file migration check.
