#!/usr/bin/env bash
# Run in the new Enhance GitHub release before Enhance changes its current symlink.
set -Eeuo pipefail
umask 027

fail() {
    printf 'Base CRM deploy: %s\n' "$*" >&2
    exit 1
}

if [[ $# -lt 1 || $# -gt 2 ]]; then
    printf 'Usage: bash scripts/deploy-enhance.sh SITE_HOME [RELEASE_DIRECTORY]\n' >&2
    exit 2
fi

[[ -d "$1" ]] || fail "Site home does not exist: $1"
site_root=$(cd -- "$1" && pwd -P)
release_arg=${2:-$PWD}
[[ -d "$release_arg" ]] || fail "Release directory does not exist: $release_arg"
release_root=$(cd -- "$release_arg" && pwd -P)
[[ "$(dirname -- "$release_root")" == "$site_root/deploys" ]] || fail 'Run this hook from a direct SITE_HOME/deploys/<release> directory.'
cd -- "$release_root"
for source_file in artisan composer.json composer.lock package-lock.json public/index.php; do
    [[ -f "$source_file" ]] || fail "Release is missing $source_file. Select the Base CRM repository root."
done

private_root="$site_root/.basecrm"
shared_env="$private_root/.env"
shared_manifest="$private_root/manifest.json"
shared_storage="$private_root/storage"
[[ -r "$shared_env" ]] || fail "Prepare the private production environment at $shared_env before deployment."
[[ -r "$shared_manifest" ]] || fail "Prepare the installation manifest at $shared_manifest before deployment."
[[ -d "$shared_storage" && -w "$shared_storage" ]] || fail "Prepare writable persistent storage at $shared_storage before deployment."
if [[ -r "$site_root/.nvm/nvm.sh" ]]; then
    export NVM_DIR="$site_root/.nvm"
    # Load the website's prepared runtime; deployment never installs Node versions.
    source "$NVM_DIR/nvm.sh" --no-use
    nvm use --silent 26 >/dev/null || fail 'Node 26 is not installed in the website nvm runtime. Provision it before deploying.'
fi
for executable in php composer node npm; do
    command -v "$executable" >/dev/null 2>&1 || fail "Required executable is missing from PATH: $executable"
done
php -r '
if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 90000) {
    fwrite(STDERR, "Base CRM deploy: PHP 8.5 or compatible PHP 8.x is required on PATH.\n");
    exit(1);
}
foreach (["pdo_pgsql", "intl", "mbstring"] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Base CRM deploy: missing PHP extension: $extension\n");
        exit(1);
    }
}
'
node -e '
const [major, minor] = process.versions.node.split(".").map(Number);
if (!((major === 20 && minor >= 19) || (major === 22 && minor >= 12) || major >= 23)) {
    process.stderr.write("Base CRM deploy: Node 20.19+ or 22.12+ is required; CI uses Node 26.\n");
    process.exit(1);
}
'

# Read only the deployment guard values; never source or print the secret dotenv file.
installation=$(php -r "$(cat <<'PHP'
function stopDeploy(string $message): never {
    fwrite(STDERR, "Base CRM deploy: $message\n");
    exit(1);
}
$values = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $line) {
    if (!preg_match('/^\s*(?:export\s+)?([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $match)) continue;
    $value = $match[2];
    if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
        $value = substr($value, 1, -1);
    } else {
        $value = preg_replace('/\s+#.*$/', '', $value);
    }
    $values[$match[1]] = $value;
}
if (($values['APP_ENV'] ?? '') !== 'production') stopDeploy('APP_ENV must be production in the private .env.');
if (!in_array(strtolower($values['APP_DEBUG'] ?? ''), ['false', '(false)', '0'], true)) stopDeploy('APP_DEBUG must be false in the private .env.');
$url = parse_url($values['APP_URL'] ?? '');
$host = strtolower($url['host'] ?? '');
if (($url['scheme'] ?? '') !== 'https' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || preg_match('/\.(?:test|invalid|localhost)$/', $host)) {
    stopDeploy('APP_URL must be the production HTTPS URL.');
}
$key = $values['APP_KEY'] ?? '';
if (!str_starts_with($key, 'base64:') || strlen(base64_decode(substr($key, 7), true) ?: '') !== 32) stopDeploy('Prepare a persistent valid APP_KEY once; do not regenerate it on each release.');
$id = $values['CRM_INSTALLATION'] ?? '';
if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) stopDeploy('CRM_INSTALLATION must identify this production installation.');
if (realpath($values['CRM_MANIFEST'] ?? '') !== realpath($argv[2])) stopDeploy('CRM_MANIFEST must point to SITE_HOME/.basecrm/manifest.json.');
if (($values['DB_CONNECTION'] ?? '') !== 'pgsql') stopDeploy('DB_CONNECTION must be pgsql; this application requires PostgreSQL.');
try {
    $manifest = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    stopDeploy('The private installation manifest must contain valid JSON.');
}
if (!is_array($manifest) || ($manifest['id'] ?? '') !== $id) stopDeploy('The manifest id must match CRM_INSTALLATION.');
echo $id;
PHP
)" -- "$shared_env" "$shared_manifest")

