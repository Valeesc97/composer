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

namespace Composer\Platform;

class Runtime
{
    /**
     * @param string $constant
     * @param class-string $class
     *
     * @return bool
     */
    public function hasConstant($constant, $class = null): bool
    {
        return defined(ltrim($class.'::'.$constant, ':'));
    }

    /**
     * @param string $constant
     * @param class-string $class
     *
     * @return mixed
     */
    public function getConstant($constant, $class = null)
    {
        return constant(ltrim($class.'::'.$constant, ':'));
    }

    /**
     * @param string $fn
     *
     * @return bool
     */
    public function hasFunction($fn): bool
    {
        return function_exists($fn);
    }

    /**
     * @param callable $callable
     * @param mixed[] $arguments
     *
     * @return mixed
     */
    public function invoke($callable, array $arguments = array())
    {
        return call_user_func_array($callable, $arguments);
    }

    /**
     * @param class-string $class
     *
     * @return bool
     */
    public function hasClass($class): bool
    {
        return class_exists($class, false);
    }

    /**
     * @param class-string $class
     * @param mixed[] $arguments
     *
     * @return object
     * @throws \ReflectionException
     */
    public function construct($class, array $arguments = array()): object
    {
        if (empty($arguments)) {
            return new $class;
        }

        $refl = new \ReflectionClass($class);

        return $refl->newInstanceArgs($arguments);
    }

    /** @return string[] */
    public function getExtensions(): array
    {
        return get_loaded_extensions();
    }

    /**
     * @param string $extension
     *
     * @return string
     */
    public function getExtensionVersion($extension): string
    {
        return phpversion($extension);
    }

    /**
     * @param string $extension
     *
     * @return string
     * @throws \ReflectionException
     */
    public function getExtensionInfo($extension): string
    {
        $reflector = new \ReflectionExtension($extension);

        ob_start();
        $reflector->info();

        return ob_get_clean();
    }
}
