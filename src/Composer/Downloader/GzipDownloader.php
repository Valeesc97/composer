<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Downloader;

use React\Promise\PromiseInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

/**
 * GZip archive downloader.
 *
 * @author Pavel Puchkin <i@neoascetic.me>
 */
class GzipDownloader extends ArchiveDownloader
{
    protected function extract(PackageInterface $package, $file, $path): ?PromiseInterface
    {
        $filename = pathinfo(parse_url($package->getDistUrl(), PHP_URL_PATH), PATHINFO_FILENAME);
        $targetFilepath = $path . DIRECTORY_SEPARATOR . $filename;

        // Try to use gunzip on *nix
        if (!Platform::isWindows()) {
            $command = 'gzip -cd -- ' . ProcessExecutor::escape($file) . ' > ' . ProcessExecutor::escape($targetFilepath);

            if (0 === $this->process->execute($command, $ignoredOutput)) {
                return \React\Promise\resolve();
            }

            if (extension_loaded('zlib')) {
                // Fallback to using the PHP extension.
                $this->extractUsingExt($file, $targetFilepath);

                return \React\Promise\resolve();
            }

            $processError = 'Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput();
            throw new \RuntimeException($processError);
        }

        // Windows version of PHP has built-in support of gzip functions
        $this->extractUsingExt($file, $targetFilepath);

        return \React\Promise\resolve();
    }

    /**
     * @param string $file
     * @param string $targetFilepath
     *
     * @return void
     */
    private function extractUsingExt($file, $targetFilepath): void
    {
        $archiveFile = gzopen($file, 'rb');
        $targetFile = fopen($targetFilepath, 'wb');
        while ($string = gzread($archiveFile, 4096)) {
            fwrite($targetFile, $string, Platform::strlen($string));
        }
        gzclose($archiveFile);
        fclose($targetFile);
    }
}
