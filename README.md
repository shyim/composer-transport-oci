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
may be URL strings or objects with `url`, `canonical`, `only` and `exclude`:

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
registry transfer. Metadata is fetched afresh during resolution; locked installs need only
the archive blob. Publishing, signing and signature verification are outside
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
