<?php declare(strict_types=1);

namespace ComposerOci;

use Composer\Cache;
use Composer\Config;
use Composer\Downloader\FileDownloader;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\HttpDownloader;
use React\Promise\PromiseInterface;

/** Fetches a verified OCI blob, then delegates extraction to stock Composer. */
class OciDownloader extends FileDownloader
{
    private FileDownloader $archive;
    private string $archiveType;
    /** @var array<int, PackageInterface> */
    private array $packages = [];

    public function __construct(IOInterface $io, Config $config, HttpDownloader $http, FileDownloader $archive, string $archiveType, ?Cache $cache = null)
    {
        parent::__construct($io, $config, $http, null, $cache);
        $this->archive = $archive;
        $this->archiveType = $archiveType;
    }

    private function archivePackage(PackageInterface $package): PackageInterface
    {
        $id = spl_object_id($package);
        if (!isset($this->packages[$id])) {
            $copy = clone $package;
            $copy->setDistType($this->archiveType);
            $copy->setTransportOptions([]);
            $this->packages[$id] = $copy;
        }

        return $this->packages[$id];
    }

    public function download(PackageInterface $package, string $path, ?PackageInterface $prevPackage = null, bool $output = true): PromiseInterface
    {
        $url = $package->getDistUrl();
        if ($url === null || !preg_match('{^(https?://[^/]+)/v2/(.+)/blobs/(sha256:[a-f0-9]{64})$}D', $url, $match) || $package->getDistReference() !== $match[3]) {
            throw new \UnexpectedValueException('Invalid OCI archive URL or locked digest');
        }
        $file = $this->getFileName($package, $path).'.'.$this->archiveType;
        $this->filesystem->ensureDirectoryExists(dirname($file));
        $key = $package->getName().'/'.hash('sha256', $url).'.'.$this->archiveType;
        $cached = $this->cache !== null && $this->cache->copyTo($key, $file);
        try {
            if (!$cached || 'sha256:'.hash_file('sha256', $file) !== $match[3]) {
                if ($cached && !$this->cache->isReadOnly()) {
                    $this->cache->remove($key);
                }
                $registry = new OciRegistry($this->httpDownloader, $match[1].'/'.$match[2], $this->io, null, $this->config);
                $registry->request($url, $file);
                if ('sha256:'.hash_file('sha256', $file) !== $match[3]) {
                    throw new \UnexpectedValueException('Composer OCI archive integrity verification failed');
                }
                if ($this->cache !== null && !$this->cache->isReadOnly()) {
                    $this->cache->copyFrom($key, $file);
                }
            }
            $copy = $this->archivePackage($package);
            $copy->setDistUrl('file://'.str_replace('\\', '/', $file));
            $copy->setDistReference(null);
            $promise = $this->archive->download($copy, $path, $prevPackage === null ? null : $this->archivePackage($prevPackage), $output);
        } catch (\Throwable $e) {
            if (is_file($file)) {
                $this->filesystem->unlink($file);
            }
            throw $e;
        }

        return $promise->then(function ($result) use ($file) {
            $this->filesystem->unlink($file);
            return $result;
        }, function (\Throwable $e) use ($file) {
            if (is_file($file)) {
                $this->filesystem->unlink($file);
            }
            throw $e;
        });
    }

    public function prepare(string $type, PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        return $this->archive->prepare($type, $this->archivePackage($package), $path, $prevPackage === null ? null : $this->archivePackage($prevPackage));
    }

    public function install(PackageInterface $package, string $path, bool $output = true): PromiseInterface
    {
        return $this->archive->install($this->archivePackage($package), $path, $output);
    }

    public function update(PackageInterface $initial, PackageInterface $target, string $path): PromiseInterface
    {
        return $this->archive->update($this->archivePackage($initial), $this->archivePackage($target), $path);
    }

    public function remove(PackageInterface $package, string $path, bool $output = true): PromiseInterface
    {
        return $this->archive->remove($this->archivePackage($package), $path, $output);
    }

    public function cleanup(string $type, PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        return $this->archive->cleanup($type, $this->archivePackage($package), $path, $prevPackage === null ? null : $this->archivePackage($prevPackage))->then(function () use ($package, $prevPackage) {
            unset($this->packages[spl_object_id($package)]);
            if ($prevPackage !== null) {
                unset($this->packages[spl_object_id($prevPackage)]);
            }
        });
    }
}
