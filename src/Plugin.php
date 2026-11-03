<?php declare(strict_types=1);

namespace ComposerOci;

use Composer\Cache;
use Composer\Composer;
use Composer\Downloader\TarDownloader;
use Composer\Downloader\ZipDownloader;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Plugin\PluginInterface;
use Composer\Repository\FilterRepository;
use Composer\Util\HttpDownloader;

final class Plugin implements PluginInterface
{
    private bool $active = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        if ($this->active) {
            return;
        }
        $config = $composer->getConfig();
        // Authentication headers are selected by OciRegistry. An isolated IO prevents
        // stock Composer from adding a second credential after a Bearer challenge.
        $http = new HttpDownloader(new NullIO(), $config);
        $cache = new Cache($io, $config->get('cache-files-dir'));
        $cache->setReadOnly($config->get('cache-read-only'));
        $loop = $composer->getLoop();
        $downloads = $composer->getDownloadManager();
        foreach (['zip' => ZipDownloader::class, 'tar' => TarDownloader::class] as $type => $class) {
            $archive = new $class($io, $config, $loop->getHttpDownloader(), $composer->getEventDispatcher(), null, null, $loop->getProcessExecutor());
            $downloads->setDownloader('oci-'.$type, new OciDownloader($io, $config, $http, $archive, $type, $cache));
        }
        $repositories = $composer->getPackage()->getExtra()['oci-repositories'] ?? [];
        if (!is_array($repositories)) {
            throw new \InvalidArgumentException('extra.oci-repositories must be an array');
        }
        // Prepending in reverse preserves the configured repository priority.
        foreach (array_reverse($repositories) as $repository) {
            if (is_string($repository)) {
                $repository = ['url' => $repository];
            }
            if (!is_array($repository) || !isset($repository['url']) || !is_string($repository['url'])) {
                throw new \InvalidArgumentException('Each OCI repository needs a URL');
            }
            foreach ($composer->getRepositoryManager()->getRepositories() as $existing) {
                $unwrapped = $existing instanceof FilterRepository ? $existing->getRepository() : $existing;
                if ($unwrapped instanceof OciRepository && $unwrapped->getRepoConfig() === $repository) {
                    continue 2;
                }
            }
            $repo = new OciRepository($repository, $io, $config, $http);
            if (isset($repository['canonical']) || isset($repository['only']) || isset($repository['exclude'])) {
                $repo = new FilterRepository($repo, $repository);
            }
            $composer->getRepositoryManager()->prependRepository($repo);
        }
        $this->active = true;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Composer has no public removeRepository/removeDownloader API. The
        // next activation replaces downloaders; a new process rebuilds repositories.
        $this->active = false;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $this->deactivate($composer, $io);
    }
}
