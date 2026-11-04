# Composer artifact format (version 1)

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

[Back to the README](../README.md)
