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

namespace Composer\Config;

use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Json\JsonValidationException;
use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use Composer\Util\Silencer;

/**
 * JSON Configuration Source
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Beau Simensen <beau@dflydev.com>
 */
class JsonConfigSource implements ConfigSourceInterface
{
    /**
     * @var JsonFile
     */
    private $file;

    /**
     * @var bool
     */
    private $authConfig;

    /**
     * Constructor
     *
     * @param JsonFile $file
     * @param bool     $authConfig
     */
    public function __construct(JsonFile $file, $authConfig = false)
    {
        $this->file = $file;
        $this->authConfig = $authConfig;
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return $this->file->getPath();
    }

    /**
     * @inheritDoc
     */
    public function addRepository($name, $config, $append = true)
    {
        $this->manipulateJson('addRepository', function (&$config, $repo, $repoConfig) use ($append): void {
            // if converting from an array format to hashmap format, and there is a {"packagist.org":false} repo, we have
            // to convert it to "packagist.org": false key on the hashmap otherwise it fails schema validation
            if (isset($config['repositories'])) {
                foreach ($config['repositories'] as $index => $val) {
                    if ($index === $repo) {
                        continue;
                    }
                    if (is_numeric($index) && ($val === array('packagist' => false) || $val === array('packagist.org' => false))) {
                        unset($config['repositories'][$index]);
                        $config['repositories']['packagist.org'] = false;
                        break;
                    }
                }
            }

            if ($append) {
                $config['repositories'][$repo] = $repoConfig;
            } else {
                $config['repositories'] = array($repo => $repoConfig) + $config['repositories'];
            }
        }, $name, $config, $append);
    }

    /**
     * @inheritDoc
     */
    public function removeRepository($name)
    {
        $this->manipulateJson('removeRepository', function (&$config, $repo): void {
            unset($config['repositories'][$repo]);
        }, $name);
    }

    /**
     * @inheritDoc
     */
    public function addConfigSetting($name, $value)
    {
        $authConfig = $this->authConfig;
        $this->manipulateJson('addConfigSetting', function (&$config, $key, $val) use ($authConfig): void {
            if (Preg::isMatch('{^(bitbucket-oauth|github-oauth|gitlab-oauth|gitlab-token|bearer|http-basic|platform)\.}', $key)) {
                list($key, $host) = explode('.', $key, 2);
                if ($authConfig) {
                    $config[$key][$host] = $val;
                } else {
                    $config['config'][$key][$host] = $val;
                }
            } else {
                $config['config'][$key] = $val;
            }
        }, $name, $value);
    }

    /**
     * @inheritDoc
     */
    public function removeConfigSetting($name)
    {
        $authConfig = $this->authConfig;
        $this->manipulateJson('removeConfigSetting', function (&$config, $key) use ($authConfig): void {
            if (Preg::isMatch('{^(bitbucket-oauth|github-oauth|gitlab-oauth|gitlab-token|bearer|http-basic|platform)\.}', $key)) {
                list($key, $host) = explode('.', $key, 2);
                if ($authConfig) {
                    unset($config[$key][$host]);
                } else {
                    unset($config['config'][$key][$host]);
                }
            } else {
                unset($config['config'][$key]);
            }
        }, $name);
    }

    /**
     * @inheritDoc
     */
    public function addProperty($name, $value)
    {
        $this->manipulateJson('addProperty', function (&$config, $key, $val): void {
            if (strpos($key, 'extra.') === 0 || strpos($key, 'scripts.') === 0) {
                $bits = explode('.', $key);
                $last = array_pop($bits);
                $arr = &$config[reset($bits)];
                foreach ($bits as $bit) {
                    if (!isset($arr[$bit])) {
                        $arr[$bit] = array();
                    }
                    $arr = &$arr[$bit];
                }
                $arr[$last] = $val;
            } else {
                $config[$key] = $val;
            }
        }, $name, $value);
    }

    /**
     * @inheritDoc
     */
    public function removeProperty($name)
    {
        $this->manipulateJson('removeProperty', function (&$config, $key): void {
            if (strpos($key, 'extra.') === 0 || strpos($key, 'scripts.') === 0) {
                $bits = explode('.', $key);
                $last = array_pop($bits);
                $arr = &$config[reset($bits)];
                foreach ($bits as $bit) {
                    if (!isset($arr[$bit])) {
                        return;
                    }
                    $arr = &$arr[$bit];
                }
                unset($arr[$last]);
            } else {
                unset($config[$key]);
            }
        }, $name);
    }

