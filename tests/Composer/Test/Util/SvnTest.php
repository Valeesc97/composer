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

namespace Composer\Test\Util;

use Composer\Config;
use Composer\IO\NullIO;
use Composer\Util\Svn;
use Composer\Test\TestCase;

class SvnTest extends TestCase
{
    /**
     * Test the credential string.
     *
     * @param string $url    The SVN url.
     * @param string $expect The expectation for the test.
     *
     * @dataProvider urlProvider
     */
    public function testCredentials($url, $expect): void
    {
        $svn = new Svn($url, new NullIO, new Config());
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialString');
        $reflMethod->setAccessible(true);

        $this->assertEquals($expect, $reflMethod->invoke($svn));
    }

    public function urlProvider(): array
    {
        return array(
            array('http://till:test@svn.example.org/', $this->getCmd(" --username 'till' --password 'test' ")),
            array('http://svn.apache.org/', ''),
            array('svn://johndoe@example.org', $this->getCmd(" --username 'johndoe' --password '' ")),
        );
    }

    public function testInteractiveString(): void
    {
        $url = 'http://svn.example.org';

        $svn = new Svn($url, new NullIO(), new Config());
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCommand');
        $reflMethod->setAccessible(true);

        $this->assertEquals(
            $this->getCmd("svn ls --non-interactive  -- 'http://svn.example.org'"),
            $reflMethod->invokeArgs($svn, array('svn ls', $url))
        );
    }

    public function testCredentialsFromConfig(): void
    {
        $url = 'http://svn.apache.org';

        $config = new Config();
        $config->merge(array(
            'config' => array(
                'http-basic' => array(
                    'svn.apache.org' => array('username' => 'foo', 'password' => 'bar'),
                ),
            ),
        ));

        $svn = new Svn($url, new NullIO, $config);
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialString');
        $reflMethod->setAccessible(true);

        $this->assertEquals($this->getCmd(" --username 'foo' --password 'bar' "), $reflMethod->invoke($svn));
    }

    public function testCredentialsFromConfigWithCacheCredentialsTrue(): void
    {
        $url = 'http://svn.apache.org';

        $config = new Config();
        $config->merge(
            array(
                'config' => array(
                    'http-basic' => array(
                        'svn.apache.org' => array('username' => 'foo', 'password' => 'bar'),
                    ),
                ),
            )
        );

        $svn = new Svn($url, new NullIO, $config);
        $svn->setCacheCredentials(true);
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialString');
        $reflMethod->setAccessible(true);

        $this->assertEquals($this->getCmd(" --username 'foo' --password 'bar' "), $reflMethod->invoke($svn));
    }

    public function testCredentialsFromConfigWithCacheCredentialsFalse(): void
    {
        $url = 'http://svn.apache.org';

        $config = new Config();
        $config->merge(
            array(
                'config' => array(
                    'http-basic' => array(
                        'svn.apache.org' => array('username' => 'foo', 'password' => 'bar'),
                    ),
                ),
            )
        );

        $svn = new Svn($url, new NullIO, $config);
        $svn->setCacheCredentials(false);
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialString');
        $reflMethod->setAccessible(true);

        $this->assertEquals($this->getCmd(" --no-auth-cache --username 'foo' --password 'bar' "), $reflMethod->invoke($svn));
    }
}
