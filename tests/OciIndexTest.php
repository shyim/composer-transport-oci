<?php declare(strict_types=1);

namespace ComposerOci\Tests;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\NullIO;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use ComposerOci\OciRegistry;
use ComposerOci\OciRepository;
use PHPUnit\Framework\TestCase;

class OciIndexTest extends TestCase
{
    private string $directory;
    private Config $config;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/oci-index-'.bin2hex(random_bytes(6));
        $this->config = new Config();
        $this->config->merge(['config' => ['cache-repo-dir' => $this->directory]]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectory($this->directory);
    }

    private function artifact(array $packages = []): array
    {
        $layer = ['mediaType' => 'application/zip', 'digest' => 'sha256:'.str_repeat('a', 64), 'size' => 123];
        $data = ['schemaVersion' => 1, 'packages' => $packages ?: [
            ['metadata' => ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '^8.1']], 'archive' => $layer['digest']],
            ['metadata' => ['name' => 'acme/lib', 'version' => '2.0.0'], 'archive' => $layer['digest']],
        ]];
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $manifest = ['schemaVersion' => 2, 'mediaType' => OciRegistry::MANIFEST_TYPE,
            'config' => ['mediaType' => OciRegistry::INDEX_TYPE, 'digest' => 'sha256:'.hash('sha256', $body), 'size' => strlen($body)], 'layers' => [$layer]];
        return [$body, $manifest];
    }

    private function http(string $body, array $manifest, int $calls): HttpDownloader
    {
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->expects(self::exactly($calls))->method('get')->willReturnCallback(static function (string $url) use ($body, $manifest): Response {
            if (str_ends_with($url, '/manifests/composer-index')) {
                return new Response(['url' => $url], 200, [], json_encode($manifest, JSON_THROW_ON_ERROR));
            }
            self::assertStringEndsWith('/blobs/'.$manifest['config']['digest'], $url);
            return new Response(['url' => $url], 200, [], $body);
        });
        return $http;
    }

    private function repo(HttpDownloader $http): OciRepository
    {
        return new OciRepository(['url' => 'oci://registry.example/acme/lib'], new NullIO(), $this->config, $http);
    }

    public function testColdIndexTakesTwoReadsAndWarmIndexOne(): void
    {
        [$body, $manifest] = $this->artifact();
        $cold = $this->repo($this->http($body, $manifest, 2))->getPackages();
        self::assertCount(2, $cold);
        self::assertSame('https://registry.example/v2/acme/lib/blobs/sha256:'.str_repeat('a', 64), $cold[0]->getDistUrl());
        self::assertSame('^8.1', $cold[0]->getRequires()['php']->getPrettyConstraint());
        $warm = $this->repo($this->http($body, $manifest, 1))->getPackages();
        self::assertSame(['1.0.0', '2.0.0'], array_map(static fn ($p) => $p->getPrettyVersion(), $warm));
    }

    public function testChangedIndexDigestLoadsNewVersions(): void
    {
        [$body, $manifest] = $this->artifact();
        $this->repo($this->http($body, $manifest, 2))->getPackages();
        [$body, $manifest] = $this->artifact([['metadata' => ['name' => 'acme/lib', 'version' => '3.0.0'], 'archive' => 'sha256:'.str_repeat('a', 64)]]);
        self::assertSame('3.0.0', $this->repo($this->http($body, $manifest, 2))->getPackages()[0]->getPrettyVersion());
    }

    public function testCorruptCachedBlobIsRefetched(): void
    {
        [$body, $manifest] = $this->artifact();
        $this->repo($this->http($body, $manifest, 2))->getPackages();
        foreach (glob($this->directory.'/oci/*.json') as $file) {
            file_put_contents($file, 'corrupt');
        }
        self::assertCount(2, $this->repo($this->http($body, $manifest, 2))->getPackages());
    }

    public function testCorruptRemoteBlobFailsWithoutFallback(): void
    {
        [$body, $manifest] = $this->artifact();
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('integrity verification failed');
        $this->repo($this->http('corrupt', $manifest, 2))->getPackages();
    }

    public function testArchiveMustBeReferencedByManifest(): void
    {
        [$body, $manifest] = $this->artifact([['metadata' => ['name' => 'acme/lib', 'version' => '1.0.0'], 'archive' => 'sha256:'.str_repeat('b', 64)]]);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('reference an archive layer');
        $this->repo($this->http($body, $manifest, 2))->getPackages();
    }

    /** @dataProvider provideStatuses */
    public function testOnlyNotFoundPermitsFallback(int $status): void
    {
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->expects(self::once())->method('get')->willReturnCallback(static function () use ($status) {
            $e = new TransportException('Failure');
            $e->setStatusCode($status);
            throw $e;
        });
        $this->expectException(TransportException::class);
        $this->repo($http)->getPackages();
    }

    public static function provideStatuses(): array
    {
        return [[401], [403], [429], [500]];
    }
}
