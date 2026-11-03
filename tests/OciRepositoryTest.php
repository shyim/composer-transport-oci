<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ComposerOci\Tests;

use Composer\Config;
use Composer\IO\NullIO;
use ComposerOci\OciRepository;
use Composer\Repository\RepositoryFactory;
use PHPUnit\Framework\TestCase;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use ComposerOci\OciRegistry;

class OciRepositoryTest extends TestCase
{
    /** @param array<string, mixed> $metadata */
    private function repository(array $metadata = [], bool $corrupt = false, bool $conflict = false): OciRepository
    {
        $metadata += ['name' => 'acme/library', 'version' => '1.2.3', 'require' => ['php' => '^8.0']];
        $body = (string) json_encode($metadata);
        $digest = 'sha256:'.hash('sha256', $body);
        $layer = ['digest' => 'sha256:'.str_repeat('a', 64), 'size' => 123, 'mediaType' => 'application/zip'];
        $manifest = ['schemaVersion' => 2, 'mediaType' => OciRegistry::MANIFEST_TYPE,
            'config' => ['mediaType' => OciRegistry::CONFIG_TYPE, 'digest' => $digest, 'size' => strlen($body)],
            'layers' => [$layer],
        ];
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->method('get')->willReturnCallback(static function (string $url) use ($body, $digest, $manifest, $corrupt, $conflict): Response {
            if (strpos($url, '/tags/list') !== false) {
                $data = (string) json_encode(['tags' => ['1.2.3', 'latest']]);
            } elseif (strpos($url, '/manifests/') !== false) {
                if ($conflict && substr($url, -6) === 'latest') {
                    $manifest['layers'][0]['digest'] = 'sha256:'.str_repeat('b', 64);
                }
                $data = (string) json_encode($manifest);
            } else {
                self::assertStringEndsWith('/blobs/'.$digest, $url);
                $data = $corrupt ? '{}' : $body;
            }

            return new Response(['url' => $url !== '' ? $url : 'https://registry.example'], 200, [], $data);
        });

        return new OciRepository(['url' => 'oci://registry.example/acme/library'], new NullIO(), new Config(), $http);
    }

    public function testLoadsMetadataAndDeduplicatesAliases(): void
    {
        $repo = $this->repository(['source' => ['type' => 'git', 'url' => 'https://evil.example', 'reference' => 'main'], 'transport-options' => ['http' => ['header' => ['Authorization: secret']]]]);
        $packages = $repo->getPackages();
        self::assertCount(1, $packages);
        self::assertSame('acme/library', $packages[0]->getName());
        self::assertSame('1.2.3.0', $packages[0]->getVersion());
        self::assertSame('oci-zip', $packages[0]->getDistType());
        self::assertSame('https://registry.example/v2/acme/library/blobs/sha256:'.str_repeat('a', 64), $packages[0]->getDistUrl());
        self::assertNull($packages[0]->getSourceUrl());
        self::assertSame([], $packages[0]->getTransportOptions());
        self::assertArrayHasKey('php', $packages[0]->getRequires());
        self::assertSame(['acme/library'], $repo->loadPackages(['acme/library' => null], ['stable' => 0], [])['namesFound']);
    }

    public function testRejectsCorruptMetadata(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('integrity verification failed');
        $this->repository([], true)->getPackages();
    }

    public function testRejectsConflictingVersions(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Conflicting');
        $this->repository([], false, true)->getPackages();
    }

}
