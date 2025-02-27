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

namespace Composer\Test\Mock;

use Composer\Config;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use Composer\Util\Http\Response;
use Composer\Downloader\TransportException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

class HttpDownloaderMock extends HttpDownloader
{
    /**
     * @var array<array{url: string, options: array<mixed>|null, status: int, body: string, headers: list<string>}>|null
     */
    private $expectations = null;
    /**
     * @var bool
     */
    private $strict = false;
    /**
     * @var array{status: int, body: string, headers: array<string>}
     */
    private $defaultHandler = array('status' => 200, 'body' => '', 'headers' => []);
    /**
     * @var string[]
     */
    private $log = array();

    public function __construct(IOInterface $io = null, Config $config = null)
    {
        if ($io === null) {
            $io = new BufferIO();
        }
        if ($config === null) {
            $config = new Config(false);
        }
        parent::__construct($io, $config);
    }

    /**
     * @param array<array{url: string, options?: array<mixed>, status?: int, body?: string, headers?: array<string>}> $expectations
     * @param bool                                                                                                    $strict         set to true if you want to provide *all* expected http requests, and not just a subset you are interested in testing
     * @param array{status?: int, body?: string, headers?: array<string>}                                             $defaultHandler default URL handler for undefined requests if not in strict mode
     */
    public function expects(array $expectations, bool $strict = false, array $defaultHandler = array('status' => 200, 'body' => '', 'headers' => [])): void
    {
        $default = ['url' => '', 'options' => null, 'status' => 200, 'body' => '', 'headers' => ['']];
        $this->expectations = array_map(function (array $expect) use ($default): array {
            if (count($diff = array_diff_key(array_merge($default, $expect), $default)) > 0) {
                throw new \UnexpectedValueException('Unexpected keys in process execution step: '.implode(', ', array_keys($diff)));
            }

            // set defaults in a PHPStan-happy way (array_merge is not well supported)
            $expect['url'] = $expect['url'] ?? $default['url'];
            $expect['options'] = $expect['options'] ?? $default['options'];
            $expect['status'] = $expect['status'] ?? $default['status'];
            $expect['body'] = $expect['body'] ?? $default['body'];
            $expect['headers'] = $expect['headers'] ?? $default['headers'];

            return $expect;
        }, $expectations);
        $this->strict = $strict;

        // set defaults in a PHPStan-happy way (array_merge is not well supported)
        $defaultHandler['status'] = $defaultHandler['status'] ?? $this->defaultHandler['status'];
        $defaultHandler['body'] = $defaultHandler['body'] ?? $this->defaultHandler['body'];
        $defaultHandler['headers'] = $defaultHandler['headers'] ?? $this->defaultHandler['headers'];

        $this->defaultHandler = $defaultHandler;
    }

    public function assertComplete(): void
    {
        // this was not configured to expect anything, so no need to react here
        if (!is_array($this->expectations)) {
            return;
        }

        if (count($this->expectations) > 0) {
            $expectations = array_map(function ($expect): string {
                return $expect['url'];
            }, $this->expectations);
            throw new AssertionFailedError(
                'There are still '.count($this->expectations).' expected HTTP requests which have not been consumed:'.PHP_EOL.
                implode(PHP_EOL, $expectations).PHP_EOL.PHP_EOL.
                'Received calls:'.PHP_EOL.implode(PHP_EOL, $this->log)
            );
        }

        // dummy assertion to ensure the test is not marked as having no assertions
        Assert::assertTrue(true); // @phpstan-ignore-line
    }

    public function get($fileUrl, $options = array()): Response
    {
        $this->log[] = $fileUrl;

        if (is_array($this->expectations) && count($this->expectations) > 0 && $fileUrl === $this->expectations[0]['url'] && ($this->expectations[0]['options'] === null || $options === $this->expectations[0]['options'])) {
            $expect = array_shift($this->expectations);

            return $this->respond($fileUrl, $expect['status'], $expect['headers'], $expect['body']);
        }

        if (!$this->strict) {
            return $this->respond($fileUrl, $this->defaultHandler['status'], $this->defaultHandler['headers'], $this->defaultHandler['body']);
        }

        throw new AssertionFailedError(
            'Received unexpected request for "'.$fileUrl.'"'.PHP_EOL.
            (is_array($this->expectations) && count($this->expectations) > 0 ? 'Expected "'.$this->expectations[0]['url'].'" at this point.' : 'Expected no more calls at this point.').PHP_EOL.
            'Received calls:'.PHP_EOL.implode(PHP_EOL, array_slice($this->log, 0, -1))
        );
    }

    /**
     * @param string[] $headers
     */
    private function respond(string $url, int $status, array $headers, string $body): Response
    {
        if ($status < 400) {
            return new Response(array('url' => $url), $status, $headers, $body);
        }

        $e = new TransportException('The "'.$url.'" file could not be downloaded', $status);
        $e->setHeaders($headers);
        $e->setResponse($body);

        throw $e;
    }
}