    /**
     * @inheritDoc
     */
    public function addLink($type, $name, $value)
    {
        $this->manipulateJson('addLink', function (&$config, $type, $name, $value): void {
            $config[$type][$name] = $value;
        }, $type, $name, $value);
    }

    /**
     * @inheritDoc
     */
    public function removeLink($type, $name)
    {
        $this->manipulateJson('removeSubNode', function (&$config, $type, $name): void {
            unset($config[$type][$name]);
        }, $type, $name);
        $this->manipulateJson('removeMainKeyIfEmpty', function (&$config, $type): void {
            if (0 === count($config[$type])) {
                unset($config[$type]);
            }
        }, $type);
    }

    /**
     * @param string $method
     * @param callable $fallback
     * @param mixed ...$args
     *
     * @return void
     */
    private function manipulateJson($method, $fallback, ...$args): void
    {
        if ($this->file->exists()) {
            if (!is_writable($this->file->getPath())) {
                throw new \RuntimeException(sprintf('The file "%s" is not writable.', $this->file->getPath()));
            }

            if (!Filesystem::isReadable($this->file->getPath())) {
                throw new \RuntimeException(sprintf('The file "%s" is not readable.', $this->file->getPath()));
            }

            $contents = file_get_contents($this->file->getPath());
        } elseif ($this->authConfig) {
            $contents = "{\n}\n";
        } else {
            $contents = "{\n    \"config\": {\n    }\n}\n";
        }

        $manipulator = new JsonManipulator($contents);

        $newFile = !$this->file->exists();

        // override manipulator method for auth config files
        if ($this->authConfig && $method === 'addConfigSetting') {
            $method = 'addSubNode';
            list($mainNode, $name) = explode('.', $args[0], 2);
            $args = array($mainNode, $name, $args[1]);
        } elseif ($this->authConfig && $method === 'removeConfigSetting') {
            $method = 'removeSubNode';
            list($mainNode, $name) = explode('.', $args[0], 2);
            $args = array($mainNode, $name);
        }

        // try to update cleanly
        if (call_user_func_array(array($manipulator, $method), $args)) {
            file_put_contents($this->file->getPath(), $manipulator->getContents());
        } else {
            // on failed clean update, call the fallback and rewrite the whole file
            $config = $this->file->read();
            $this->arrayUnshiftRef($args, $config);
            call_user_func_array($fallback, $args);
            // avoid ending up with arrays for keys that should be objects
            foreach (array('require', 'require-dev', 'conflict', 'provide', 'replace', 'suggest', 'config', 'autoload', 'autoload-dev', 'scripts', 'scripts-descriptions', 'support') as $prop) {
                if (isset($config[$prop]) && $config[$prop] === array()) {
                    $config[$prop] = new \stdClass;
                }
            }
            foreach (array('psr-0', 'psr-4') as $prop) {
                if (isset($config['autoload'][$prop]) && $config['autoload'][$prop] === array()) {
                    $config['autoload'][$prop] = new \stdClass;
                }
                if (isset($config['autoload-dev'][$prop]) && $config['autoload-dev'][$prop] === array()) {
                    $config['autoload-dev'][$prop] = new \stdClass;
                }
            }
            foreach (array('platform', 'http-basic', 'bearer', 'gitlab-token', 'gitlab-oauth', 'github-oauth', 'preferred-install') as $prop) {
                if (isset($config['config'][$prop]) && $config['config'][$prop] === array()) {
                    $config['config'][$prop] = new \stdClass;
                }
            }
            $this->file->write($config);
        }

        try {
            $this->file->validateSchema(JsonFile::LAX_SCHEMA);
        } catch (JsonValidationException $e) {
            // restore contents to the original state
            file_put_contents($this->file->getPath(), $contents);
            throw new \RuntimeException('Failed to update composer.json with a valid format, reverting to the original content. Please report an issue to us with details (command you run and a copy of your composer.json). '.PHP_EOL.implode(PHP_EOL, $e->getErrors()), 0, $e);
        }

        if ($newFile) {
            Silencer::call('chmod', $this->file->getPath(), 0600);
        }
    }

    /**
     * Prepend a reference to an element to the beginning of an array.
     *
     * @param  mixed[] $array
     * @param  mixed $value
     * @return int
     */
    private function arrayUnshiftRef(&$array, &$value): int
    {
        $return = array_unshift($array, '');
        $array[0] = &$value;

        return $return;
    }
}
