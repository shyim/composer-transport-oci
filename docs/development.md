# Development and validation

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

GitHub Actions runs unit tests on PHP 8.1–8.5 and static analysis on PHP 8.5.
The OCI integration matrix tests Composer 2.9.0 and the latest Composer 2 release
against anonymous and password-protected disposable registries. The protected
registry exercises Docker credential helpers; CI needs no registry secrets and
publishes nothing externally. Workflows run on pull requests and pushes to main,
and can also be started manually. Actions are pinned to release commit hashes.

[Back to the README](../README.md)
