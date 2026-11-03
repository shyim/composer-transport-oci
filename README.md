# Composer OCI transport plugin

Install PHP packages directly from OCI registries using stock Composer 2.9+ and
PHP 8.1+. Includes `oci://` URLs, digest verification, ZIP/TAR/TAR.GZ archives,
Docker credential helpers and locked installs. No Composer core patch is needed.

## Installation

This repository is private and the package is not published on Packagist.
Authenticate with GitHub using an account that has access, then run these commands
from your consuming project:

```sh
composer config repositories.oci-plugin vcs https://github.com/shyim/composer-transport-oci
composer config allow-plugins.shyim/composer-transport-oci true
composer require shyim/composer-transport-oci:dev-main
```

Install the plugin **before** adding OCI dependencies for the first update.
Then add registry paths and require packages:

```json
{
    "extra": {
        "oci-repositories": [
            "oci://ghcr.io/acme/library"
        ]
    },
    "require": {
        "shyim/composer-transport-oci": "dev-main",
        "acme/library": "^1.2"
    }
}
```

Run `composer update`. Keep the plugin in `require`, including in production.
On a fresh `composer install`, `plugin-modifies-downloads` makes Composer install
and activate it before downloading OCI dependencies. `--no-plugins` disables OCI
support. Consumers need access to this private GitHub repository. For local development,
replace the VCS entry with a path repository pointing to your checkout.

Composer constructs its ordinary `repositories` before activating plugins, so
use `extra.oci-repositories`, **not** a top-level `type: oci` repository. Entries
may be URL strings or objects with `url`, `canonical`, `only`, `exclude` and `index`:

```json
{
    "extra": {
        "oci-repositories": [
            {"url": "oci://ghcr.io/acme/library", "only": ["acme/library"]}
        ]
    }
}
```

OCI repositories are prepended in configured order and are canonical by default.
Each path contains the tagged versions of one package. A bare `oci://ghcr.io`
is insufficient. `oci://` uses HTTPS; explicit HTTPS and HTTP URLs are accepted.
Metadata supplies the package name and version. Alias tags are deduplicated;
conflicting artifacts for one version are rejected. Registry catalogs and image
indexes are not supported.

## Fast version discovery

By default, the plugin checks the reserved `composer-index` tag first. If it is
absent (HTTP 404), it falls back to listing and reading individual release tags.
Authentication failures, server errors and corrupt indexes are reported rather
than silently falling back.

The index is an OCI image manifest with config media type
`application/vnd.composer.repository.v1+json`. Its config blob contains all
Composer versions and dependency/autoload metadata:

```json
{
    "schemaVersion": 1,
    "packages": [
        {
            "metadata": {"name": "acme/library", "version": "1.2.3"},
            "archive": "sha256:<archive digest>"
        }
    ]
}
```

Each `archive` digest must refer to a ZIP or TAR layer descriptor in the index
manifest. Layers reuse the existing release blobs; no archive content is
rewritten or duplicated. These references also keep the indexed archives
reachable for registry garbage collection. Locks still point directly to archive
blobs and do not depend on the mutable index tag.

A cold lookup reads the manifest and one metadata blob, independent of the number
of versions. Subsequent runs re-read the manifest and reuse a verified blob from
Composer's repository cache when the digest is unchanged. Authentication may add
requests. The cache key includes the registry/repository and SHA-256 digest;
changed indexes are picked up on the next update, and corrupt cached blobs are
fetched again. Authentication is checked before using cached metadata.

For the 120 FroshTools releases on GHCR, one measured Composer dry run went from
40.6 seconds scanning tags to 2.3 seconds with a cold index cache and 1.8 seconds
with a warm cache. These measurements include registry authentication and isolate
OCI lookup overhead by treating Shopware and Symfony as already satisfied; they
are not full Shopware installation timings.

### Publishing an index

After pushing your release archives, run this tool from a development checkout
with `composer install` completed. It uses Composer or Docker credentials:

```sh
php tools/build-index.php oci://ghcr.io/acme/library ./index
oras blob push ghcr.io/acme/library ./index/index.json
oras manifest push ghcr.io/acme/library:composer-index ./index/manifest.json \
  --media-type application/vnd.oci.image.manifest.v1+json
```

The builder scans release tags and validates their metadata. It does not download
archives. Upload the config blob first, then replace the index manifest tag; this
makes the complete new snapshot visible at once. Serialize publishers to avoid
replacing a newer snapshot with an older one.

**The index is authoritative.** Rebuild and publish it after adding, changing or
removing releases. New tags are invisible to indexed clients until then. To
force discovery from individual tags for a stale index or debugging:

