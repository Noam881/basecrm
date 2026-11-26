#!/usr/bin/env bash
# Exercise the real deployment hook's cleanup boundary without a runtime, DB or hosting account.
set -Eeuo pipefail

repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
hook="$repository/scripts/deploy-enhance.sh"
fixture_root=$(mktemp -d "${TMPDIR:-/tmp}/basecrm-footprint-test.XXXXXX")
fixture_root=$(cd -- "$fixture_root" && pwd -P)
trap 'rm -rf -- "$fixture_root"' EXIT
stub_root="$fixture_root/stubs"
mkdir -p "$stub_root"

cat > "$stub_root/php" <<'STUB'
#!/usr/bin/env bash
set -Eeuo pipefail
# Other tests cover production guards; this fixture only isolates filesystem cleanup.
if [[ ${2:-} == *'function stopDeploy'* ]]; then
    printf 'fixture'
fi
STUB
for executable in composer node; do
    printf '#!/usr/bin/env bash\nexit 0\n' > "$stub_root/$executable"
done
cat > "$stub_root/npm" <<'STUB'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'npm invoked\n' > .npm-fixture-called
if [[ ${1:-} == ci ]]; then
    if [[ ! -e node_modules && ! -L node_modules ]]; then
        mkdir -p node_modules
        printf 'build dependency\n' > node_modules/fixture-package
    fi
elif [[ ${1:-} == run && ${2:-} == build ]]; then
    [[ "$FOOTPRINT_SCENARIO" != build-failure ]] || exit 17
    mkdir -p public/build
    printf 'compiled asset\n' > public/build/fixture.js
    if [[ "$FOOTPRINT_SCENARIO" != missing-manifest ]]; then
        printf '{"fixture":{"file":"fixture.js"}}\n' > public/build/manifest.json
    fi
else
    exit 90
fi
STUB
chmod +x "$stub_root/php" "$stub_root/composer" "$stub_root/node" "$stub_root/npm"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

prepare() {
    scenario=$1
    site_root="$fixture_root/$scenario"
    release_root="$site_root/deploys/reviewed-release"
    private_root="$site_root/.basecrm"
    mkdir -p "$release_root/public" "$release_root/storage" "$private_root/storage" \
        "$site_root/deploys/previous/node_modules" "$site_root/external"
    for source_file in artisan composer.json composer.lock package-lock.json public/index.php; do
        printf 'fixture\n' > "$release_root/$source_file"
    done
    printf '{}\n' > "$private_root/manifest.json"
    printf 'fictional fixture only\n' > "$private_root/.env"
    printf 'persistent data\n' > "$private_root/storage/keep"
    printf 'previous release\n' > "$site_root/deploys/previous/node_modules/keep"
    printf 'external data\n' > "$site_root/external/keep"
    ln -s previous "$site_root/deploys/current"
}

run_hook() {
    status=0
    env PATH="$stub_root:$PATH" FOOTPRINT_SCENARIO="$scenario" \
        bash "$hook" "$site_root" "$release_root" > "$site_root/hook.log" 2>&1 || status=$?
}

assert_untouched() {
    [[ $(cat "$private_root/storage/keep") == 'persistent data' ]] || fail "$scenario changed persistent storage"
    [[ $(cat "$site_root/external/keep") == 'external data' ]] || fail "$scenario changed external data"
    [[ $(cat "$site_root/deploys/previous/node_modules/keep") == 'previous release' ]] || fail "$scenario changed a historical release"
    [[ $(readlink "$site_root/deploys/current") == previous ]] || fail "$scenario changed current"
    [[ -L "$release_root/storage" && $(readlink "$release_root/storage") == "$private_root/storage" ]] || fail "$scenario changed the shared-storage link"
    [[ -L "$release_root/.env" && $(readlink "$release_root/.env") == "$private_root/.env" ]] || fail "$scenario changed the private-environment link"
}

prepare success
run_hook
[[ $status == 0 ]] || fail 'valid deployment failed'
[[ ! -e "$release_root/node_modules" && ! -L "$release_root/node_modules" ]] || fail 'build dependencies remain after success'
[[ -s "$release_root/public/build/manifest.json" && -s "$release_root/public/build/fixture.js" ]] || fail 'compiled assets were removed'
assert_untouched
printf 'PASS: successful build removes only release-local dependencies\n'

for failed_build in build-failure missing-manifest; do
    prepare "$failed_build"
    run_hook
    [[ $status != 0 ]] || fail "$scenario unexpectedly succeeded"
    [[ -f "$release_root/node_modules/fixture-package" ]] || fail "$scenario removed dependencies before verifying the build"
    assert_untouched
    printf 'PASS: %s retains dependencies\n' "$scenario"
done

for unsafe_target in storage-symlink external-symlink file-target; do
    prepare "$unsafe_target"
    case "$unsafe_target" in
        storage-symlink) ln -s "$private_root/storage" "$release_root/node_modules" ;;
        external-symlink) ln -s "$site_root/external" "$release_root/node_modules" ;;
        file-target) printf 'unexpected file\n' > "$release_root/node_modules" ;;
    esac
    run_hook
    [[ $status != 0 ]] || fail "$scenario unexpectedly succeeded"
    [[ ! -e "$release_root/.npm-fixture-called" ]] || fail "$scenario reached npm with an unsafe dependency target"
    [[ -e "$release_root/node_modules" || -L "$release_root/node_modules" ]] || fail "$scenario was deleted"
    [[ $(cat "$site_root/hook.log") == *'Build dependencies must be a release-local node_modules directory before cleanup.'* ]] || fail "$scenario failed outside the cleanup guard"
    assert_untouched
    printf 'PASS: %s stops deployment without deleting its target\n' "$scenario"
done