if [[ -e .env || -L .env ]]; then
    [[ -L .env && "$(readlink -- .env)" == "$shared_env" ]] || fail 'Release .env already exists and is not the expected private symlink; preserve and inspect it.'
fi
if [[ -L storage ]]; then
    [[ "$(readlink -- storage)" == "$shared_storage" ]] || fail 'Release storage points elsewhere; preserve and inspect it.'
elif [[ -e storage ]]; then
    [[ -d storage ]] || fail 'Release storage is not a directory; preserve and inspect it.'
    [[ -z "$(find storage -mindepth 1 ! -type d ! -name .gitignore -print -quit)" ]] || fail 'Release storage contains data. Move it to persistent storage deliberately before running this hook.'
    [[ ! -e .storage-template && ! -L .storage-template ]] || fail 'A previous .storage-template exists; inspect the interrupted release before retrying.'
fi

composer check-platform-reqs --lock --no-dev --no-interaction
mkdir -p "$shared_storage/framework/cache/data" "$shared_storage/framework/sessions" \
    "$shared_storage/framework/views" "$shared_storage/logs" \
    "$shared_storage/installations/$installation/private" "$shared_storage/installations/$installation/public" \
    bootstrap/cache
[[ -w bootstrap/cache ]] || fail 'bootstrap/cache must be writable by the website deployment/PHP user.'
[[ -L .env ]] || ln -s -- "$shared_env" .env
if [[ ! -L storage ]]; then
    [[ ! -d storage ]] || mv -- storage .storage-template
    ln -s -- "$shared_storage" storage
fi

COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
# Vite/TypeScript are build dependencies even when NODE_ENV is production.
npm ci --include=dev
npm run build
[[ -s public/build/manifest.json ]] || fail 'The frontend build did not produce public/build/manifest.json.'

# Check effective Laravel configuration too: server environment variables can override .env.
php -r "$(cat <<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$expected = Dotenv\Dotenv::parse(file_get_contents($argv[3]));
$database = config('database.connections.pgsql', []);
$databaseMatches = in_array($database['url'] ?? null, [null, ''], true) && ($expected['DB_URL'] ?? '') === '';
foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME'] as $key => $environmentKey) {
    $databaseMatches = $databaseMatches && isset($expected[$environmentKey], $database[$key]) && is_scalar($database[$key]) && (string) $database[$key] === $expected[$environmentKey];
}
if (!$databaseMatches) {
    fwrite(STDERR, "Base CRM deploy: effective database connection does not match the private installation environment. Remove DB_URL overrides and verify the explicit PostgreSQL connection values.\n");
    exit(1);
}
if (!$app->isProduction() || config('app.debug') || config('database.default') !== 'pgsql' || realpath(config('core.manifest') ?: '') !== realpath($argv[1]) || config('core.installation') !== $argv[2] || config('app.url') !== ($expected['APP_URL'] ?? null) || config('app.key') !== ($expected['APP_KEY'] ?? null)) {
    fwrite(STDERR, "Base CRM deploy: effective Laravel configuration is not the expected production PostgreSQL installation. Check server environment overrides.\n");
    exit(1);
}
PHP
)" -- "$shared_manifest" "$installation" "$shared_env"

# Backups/write coordination are an operator prerequisite. These commands do not seed fixtures.
php artisan migrate --force --no-interaction
php artisan crm:install --no-interaction
php artisan optimize --no-interaction
php artisan crm:diagnose --no-interaction
printf 'Base CRM release prepared. Let Enhance activate current, then restart queue workers and verify HTTPS /up and login.\n'
