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

use Composer\Util\Platform;

use Composer\Pcre\Preg;
use Symfony\Component\Process\Process;

/** Reads Docker credentials without changing Docker or Composer configuration. @internal */
class DockerCredentials
{
    /** @var string */
    private $directory;
    /** @var array<string, array{username: string, password: string}|null> */
    private $credentials = [];

    public function __construct(?string $directory = null)
    {
        $configured = Platform::getEnv('DOCKER_CONFIG');
        $this->directory = $directory ?? ($configured !== false && $configured !== '' ? $configured : Platform::getUserDirectory().'/.docker');
    }

    /** @return array{username: string, password: string}|null */
    public function get(string $registry): ?array
    {
        if (array_key_exists($registry, $this->credentials)) {
            return $this->credentials[$registry];
        }

        return $this->credentials[$registry] = $this->load($registry);
    }

    /** @return array{username: string, password: string}|null */
    private function load(string $registry): ?array
    {
        $file = $this->directory.'/config.json';
        if (!is_file($file)) {
            return null;
        }
        $contents = @file_get_contents($file);
        $config = $contents !== false ? json_decode($contents, true) : null;
        if (!is_array($config)) {
            throw new \UnexpectedValueException('Unable to read Docker config.json as a JSON object');
        }
        // Docker Hub credentials traditionally use this address as their key.
        $server = in_array($registry, ['docker.io', 'index.docker.io', 'registry-1.docker.io'], true) ? 'https://index.docker.io/v1/' : $registry;
        $helper = $config['credHelpers'][$server] ?? $config['credHelpers'][$registry] ?? $config['credsStore'] ?? '';
        if (!is_string($helper)) {
            throw new \UnexpectedValueException('Invalid Docker credential helper configuration');
        }
        if ($helper !== '') {
            if (!Preg::isMatch('{^[a-zA-Z0-9][a-zA-Z0-9_.-]*$}D', $helper)) {
                throw new \UnexpectedValueException('Invalid Docker credential helper name');
            }
            return $this->readHelper($helper, $server);
        }
        $entry = $config['auths'][$server] ?? $config['auths'][$registry] ?? null;
        // Older Docker clients stored URL-shaped keys for other registries too.
        if ($entry === null && isset($config['auths']) && is_array($config['auths'])) {
            foreach ($config['auths'] as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $parts = parse_url(strpos($key, '://') === false ? 'https://'.$key : $key);
                if (is_array($parts) && isset($parts['host']) && $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') === $registry) {
                    $entry = $value;
                    break;
                }
            }
        }
        if (!is_array($entry)) {
            return null;
        }
        if (isset($entry['identitytoken']) && is_string($entry['identitytoken']) && $entry['identitytoken'] !== '') {
            return ['username' => '<token>', 'password' => $entry['identitytoken']];
        }
        if (!isset($entry['auth']) || $entry['auth'] === '') {
            return null;
        }
        $decoded = is_string($entry['auth']) ? base64_decode($entry['auth'], true) : false;
        if ($decoded === false || strpos($decoded, ':') === false) {
            throw new \UnexpectedValueException('Invalid Docker registry auth entry');
        }
        [$username, $password] = explode(':', $decoded, 2);

        return ['username' => $username, 'password' => $password];
    }

    /** @return array{username: string, password: string}|null */
    protected function readHelper(string $helper, string $server): ?array
    {
        // Array arguments avoid a shell; stdin carries the exact server address.
        $process = new Process(['docker-credential-'.$helper, 'get'], null, null, $server."\n", 30);
        try {
            $code = $process->run();
        } catch (\Throwable $e) {
            // Helper output and exceptions can contain secrets; never include them.
            throw new \RuntimeException('Unable to run Docker credential helper docker-credential-'.$helper);
        }
        if ($code !== 0) {
            if (strpos($process->getOutput(), 'credentials not found in native keychain') !== false) {
                return null;
            }
            throw new \RuntimeException('Docker credential helper docker-credential-'.$helper.' failed');
        }
        $data = json_decode($process->getOutput(), true);
        if (!is_array($data) || !isset($data['Username'], $data['Secret']) || !is_string($data['Username']) || !is_string($data['Secret']) || $data['Secret'] === '') {
            throw new \UnexpectedValueException('Docker credential helper returned invalid credentials');
        }

        return ['username' => $data['Username'], 'password' => $data['Secret']];
    }
}
