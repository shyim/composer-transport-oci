# Fast version discovery

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

## Publishing an index

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

[Back to the README](../README.md)
