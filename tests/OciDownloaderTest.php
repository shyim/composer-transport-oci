<?php declare(strict_types=1);

namespace ComposerOci\Tests;

use Composer\Cache;
use Composer\Config;
use Composer\Downloader\ZipDownloader;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use ComposerOci\OciDownloader;
use PHPUnit\Framework\TestCase;

class OciDownloaderTest extends TestCase
{
    /** @dataProvider provideBodies */
    public function testDigestCacheAndMetadataIsolation(string $body, bool $valid, bool $corruptCache): void
    {
        $dir = sys_get_temp_dir().'/oci-download-'.bin2hex(random_bytes(6));
        $io = new NullIO();
        $config = new Config();
        $config->merge(['config' => ['vendor-dir' => $dir.'/vendor']]);
        $package = new Package('acme/lib', '1.0.0.0', '1.0.0');
        $digest = 'sha256:'.hash('sha256', 'valid archive');
        $url = 'https://ghcr.io/v2/acme/lib/blobs/'.$digest;
        $package->setDistType('oci-zip');
        $package->setDistUrl($url);
        $package->setDistReference($digest);
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->expects(self::once())->method('copy')->willReturnCallback(static function (string $url, string $file) use ($body): Response {
            file_put_contents($file, $body);
            return new Response(['url' => $url], 200, [], null);
        });
        $archive = $this->getMockBuilder(ZipDownloader::class)->disableOriginalConstructor()->getMock();
        $archive->expects($valid ? self::exactly(2) : self::never())->method('download')->willReturnCallback(static function (PackageInterface $copy) use ($package) {
            self::assertNotSame($package, $copy);
            self::assertSame('zip', $copy->getDistType());
            self::assertStringStartsWith('file://', $copy->getDistUrl());
            self::assertSame('valid archive', file_get_contents($copy->getDistUrl()));
            return \React\Promise\resolve(null);
        });
        $cache = new Cache($io, $dir.'/cache');
        $downloader = new OciDownloader($io, $config, $http, $archive, 'zip', $cache);
        try {
            if ($corruptCache) {
                $cache->write($package->getName().'/'.hash('sha256', $url).'.zip', 'corrupted cache');
            }
            if (!$valid) {
                $this->expectException(\UnexpectedValueException::class);
                $this->expectExceptionMessage('integrity verification failed');
            }
            $downloader->download($package, $dir.'/install');
            $downloader->download($package, $dir.'/install');
            self::assertSame($url, $package->getDistUrl());
            self::assertSame($digest, $package->getDistReference());
            self::assertSame([], $package->getTransportOptions());
        } finally {
            (new Filesystem())->removeDirectory($dir);
        }
    }

    public static function provideBodies(): array
    {
        return [['valid archive', true, false], ['corrupted download', false, false], ['valid archive', true, true]];
    }
}
