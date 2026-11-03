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

use Composer\Downloader\TransportException;
use Composer\IO\NullIO;
use ComposerOci\DockerCredentials;
use PHPUnit\Framework\TestCase;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use ComposerOci\OciRegistry;

class OciRegistryTest extends TestCase
{
    /** @dataProvider provideRepositoryUrls */
    public function testRepositoryUrlTransport(string $url, string $base): void
    {
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->expects(self::once())->method('get')->with($base.'/tags/list?n=100')
            ->willReturn(new Response(['url' => 'https://ghcr.io'], 200, [], '{"tags":[]}'));
        $registry = new OciRegistry($http, $url);
        self::assertSame($base, $registry->getBaseUrl());
        self::assertSame([], $registry->getTags());
    }

    public static function provideRepositoryUrls(): array
    {
        return [
            ['oci://ghcr.io/acme/library', 'https://ghcr.io/v2/acme/library'],
            ['OCI://ghcr.io:5443/acme/library/', 'https://ghcr.io:5443/v2/acme/library'],
            ['https://ghcr.io/acme/library', 'https://ghcr.io/v2/acme/library'],
            ['http://localhost:5000/acme/library', 'http://localhost:5000/v2/acme/library'],
        ];
    }

    public function testPaginationAndNullTags(): void
    {
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->expects(self::exactly(2))->method('get')->willReturnOnConsecutiveCalls(
            new Response(['url' => 'https://registry.example'], 200, ['Link: </v2/acme/lib/tags/list?n=1&last=1.0>; rel="next"'], '{"tags":["1.0"]}'),
            new Response(['url' => 'https://registry.example'], 200, [], '{"tags":null}')
        );
        self::assertSame(['1.0'], (new OciRegistry($http, 'https://registry.example/acme/lib'))->getTags());
    }

    /** @dataProvider invalidLinks */
    public function testRejectsUnsafePagination(string $link): void
    {
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->method('get')->willReturn(new Response(['url' => 'https://registry.example'], 200, ['Link: <'.$link.'>; rel="next"'], '{"tags":[]}'));
        $this->expectException(\UnexpectedValueException::class);
        (new OciRegistry($http, 'https://registry.example/acme/lib'))->getTags();
    }

    /** @return list<array{string}> */
    public static function invalidLinks(): array
    {
        return [['https://evil.example/v2/acme/lib/tags/list?n=1'], ['/v2/other/lib/tags/list?n=1'], ['?n=100']];
    }

    public function testBearerChallengeRequestsOnlyPullAndRetries(): void
    {
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $call = 0;
        $http->expects(self::exactly(3))->method('get')->willReturnCallback(static function (string $url, array $options) use (&$call): Response {
            ++$call;
            if ($call === 1) {
                self::assertFalse($options['retry-auth-failure']);
                $e = new TransportException('Unauthorized');
                $e->setStatusCode(401);
                $e->setHeaders(['WWW-Authenticate: Bearer realm="https://auth.example/token",service="registry.example",scope="repository:other:pull,push"']);
                throw $e;
            }
            if ($call === 2) {
                self::assertSame('https://auth.example/token?service=registry.example&scope=repository%3Aacme%2Flib%3Apull', $url);
                self::assertSame([], $options['http']['header'] ?? []);

                return new Response(['url' => $url], 200, [], '{"access_token":"secret-token"}');
            }
            self::assertContains('Authorization: Bearer secret-token', $options['http']['header']);

            return new Response(['url' => $url !== '' ? $url : 'https://registry.example'], 200, [], '{"tags":[]}');
        });
        self::assertSame([], (new OciRegistry($http, 'https://registry.example/acme/lib'))->getTags());
    }

    public function testComposerCredentialsTakePrecedence(): void
    {
        $io = new NullIO();
        $io->setAuthentication('ghcr.io', 'composer-user', 'composer-secret');
        $credentials = $this->getMockBuilder(DockerCredentials::class)->disableOriginalConstructor()->getMock();
        $credentials->expects(self::never())->method('get');
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->method('get')->willReturnCallback(static function (string $url, array $options): Response {
            self::assertContains('Authorization: Basic '.base64_encode('composer-user:composer-secret'), $options['http']['header']);

            return new Response(['url' => 'https://ghcr.io'], 200, [], '{"tags":[]}');
        });
        self::assertSame([], (new OciRegistry($http, 'oci://ghcr.io/acme/lib', $io, $credentials))->getTags());
    }

    /** @dataProvider provideDockerTokenCredentials */
    public function testDockerCredentialsAuthenticateChallenge(string $username, bool $sameHost): void
    {
        $credentials = $this->getMockBuilder(DockerCredentials::class)->disableOriginalConstructor()->getMock();
        $credentials->method('get')->willReturnCallback(static function (string $origin) use ($username): ?array {
            return $origin === 'ghcr.io' ? ['username' => $username, 'password' => 'docker-secret'] : null;
        });
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $call = 0;
        $http->expects(self::exactly(3))->method('get')->willReturnCallback(static function (string $url, array $options) use (&$call, $username, $sameHost): Response {
            ++$call;
            if ($call === 1) {
                if ($username !== '<token>') {
                    self::assertContains('Authorization: Basic '.base64_encode($username.':docker-secret'), $options['http']['header']);
                } else {
                    self::assertSame(['Accept: '.OciRegistry::MANIFEST_TYPE], $options['http']['header']);
                }
                $e = new TransportException('Unauthorized');
                $e->setStatusCode(401);
                $e->setHeaders(['WWW-Authenticate: Bearer realm="https://'.($sameHost ? 'ghcr.io' : 'auth.example').'/token",service="ghcr.io"']);
                throw $e;
            }
            if ($call === 2) {
                if (!$sameHost) {
                    self::assertSame([], $options['http']['header'] ?? []);
                } elseif ($username === '<token>') {
                    self::assertSame('POST', $options['http']['method']);
                    parse_str($options['http']['content'], $form);
                    self::assertSame('refresh_token', $form['grant_type']);
                    self::assertSame('docker-secret', $form['refresh_token']);
                } else {
                    self::assertSame(['Authorization: Basic '.base64_encode($username.':docker-secret')], $options['http']['header']);
                }

                return new Response(['url' => 'https://ghcr.io'], 200, [], '{"token":"registry-token"}');
            }
            self::assertSame(['Accept: '.OciRegistry::MANIFEST_TYPE, 'Authorization: Bearer registry-token'], $options['http']['header']);

            return new Response(['url' => 'https://ghcr.io'], 200, [], '{"tags":[]}');
        });
        self::assertSame([], (new OciRegistry($http, 'oci://ghcr.io/acme/lib', new NullIO(), $credentials))->getTags());
    }

    public static function provideDockerTokenCredentials(): array
    {
        return [['alice', true], ['<token>', true], ['alice', false], ['<token>', false]];
    }

    /** @dataProvider invalidUrls */
    public function testRejectsInvalidRepositoryUrls(string $url): void
    {
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $this->expectException(\InvalidArgumentException::class);
        new OciRegistry($http, $url);
    }

    /** @return list<array{string}> */
    public static function invalidUrls(): array
    {
        return [['oci://ghcr.io'], ['oci://user:password@ghcr.io/acme/lib'], ['oci://ghcr.io/acme/lib?foo'], ['oci://ghcr.io/acme/../lib'], ['file:///tmp/package'], ['https://user:password@registry.example/acme/lib'], ['https://registry.example/acme/../lib'], ['https://registry.example/acme/lib?foo'], ['https://registry.example']];
    }
}
