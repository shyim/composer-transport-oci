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

namespace ComposerOci;

use Composer\Repository\ArrayRepository;
use Composer\Repository\ConfigurableRepositoryInterface;

use Composer\Config;
use Composer\Cache;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\HttpDownloader;

/**
 * One OCI repository contains the tagged versions of one Composer package.
 */
class OciRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    /** @var array<string, mixed> */
    private $repoConfig;
    private Cache $cache;

    /** @var OciRegistry */
    private $registry;

    /** @param array{url: string, index?: mixed} $repoConfig */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader)
    {
        parent::__construct();
        if (isset($repoConfig['index']) && !is_bool($repoConfig['index'])) {
            throw new \InvalidArgumentException('OCI repository index option must be a boolean');
        }
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/oci');
        $this->cache->setReadOnly($config->get('cache-read-only'));
        $this->repoConfig = $repoConfig;
        $this->registry = new OciRegistry($httpDownloader, $repoConfig['url'], $io, null, $config);
        $config->prohibitUrlByConfig($this->registry->getBaseUrl(), $io);
    }

    public function getRepoName()
    {
        return 'oci repo ('.$this->repoConfig['url'].')';
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    protected function initialize()
    {
        $entries = ($this->repoConfig['index'] ?? true) ? $this->loadIndex() : null;
        $packages = $this->createPackages($entries ?? $this->scanPackages());
        parent::initialize();
        foreach ($packages as $package) {
            $this->addPackage($package);
        }
    }

    /**
     * Build an authoritative index from release manifests, ignoring any existing index.
     * @return array{config: string, manifest: string}
     */
    public function buildIndex(): array
    {
        $entries = $this->scanPackages();
        $this->createPackages($entries); // Apply the same package and version checks as resolution.
        $layers = [];
        $packages = [];
        foreach ($entries as $entry) {
            $layers[$entry['archive']['digest']] = $entry['archive'];
            $packages[] = ['metadata' => $entry['metadata'], 'archive' => $entry['archive']['digest']];
        }
        $config = json_encode(['schemaVersion' => 1, 'packages' => $packages], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifest = json_encode([
            'schemaVersion' => 2,
            'mediaType' => OciRegistry::MANIFEST_TYPE,
            'artifactType' => OciRegistry::INDEX_TYPE,
            'config' => ['mediaType' => OciRegistry::INDEX_TYPE, 'digest' => 'sha256:'.hash('sha256', $config), 'size' => strlen($config)],
            'layers' => array_values($layers),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['config' => $config, 'manifest' => $manifest];
    }

    /** @return array|null */
    private function loadIndex(): ?array
    {
        try {
            $manifest = $this->registry->request($this->registry->getBaseUrl().'/manifests/'.OciRegistry::INDEX_TAG)->decodeJson();
        } catch (TransportException $e) {
            // Only an absent index permits a fallback. Auth and integrity failures must surface.
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
        $this->validateManifest($manifest);
        if (($manifest['config']['mediaType'] ?? null) !== OciRegistry::INDEX_TYPE) {
            throw new \UnexpectedValueException('Invalid Composer OCI repository index media type');
        }
        $layers = [];
        foreach ($manifest['layers'] as $layer) {
            $this->archiveType($layer);
            $descriptor = array_intersect_key($layer, array_flip(['digest', 'size', 'mediaType']));
            if (isset($layers[$layer['digest']]) && $layers[$layer['digest']] !== $descriptor) {
                throw new \UnexpectedValueException('Conflicting OCI index archive descriptors');
            }
            $layers[$layer['digest']] = $descriptor;
        }
        $index = $this->readMetadata($manifest['config'], true);
        if (($index['schemaVersion'] ?? null) !== 1 || !isset($index['packages']) || !is_array($index['packages']) || !array_is_list($index['packages'])) {
            throw new \UnexpectedValueException('Invalid Composer OCI repository index schema');
        }
        $entries = [];
        foreach ($index['packages'] as $entry) {
            if (!is_array($entry) || !isset($entry['metadata'], $entry['archive']) || !is_array($entry['metadata']) || !is_string($entry['archive']) || !isset($layers[$entry['archive']])) {
                throw new \UnexpectedValueException('OCI index package must reference an archive layer in its manifest');
            }
            $entries[] = ['metadata' => $entry['metadata'], 'archive' => $layers[$entry['archive']]];
        }

        return $entries;
    }

    /** @return array */
    private function scanPackages(): array
    {
        $entries = [];
        $base = $this->registry->getBaseUrl();
        foreach ($this->registry->getTags() as $tag) {
            if ($tag === OciRegistry::INDEX_TAG) {
                continue;
            }
            $manifest = $this->registry->request($base.'/manifests/'.$tag)->decodeJson();
            $this->validateManifest($manifest);
            if (($manifest['config']['mediaType'] ?? null) !== OciRegistry::CONFIG_TYPE) {
                continue;
            }
            if (count($manifest['layers']) !== 1) {
                throw new \UnexpectedValueException('Composer OCI artifacts must contain exactly one archive layer');
            }
            $layer = $manifest['layers'][0];
            $this->archiveType($layer);
            $entries[] = ['metadata' => $this->readMetadata($manifest['config']), 'archive' => array_intersect_key($layer, array_flip(['digest', 'size', 'mediaType']))];
        }

        return $entries;
    }

    private function validateManifest($manifest): void
    {
        if (!is_array($manifest) || ($manifest['schemaVersion'] ?? null) !== 2 || ($manifest['mediaType'] ?? null) !== OciRegistry::MANIFEST_TYPE
            || !isset($manifest['config'], $manifest['layers']) || !is_array($manifest['layers']) || !array_is_list($manifest['layers'])
        ) {
            throw new \UnexpectedValueException('Expected an OCI image manifest');
        }
        OciRegistry::validateDescriptor($manifest['config']);
    }

    private function archiveType($layer): string
    {
        OciRegistry::validateDescriptor($layer);
        $types = ['application/zip' => 'oci-zip', 'application/x-tar' => 'oci-tar', 'application/gzip' => 'oci-tar'];
        if (!isset($types[$layer['mediaType']])) {
            throw new \UnexpectedValueException('Unsupported Composer OCI archive media type: '.$layer['mediaType']);
        }

        return $types[$layer['mediaType']];
    }

    /** @return array */
    private function readMetadata(array $descriptor, bool $cache = false): array
    {
        OciRegistry::validateDescriptor($descriptor);
        $key = hash('sha256', $this->registry->getBaseUrl().'/'.$descriptor['digest']).'.json';
        $body = $cache ? $this->cache->read($key) : false;
        $valid = static fn ($value): bool => is_string($value) && strlen($value) === $descriptor['size'] && 'sha256:'.hash('sha256', $value) === $descriptor['digest'];
        if (!$valid($body)) {
            $body = $this->registry->request($this->registry->getBaseUrl().'/blobs/'.$descriptor['digest'])->getBody();
            if (!$valid($body)) {
                throw new \UnexpectedValueException('Composer OCI metadata integrity verification failed');
            }
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Invalid Composer OCI metadata JSON');
        }
        if ($cache && !$this->cache->isReadOnly()) {
            $this->cache->write($key, $body);
        }

        return $data;
    }

    /** @return array<\Composer\Package\BasePackage> */
    private function createPackages(array $entries): array
    {
        $packages = [];
        $name = null;
        $versions = [];
        $loader = new ArrayLoader();
        $base = $this->registry->getBaseUrl();
        foreach ($entries as $entry) {
            $data = $entry['metadata'];
            $layer = $entry['archive'];
            if (!isset($data['name'], $data['version']) || !is_string($data['name']) || !is_string($data['version'])) {
                throw new \UnexpectedValueException('Composer OCI metadata must contain name and version');
            }
            $fingerprint = hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR));
            unset($data['source'], $data['transport-options'], $data['version_normalized']);
            $data['dist'] = ['type' => $this->archiveType($layer), 'url' => $base.'/blobs/'.$layer['digest'], 'reference' => $layer['digest']];
            $package = $loader->load($data);
            if ($name !== null && $name !== $package->getName()) {
                throw new \UnexpectedValueException('An OCI repository must contain only one Composer package name');
            }
            $name = $package->getName();
            $version = $package->getVersion();
            if (isset($versions[$version])) {
                if ($versions[$version] !== $fingerprint) {
                    throw new \UnexpectedValueException('Conflicting Composer OCI artifacts for version '.$data['version']);
                }
                continue;
            }
            $versions[$version] = $fingerprint;
            $packages[] = $package;
        }

        return $packages;
    }
}
