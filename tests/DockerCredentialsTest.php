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

use PHPUnit\Framework\TestCase;
use ComposerOci\DockerCredentials;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class DockerCredentialsTest extends TestCase
{
    /** @var string */
    private $directory;
    /** @var string|false */
    private $oldPath;
    /** @var string|false */
    private $oldConfig;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/composer-docker-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->oldPath = Platform::getEnv('PATH');
        $this->oldConfig = Platform::getEnv('DOCKER_CONFIG');
        Platform::putEnv('DOCKER_CONFIG', $this->directory);
    }

    protected function tearDown(): void
    {
        if ($this->oldPath === false) {
            Platform::clearEnv('PATH');
        } else {
            Platform::putEnv('PATH', $this->oldPath);
        }
        if ($this->oldConfig === false) {
            Platform::clearEnv('DOCKER_CONFIG');
        } else {
            Platform::putEnv('DOCKER_CONFIG', $this->oldConfig);
        }
        (new Filesystem())->removeDirectory($this->directory);
    }

    /** @param array<string, mixed> $config */
    private function configure(array $config): void
    {
        file_put_contents($this->directory.'/config.json', json_encode($config));
    }

    public function testConfigDirectoryAndInlinePasswordWithColon(): void
    {
        $this->configure(['auths' => ['ghcr.io' => ['auth' => base64_encode('alice:secret:with:colons')]]]);
        self::assertSame(['username' => 'alice', 'password' => 'secret:with:colons'], (new DockerCredentials())->get('ghcr.io'));
        self::assertNull((new DockerCredentials())->get('other.example'));
    }

    public function testMissingConfigIsAnonymous(): void
    {
        self::assertNull((new DockerCredentials())->get('ghcr.io'));
    }

    public function testLegacyKeyAndDockerHubAlias(): void
    {
        $this->configure(['auths' => [
            'https://index.docker.io/v1/' => ['auth' => base64_encode('hub:password')],
            'https://registry.example:5443/v1/' => ['auth' => base64_encode('private:password')],
        ]]);
        self::assertSame('hub', (new DockerCredentials())->get('registry-1.docker.io')['username'] ?? null);
        self::assertSame('private', (new DockerCredentials())->get('registry.example:5443')['username'] ?? null);
        self::assertNull((new DockerCredentials())->get('registry.example'));
    }

    public function testIdentityToken(): void
    {
        $this->configure(['auths' => ['ghcr.io' => ['identitytoken' => 'refresh-secret']]]);
        self::assertSame(['username' => '<token>', 'password' => 'refresh-secret'], (new DockerCredentials())->get('ghcr.io'));
    }

    public function testPerRegistryHelperOverridesStoreAndAuths(): void
    {
        $this->configure(['credHelpers' => ['ghcr.io' => 'specific'], 'credsStore' => 'default', 'auths' => ['ghcr.io' => ['auth' => base64_encode('inline:secret')]]]);
        $credentials = $this->getMockBuilder(DockerCredentials::class)->setConstructorArgs([$this->directory])->onlyMethods(['readHelper'])->getMock();
        $credentials->expects(self::once())->method('readHelper')->with('specific', 'ghcr.io')->willReturn(['username' => 'helper', 'password' => 'secret']);
        self::assertSame('helper', $credentials->get('ghcr.io')['username'] ?? null);
        self::assertSame('helper', $credentials->get('ghcr.io')['username'] ?? null);
    }

    public function testDefaultStoreAndHubServerAddress(): void
    {
        $this->configure(['credsStore' => 'default']);
        $credentials = $this->getMockBuilder(DockerCredentials::class)->setConstructorArgs([$this->directory])->onlyMethods(['readHelper'])->getMock();
        $credentials->expects(self::once())->method('readHelper')->with('default', 'https://index.docker.io/v1/')->willReturn(null);
        self::assertNull($credentials->get('registry-1.docker.io'));
    }

    public function testInvalidHelperNameCannotExecuteShell(): void
    {
        $this->configure(['credsStore' => 'bad; echo secret']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid Docker credential helper name');
        (new DockerCredentials())->get('ghcr.io');
    }

    /** @dataProvider provideHelperResults */
    public function testRealHelperProtocol(string $body, ?string $expectedUser, ?string $expectedError): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('This executable fixture uses a Unix shebang');
        }
        $helper = $this->directory.'/docker-credential-fixture';
        file_put_contents($helper, '#!'.PHP_BINARY."\n<?php\n".'if ($argv[1] !== "get" || trim(stream_get_contents(STDIN)) !== "ghcr.io") { exit(9); }' . "\n".$body);
        chmod($helper, 0700);
        Platform::putEnv('PATH', $this->directory.PATH_SEPARATOR.$this->oldPath);
        $this->configure(['credHelpers' => ['ghcr.io' => 'fixture']]);
        if ($expectedError !== null) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($expectedError);
        }
        try {
            $result = (new DockerCredentials())->get('ghcr.io');
            self::assertSame($expectedUser, $result['username'] ?? null);
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('PRIVATE_SECRET', $e->getMessage());
            self::assertNull($e->getPrevious());
            throw $e;
        }
    }

    public static function provideHelperResults(): array
    {
        return [
            ['echo json_encode(["Username" => "alice", "Secret" => "PRIVATE_SECRET"]);', 'alice', null],
            ['echo "credentials not found in native keychain"; exit(1);', null, null],
            ['echo "PRIVATE_SECRET"; fwrite(STDERR, "PRIVATE_SECRET"); exit(1);', null, 'Docker credential helper docker-credential-fixture failed'],
            ['echo "PRIVATE_SECRET";', null, 'Docker credential helper returned invalid credentials'],
        ];
    }
}
