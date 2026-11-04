# Authentication and limitations

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
registry transfer. The index manifest is refreshed during resolution; its immutable
metadata blob is cached. Repositories without an index still scan individual tags. Locked
installs need only the archive blob. ORAS handles publishing; signature verification
is outside this plugin's scope.

[Back to the README](../README.md)
