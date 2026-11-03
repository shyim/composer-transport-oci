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

use Composer\Util\HttpDownloader;
use Composer\Util\AuthHelper;

use Composer\Pcre\Preg;
use Composer\IO\IOInterface;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\Util\Http\Response;

/** @internal */
class OciRegistry
{
    public const MANIFEST_TYPE = 'application/vnd.oci.image.manifest.v1+json';
    public const CONFIG_TYPE = 'application/vnd.composer.package.v1+json';

    /** @var HttpDownloader */
    private $http;
    /** @var non-empty-string */
    private $baseUrl;
    /** @var string */
    private $repository;
    /** @var ?string */
    private $token;

    private Config $config;

    /** @var ?IOInterface */
    private $io;
    /** @var ?DockerCredentials */
    private $dockerCredentials;
    /** @var string */
    private $origin;

    public function __construct(HttpDownloader $http, string $url, ?IOInterface $io = null, ?DockerCredentials $dockerCredentials = null, ?Config $config = null)
    {
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['scheme'])) {
            $parts['scheme'] = strtolower($parts['scheme']);
            if ($parts['scheme'] === 'oci') {
                $parts['scheme'] = 'https';
            }
        }
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || !in_array($parts['scheme'], ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !Preg::isMatch('{^/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*/?$}D', $parts['path'])
        ) {
            throw new \InvalidArgumentException('OCI repository URL must be oci://registry/repository or http(s)://registry/repository without credentials, query or fragment');
        }
        $this->http = $http;
        $this->io = $io;
        $this->config = $config ?? new Config();
        $this->dockerCredentials = $dockerCredentials;
        $this->origin = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $this->repository = trim($parts['path'], '/');
        $this->baseUrl = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/v2/'.$this->repository;
    }

    /** @return non-empty-string */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        $url = $this->baseUrl.'/tags/list?n=100';
        $tags = [];
        $visited = [];
        do {
            if (isset($visited[$url])) {
                throw new \UnexpectedValueException('OCI registry returned a pagination cycle');
            }
            $visited[$url] = true;
            $response = $this->request($url);
            $data = $response->decodeJson();
            if (!is_array($data) || !array_key_exists('tags', $data) || ($data['tags'] !== null && !is_array($data['tags']))) {
                throw new \UnexpectedValueException('Invalid OCI tag list');
            }
            foreach ($data['tags'] ?? [] as $tag) {
                if (!is_string($tag) || !Preg::isMatch('{^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}$}D', $tag)) {
                    throw new \UnexpectedValueException('Invalid OCI tag');
                }
                $tags[$tag] = $tag;
            }
            $url = null;
            $link = $response->getHeader('Link');
            if ($link !== null && Preg::isMatch('{<([^>]+)>;\s*rel="?next"?}i', $link, $match)) {
                $next = $match[1];
                if (strpos($next, '?') === 0) {
                    $next = $this->baseUrl.'/tags/list'.$next;
                } elseif (strpos($next, '/') === 0) {
                    $next = explode('/v2/', $this->baseUrl, 2)[0].$next;
                }
                if (strpos($next, $this->baseUrl.'/tags/list?') !== 0) {
                    throw new \UnexpectedValueException('OCI pagination must remain on the same tag-list endpoint');
                }
                $url = $next;
            }
        } while ($url !== null);

        return array_values($tags);
    }

    /** @param non-empty-string $url */
    public function request(string $url, ?string $file = null): Response
    {
        // Authentication is scoped to this repository and never stored in package metadata.
        if (strpos($url, $this->baseUrl.'/') !== 0) {
            throw new \InvalidArgumentException('OCI request is outside the configured repository');
        }
        $attempt = 0;
        while (true) {
            $options = ['retry-auth-failure' => false, 'http' => ['header' => ['Accept: '.self::MANIFEST_TYPE]]];
            $credentials = $this->getDockerCredentials($this->origin);
            if ($credentials !== null && $credentials['username'] !== '<token>') {
                $options['http']['header'][] = 'Authorization: Basic '.base64_encode($credentials['username'].':'.$credentials['password']);
            }
            if ($this->io !== null && $this->token === null) {
                $options = (new AuthHelper($this->io, $this->config))->addAuthenticationOptions($options, $this->origin, $url);
            }
            if ($this->token !== null) {
                $options['http']['header'] = ['Accept: '.self::MANIFEST_TYPE];
                $options['http']['header'][] = 'Authorization: Bearer '.$this->token;
            }
            try {
                return $file === null ? $this->http->get($url, $options) : $this->http->copy($url, $file, $options);
            } catch (TransportException $e) {
                $challenge = Response::findHeaderValue($e->getHeaders() ?? [], 'WWW-Authenticate');
                if ($attempt !== 0 || $e->getStatusCode() !== 401 || $challenge === null || stripos($challenge, 'Bearer ') !== 0) {
                    throw $e;
                }
                Preg::matchAll('{(realm|service)="([^"\r\n]+)"}i', $challenge, $matches);
                $parameters = [];
                foreach ($matches[1] as $index => $parameter) {
                    $parameters[strtolower($parameter)] = $matches[2][$index];
                }
                $realm = $parameters['realm'] ?? '';
                $parts = parse_url($realm);
                if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                    throw new \UnexpectedValueException('OCI token realm must be an HTTPS URL without credentials or fragment');
                }
                // HttpDownloader uses credentials configured for the token service's own host.
                $tokenUrl = $realm.(strpos($realm, '?') === false ? '?' : '&').http_build_query([
                    'service' => $parameters['service'] ?? '',
                    'scope' => 'repository:'.$this->repository.':pull',
                ], '', '&', PHP_QUERY_RFC3986);
                $tokenOrigin = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
                $tokenOptions = ['retry-auth-failure' => false];
                // Resolve the token service's own credentials; never forward a registry secret to another host.
                $credentialOrigin = $tokenOrigin;
                if ($tokenOrigin === 'auth.docker.io'
                    && in_array($this->origin, ['docker.io', 'index.docker.io', 'registry-1.docker.io'], true)
                    && $this->io !== null && !$this->hasComposerAuthentication($tokenOrigin)
                ) {
                    // Docker Hub has a fixed, separate authentication service.
                    $credentialOrigin = $this->origin;
                }
                $tokenCredentials = $this->getDockerCredentials($credentialOrigin);
                if ($tokenCredentials !== null) {
                    if ($tokenCredentials['username'] === '<token>') {
                        $tokenOptions['http'] = [
                            'method' => 'POST',
                            'header' => ['Content-Type: application/x-www-form-urlencoded'],
                            'content' => http_build_query([
                                'grant_type' => 'refresh_token',
                                'refresh_token' => $tokenCredentials['password'],
                                'service' => $parameters['service'] ?? '',
                                'scope' => 'repository:'.$this->repository.':pull',
                                'client_id' => 'composer',
                            ], '', '&', PHP_QUERY_RFC3986),
                        ];
                    } else {
                        $tokenOptions['http']['header'] = ['Authorization: Basic '.base64_encode($tokenCredentials['username'].':'.$tokenCredentials['password'])];
                    }
                }
                if ($this->io !== null) {
                    $tokenOptions = (new AuthHelper($this->io, $this->config))->addAuthenticationOptions($tokenOptions, $tokenOrigin, $tokenUrl);
                }
                $data = $this->http->get($tokenUrl, $tokenOptions)->decodeJson();
                $token = is_array($data) ? ($data['token'] ?? $data['access_token'] ?? null) : null;
                if (!is_string($token) || !Preg::isMatch('{^[a-zA-Z0-9._~+/-]+=*$}D', $token)) {
                    throw new \UnexpectedValueException('OCI token service returned an invalid token');
                }
                $this->token = $token;
                ++$attempt;
            }
        }
    }

    private function hasComposerAuthentication(string $origin): bool
    {
        if ($this->io === null) {
            return false;
        }
        if ($this->io->hasAuthentication($origin)) {
            return true;
        }

        return in_array($origin, ['api.github.com', 'api.bitbucket.org'], true)
            && $this->io->hasAuthentication(substr($origin, 4));
    }

    /** @return array{username: string, password: string}|null */
    private function getDockerCredentials(string $origin): ?array
    {
        if ($this->io === null || $this->hasComposerAuthentication($origin)) {
            return null;
        }
        if ($this->dockerCredentials === null) {
            $this->dockerCredentials = new DockerCredentials();
        }

        return $this->dockerCredentials->get($origin);
    }

    /** @param mixed $descriptor */
    public static function validateDescriptor($descriptor): void
    {
        if (!is_array($descriptor) || !isset($descriptor['digest'], $descriptor['size'], $descriptor['mediaType'])
            || !is_string($descriptor['digest']) || !Preg::isMatch('{^sha256:[a-f0-9]{64}$}D', $descriptor['digest'])
            || !is_int($descriptor['size']) || $descriptor['size'] < 0 || !is_string($descriptor['mediaType'])
        ) {
            throw new \UnexpectedValueException('Invalid OCI descriptor (SHA-256 digest, size and mediaType required)');
        }
    }
}
