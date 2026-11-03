<?php declare(strict_types=1);

namespace ComposerOci\Tests;

use Composer\Factory;
use Composer\IO\NullIO;
use ComposerOci\OciDownloader;
use ComposerOci\OciRepository;
use ComposerOci\Plugin;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    public function testActivationRegistersRepositoriesAndDownloaders(): void
    {
        $io = new NullIO();
        $composer = Factory::create($io, [
            'name' => 'test/project',
            'version' => '1.0.0',
            'repositories' => [['packagist.org' => false]],
            'extra' => ['oci-repositories' => ['oci://ghcr.io/acme/first', 'oci://ghcr.io/acme/second']],
        ], true);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->activate($composer, $io);
        $plugin->deactivate($composer, $io);
        $plugin->activate($composer, $io);
        $repos = $composer->getRepositoryManager()->getRepositories();
        self::assertCount(2, $repos);
        self::assertInstanceOf(OciRepository::class, $repos[0]);
        self::assertSame('oci://ghcr.io/acme/first', $repos[0]->getRepoConfig()['url']);
        self::assertInstanceOf(OciDownloader::class, $composer->getDownloadManager()->getDownloader('oci-zip'));
        self::assertInstanceOf(OciDownloader::class, $composer->getDownloadManager()->getDownloader('oci-tar'));
    }
}
