<?php declare(strict_types=1);

// Run from a development checkout (composer install includes Composer's API).
require dirname(__DIR__).'/vendor/autoload.php';

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;
use ComposerOci\OciRepository;

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3) {
    fwrite(STDERR, "Usage: php tools/build-index.php oci://registry/repository output-directory\n");
    exit(1);
}
$io = new NullIO();
$config = Factory::createConfig($io);
$io->loadConfiguration($config);
$repository = new OciRepository(['url' => $arguments[1]], $io, $config, new HttpDownloader(new NullIO(), $config));
$artifact = $repository->buildIndex();
$directory = $arguments[2];
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create index output directory');
}
foreach (['config' => 'index.json', 'manifest' => 'manifest.json'] as $key => $file) {
    if (file_put_contents($directory.'/'.$file, $artifact[$key]) === false) {
        throw new RuntimeException('Could not write index artifact');
    }
}
$index = json_decode($artifact['config'], true, 512, JSON_THROW_ON_ERROR);
echo 'Built index for '.count($index['packages']).' tagged artifacts ('.strlen($artifact['config'])." metadata bytes).\n";