```json
{"url": "oci://ghcr.io/acme/library", "index": false}
```

Older plugin versions can still scan the release tags and ignore the index
artifact. Keep release tags if those clients must remain supported.

## Composer artifact format (version 1)

Use an OCI image manifest (`application/vnd.oci.image.manifest.v1+json`) with:

* `schemaVersion` set to `2`.
* A `config` descriptor with media type
  `application/vnd.composer.package.v1+json`. Its blob contains the package's
  `composer.json`, including explicit `name` and `version` fields and its normal
  dependency, autoload and other package metadata.
* Exactly one archive layer: `application/zip`, `application/x-tar`, or
  `application/gzip` (a gzip-compressed TAR archive). The archive follows the
  same layout as ordinary Composer distribution archives.
* SHA-256 digests and byte sizes on both descriptors.

This is a Composer-specific artifact convention, not an existing OCI standard.
It uses the [OCI artifact guidance](https://github.com/opencontainers/image-spec/blob/main/artifacts-guidance.md)
and [Distribution API](https://github.com/opencontainers/distribution-spec/blob/main/spec.md).
If `artifactType` is supplied, use `application/vnd.composer.package.v1+json`.

For example, with a versioned `composer.json` and `package.zip`, publish using
[ORAS](https://oras.land/docs/commands/oras_push/):

```shell
oras push registry.example.com/php/acme/library:1.2.3 \
  --config composer.json:application/vnd.composer.package.v1+json \
  package.zip:application/zip
```

Composer verifies the metadata descriptor's size and digest, and verifies the
archive SHA-256 before extraction, including cached archives. The lock file
contains an `oci-zip` or `oci-tar` distribution pointing directly to an immutable
blob digest. Moving or deleting tags does not affect locked installs as long as
the referenced blob remains available. Registry garbage collection can still
remove unreferenced blobs. Source and transport overrides embedded in metadata
are ignored. These lock files require this plugin.

## Authentication and limitations

Explicit Composer `auth.json` credentials take precedence. Otherwise OCI requests
read `$DOCKER_CONFIG/config.json`, or `~/.docker/config.json` when `DOCKER_CONFIG`
is unset. Docker's per-registry `credHelpers` take precedence over `credsStore`,
which takes precedence over inline `auths` entries. Configured helpers are invoked
as `docker-credential-<name> get`, with the registry address on stdin, following
[Docker's credential helper protocol](https://docs.docker.com/reference/cli/docker/login/#credential-helper-protocol).
The helper must be available on `PATH`; Docker itself need not be installed.
Unconfigured platform-default helpers are not auto-detected.

For example, credentials saved by `docker login ghcr.io` are available to an
`oci://ghcr.io/acme/library` repository, including when installing from a lock file.
The config and credential stores are read only; credentials are not copied into
Composer's `auth.json`, package metadata or lock files. Missing credentials allow
anonymous access. Malformed configuration, a missing configured helper, or helper
failures produce an error without including helper output or secrets.

Registry Bearer challenges request repository-scoped `pull` tokens. Docker
identity tokens (including a helper's `<token>` username) use the OAuth refresh
token exchange. A separate token-service hostname needs its own credentials;
registry secrets are not forwarded to arbitrary hosts. Docker Hub's known
`auth.docker.io` service uses the Docker Hub registry credentials. Explicit
Composer credentials for the token service still take precedence. Token endpoints
must use HTTPS. Acquired tokens stay in memory.

HTTP registries require the normal `secure-http` configuration exception and
should be limited to local development. Downloads currently run synchronously,
use a verified archive cache, and delegate extraction to Composer. File-download
plugin events apply to the verified local archive; they do not intercept the OCI
registry transfer. The index manifest is refreshed during resolution; its immutable metadata blob
is cached. Repositories without an index still scan individual tags. Locked
installs need only the archive blob. Publishing, signing and signature verification are outside
this repository type's scope.


## Development and validation

```sh
composer install
composer test
composer analyse
```

Unit tests cover authentication precedence, helper execution, token challenges,
metadata and archive integrity, cache recovery, and plugin activation. The local
integration check uses a disposable Distribution registry and stock Composer:

```sh
docker run -d --rm -p 127.0.0.1::5000 --name composer-oci-plugin-validation registry:2
python3 tests/integration.py
docker stop composer-oci-plugin-validation
```

Set `COMPOSER_BINARY` to an executable Composer release to test another version.
For an htpasswd-protected registry with username `test`, set `OCI_TEST_PASSWORD`;
the check creates a temporary Docker credential helper and verifies update,
uncached locked install, autoloading and absence of secrets/local URLs in the lock
file for all three archive formats. Nothing is published to a hosted registry.
