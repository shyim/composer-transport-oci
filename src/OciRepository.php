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
    /** @var OciRegistry */
    private $registry;

    /** @param array{url: string} $repoConfig */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader)
    {
        parent::__construct();
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
        // Build locally so a failed load cannot leave a partially initialized repository.
        $packages = [];
        $name = null;
        $versions = [];
        $loader = new ArrayLoader();
        $base = $this->registry->getBaseUrl();
        foreach ($this->registry->getTags() as $tag) {
            $response = $this->registry->request($base.'/manifests/'.$tag);
            $manifest = $response->decodeJson();
            if (!is_array($manifest) || ($manifest['schemaVersion'] ?? null) !== 2 || ($manifest['mediaType'] ?? null) !== OciRegistry::MANIFEST_TYPE) {
                throw new \UnexpectedValueException('Expected an OCI image manifest for tag '.$tag);
            }
            // Ignore images and other artifact types stored alongside Composer packages.
            if (($manifest['config']['mediaType'] ?? null) !== OciRegistry::CONFIG_TYPE) {
                continue;
            }
            if (!isset($manifest['layers']) || !is_array($manifest['layers']) || count($manifest['layers']) !== 1) {
                throw new \UnexpectedValueException('Composer OCI artifacts must contain exactly one archive layer');
            }
            $metadata = $manifest['config'];
            $layer = $manifest['layers'][0];
            OciRegistry::validateDescriptor($metadata);
            OciRegistry::validateDescriptor($layer);
            $types = ['application/zip' => 'oci-zip', 'application/x-tar' => 'oci-tar', 'application/gzip' => 'oci-tar'];
            if (!isset($types[$layer['mediaType']])) {
                throw new \UnexpectedValueException('Unsupported Composer OCI archive media type: '.$layer['mediaType']);
            }
            $response = $this->registry->request($base.'/blobs/'.$metadata['digest']);
            $body = (string) $response->getBody();
            if (strlen($body) !== $metadata['size'] || 'sha256:'.hash('sha256', $body) !== $metadata['digest']) {
                throw new \UnexpectedValueException('Composer OCI metadata integrity verification failed');
            }
            $data = $response->decodeJson();
            if (!is_array($data) || !isset($data['name'], $data['version']) || !is_string($data['name']) || !is_string($data['version'])) {
                throw new \UnexpectedValueException('Composer OCI metadata must contain name and version');
            }
            // The archive and transport are defined exclusively by the OCI manifest.
            unset($data['source'], $data['transport-options'], $data['version_normalized']);
            $data['dist'] = ['type' => $types[$layer['mediaType']], 'url' => $base.'/blobs/'.$layer['digest'], 'reference' => $layer['digest']];
            $package = $loader->load($data);
            if ($name !== null && $name !== $package->getName()) {
                throw new \UnexpectedValueException('An OCI repository must contain only one Composer package name');
            }
            $name = $package->getName();
            $version = $package->getVersion();
            if (isset($versions[$version])) {
                if ($versions[$version] !== $layer['digest'].':'.$metadata['digest']) {
                    throw new \UnexpectedValueException('Conflicting Composer OCI artifacts for version '.$data['version']);
                }
                continue;
            }
            $versions[$version] = $layer['digest'].':'.$metadata['digest'];
            $packages[] = $package;
        }
        parent::initialize();
        foreach ($packages as $package) {
            $this->addPackage($package);
        }
    }
}
