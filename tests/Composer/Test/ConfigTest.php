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

namespace Composer\Test;

use Composer\Config;
use Composer\Util\Platform;

class ConfigTest extends TestCase
{
    /**
     * @dataProvider dataAddPackagistRepository
     * @param mixed[] $expected
     * @param mixed[] $localConfig
     * @param ?mixed[] $systemConfig
     */
    public function testAddPackagistRepository($expected, $localConfig, $systemConfig = null): void
    {
        $config = new Config(false);
        if ($systemConfig) {
            $config->merge(array('repositories' => $systemConfig));
        }
        $config->merge(array('repositories' => $localConfig));

        $this->assertEquals($expected, $config->getRepositories());
    }

    public function dataAddPackagistRepository(): array
    {
        $data = array();
        $data['local config inherits system defaults'] = array(
            array(
                'packagist.org' => array('type' => 'composer', 'url' => 'https://repo.packagist.org'),
            ),
            array(),
        );

        $data['local config can disable system config by name'] = array(
            array(),
            array(
                array('packagist.org' => false),
            ),
        );

        $data['local config can disable system config by name bc'] = array(
            array(),
            array(
                array('packagist' => false),
            ),
        );

        $data['local config adds above defaults'] = array(
            array(
                1 => array('type' => 'vcs', 'url' => 'git://github.com/composer/composer.git'),
                0 => array('type' => 'pear', 'url' => 'http://pear.composer.org'),
                'packagist.org' => array('type' => 'composer', 'url' => 'https://repo.packagist.org'),
            ),
            array(
                array('type' => 'vcs', 'url' => 'git://github.com/composer/composer.git'),
                array('type' => 'pear', 'url' => 'http://pear.composer.org'),
            ),
        );

        $data['system config adds above core defaults'] = array(
            array(
                'example.com' => array('type' => 'composer', 'url' => 'http://example.com'),
                'packagist.org' => array('type' => 'composer', 'url' => 'https://repo.packagist.org'),
            ),
            array(),
            array(
                'example.com' => array('type' => 'composer', 'url' => 'http://example.com'),
            ),
        );

        $data['local config can disable repos by name and re-add them anonymously to bring them above system config'] = array(
            array(
                0 => array('type' => 'composer', 'url' => 'http://packagist.org'),
                'example.com' => array('type' => 'composer', 'url' => 'http://example.com'),
            ),
            array(
                array('packagist.org' => false),
                array('type' => 'composer', 'url' => 'http://packagist.org'),
            ),
            array(
                'example.com' => array('type' => 'composer', 'url' => 'http://example.com'),
            ),
        );

        $data['local config can override by name to bring a repo above system config'] = array(
            array(
                'packagist.org' => array('type' => 'composer', 'url' => 'http://packagistnew.org'),
                'example.com' => array('type' => 'composer', 'url' => 'http://example.com'),
            ),
            array(
                'packagist.org' => array('type' => 'composer', 'url' => 'http://packagistnew.org'),
            ),
            array(
                'example.com' => array('type' => 'composer', 'url' => 'http://example.com'),
            ),
        );

        $data['local config redefining packagist.org by URL override it if no named keys are used'] = array(
            array(
                array('type' => 'composer', 'url' => 'https://repo.packagist.org'),
            ),
            array(
                array('type' => 'composer', 'url' => 'https://repo.packagist.org'),
            ),
        );

        $data['local config redefining packagist.org by URL override it also with named keys'] = array(
            array(
                'example' => array('type' => 'composer', 'url' => 'https://repo.packagist.org'),
            ),
            array(
                'example' => array('type' => 'composer', 'url' => 'https://repo.packagist.org'),
            ),
        );

        $data['incorrect local config does not cause ErrorException'] = array(
            array(
                'packagist.org' => array('type' => 'composer', 'url' => 'https://repo.packagist.org'),
                'type' => 'vcs',
                'url' => 'http://example.com',
            ),
            array(
                'type' => 'vcs',
                'url' => 'http://example.com',
            ),
        );

        return $data;
    }

    public function testPreferredInstallAsString(): void
    {
        $config = new Config(false);
        $config->merge(array('config' => array('preferred-install' => 'source')));
        $config->merge(array('config' => array('preferred-install' => 'dist')));

        $this->assertEquals('dist', $config->get('preferred-install'));
    }

    public function testMergePreferredInstall(): void
    {
        $config = new Config(false);
        $config->merge(array('config' => array('preferred-install' => 'dist')));
        $config->merge(array('config' => array('preferred-install' => array('foo/*' => 'source'))));

        // This assertion needs to make sure full wildcard preferences are placed last
        // Handled by composer because we convert string preferences for BC, all other
        // care for ordering and collision prevention is up to the user
        $this->assertEquals(array('foo/*' => 'source', '*' => 'dist'), $config->get('preferred-install'));
    }

    public function testMergeGithubOauth(): void
    {
        $config = new Config(false);
        $config->merge(array('config' => array('github-oauth' => array('foo' => 'bar'))));
        $config->merge(array('config' => array('github-oauth' => array('bar' => 'baz'))));

        $this->assertEquals(array('foo' => 'bar', 'bar' => 'baz'), $config->get('github-oauth'));
    }

    public function testVarReplacement(): void
    {
        $config = new Config(false);
        $config->merge(array('config' => array('a' => 'b', 'c' => '{$a}')));
        $config->merge(array('config' => array('bin-dir' => '$HOME', 'cache-dir' => '~/foo/')));

        $home = rtrim(getenv('HOME') ?: getenv('USERPROFILE'), '\\/');
        $this->assertEquals('b', $config->get('c'));
        $this->assertEquals($home, $config->get('bin-dir'));
        $this->assertEquals($home.'/foo', $config->get('cache-dir'));
    }

    public function testRealpathReplacement(): void
    {
        $config = new Config(false, '/foo/bar');
        $config->merge(array('config' => array(
            'bin-dir' => '$HOME/foo',
            'cache-dir' => '/baz/',
            'vendor-dir' => 'vendor',
        )));

        $home = rtrim(getenv('HOME') ?: getenv('USERPROFILE'), '\\/');
        $this->assertEquals('/foo/bar/vendor', $config->get('vendor-dir'));
        $this->assertEquals($home.'/foo', $config->get('bin-dir'));
        $this->assertEquals('/baz', $config->get('cache-dir'));
    }

    public function testStreamWrapperDirs(): void
    {
        $config = new Config(false, '/foo/bar');
        $config->merge(array('config' => array(
            'cache-dir' => 's3://baz/',
        )));

        $this->assertEquals('s3://baz', $config->get('cache-dir'));
    }

    public function testFetchingRelativePaths(): void
    {
        $config = new Config(false, '/foo/bar');
        $config->merge(array('config' => array(
            'bin-dir' => '{$vendor-dir}/foo',
            'vendor-dir' => 'vendor',
        )));

        $this->assertEquals('/foo/bar/vendor', $config->get('vendor-dir'));
        $this->assertEquals('/foo/bar/vendor/foo', $config->get('bin-dir'));
        $this->assertEquals('vendor', $config->get('vendor-dir', Config::RELATIVE_PATHS));
        $this->assertEquals('vendor/foo', $config->get('bin-dir', Config::RELATIVE_PATHS));
    }

    public function testOverrideGithubProtocols(): void
    {
        $config = new Config(false);
        $config->merge(array('config' => array('github-protocols' => array('https', 'ssh'))));
        $config->merge(array('config' => array('github-protocols' => array('https'))));

        $this->assertEquals(array('https'), $config->get('github-protocols'));
    }

    public function testGitDisabledByDefaultInGithubProtocols(): void
    {
        $config = new Config(false);
        $config->merge(array('config' => array('github-protocols' => array('https', 'git'))));
        $this->assertEquals(array('https'), $config->get('github-protocols'));

        $config->merge(array('config' => array('secure-http' => false)));
        $this->assertEquals(array('https', 'git'), $config->get('github-protocols'));
    }

    /**
     * @dataProvider allowedUrlProvider
     * @doesNotPerformAssertions
     *
     * @param string $url
     */
    public function testAllowedUrlsPass($url): void
    {
        $config = new Config(false);
        $config->prohibitUrlByConfig($url);
    }

    /**
     * @dataProvider prohibitedUrlProvider
     *
     * @param string $url
     */
    public function testProhibitedUrlsThrowException($url): void
    {
        self::expectException('Composer\Downloader\TransportException');
        self::expectExceptionMessage('Your configuration does not allow connections to ' . $url);
        $config = new Config(false);
        $config->prohibitUrlByConfig($url);
    }

    /**
     * @return string[][] List of test URLs that should pass strict security
     */
    public function allowedUrlProvider(): array
    {
        $urls = array(
            'https://packagist.org',
            'git@github.com:composer/composer.git',
            'hg://user:pass@my.satis/satis',
            '\\myserver\myplace.git',
            'file://myserver.localhost/mygit.git',
            'file://example.org/mygit.git',
            'git:Department/Repo.git',
            'ssh://[user@]host.xz[:port]/path/to/repo.git/',
        );

        return array_combine($urls, array_map(function ($e): array {
            return array($e);
        }, $urls));
    }

    /**
     * @return string[][] List of test URLs that should not pass strict security
     */
    public function prohibitedUrlProvider(): array
    {
        $urls = array(
            'http://packagist.org',
            'http://10.1.0.1/satis',
            'http://127.0.0.1/satis',
            'svn://localhost/trunk',
            'svn://will.not.resolve/trunk',
            'svn://192.168.0.1/trunk',
            'svn://1.2.3.4/trunk',
            'git://5.6.7.8/git.git',
        );

        return array_combine($urls, array_map(function ($e): array {
            return array($e);
        }, $urls));
    }

    /**
     * @group TLS
     */
    public function testDisableTlsCanBeOverridden(): void
    {
        $config = new Config;
        $config->merge(
            array('config' => array('disable-tls' => 'false'))
        );
        $this->assertFalse($config->get('disable-tls'));
        $config->merge(
            array('config' => array('disable-tls' => 'true'))
        );
        $this->assertTrue($config->get('disable-tls'));
    }

    public function testProcessTimeout(): void
    {
        Platform::putEnv('COMPOSER_PROCESS_TIMEOUT', '0');
        $config = new Config(true);
        $result = $config->get('process-timeout');
        Platform::clearEnv('COMPOSER_PROCESS_TIMEOUT');

        $this->assertEquals(0, $result);
    }

    public function testHtaccessProtect(): void
    {
        Platform::putEnv('COMPOSER_HTACCESS_PROTECT', '0');
        $config = new Config(true);
        $result = $config->get('htaccess-protect');
        Platform::clearEnv('COMPOSER_HTACCESS_PROTECT');

        $this->assertEquals(0, $result);
    }

    public function testGetSourceOfValue(): void
    {
        Platform::clearEnv('COMPOSER_PROCESS_TIMEOUT');

        $config = new Config;

        $this->assertSame(Config::SOURCE_DEFAULT, $config->getSourceOfValue('process-timeout'));

        $config->merge(
            array('config' => array('process-timeout' => 1)),
            'phpunit-test'
        );

        $this->assertSame('phpunit-test', $config->getSourceOfValue('process-timeout'));
    }

    public function testGetSourceOfValueEnvVariables(): void
    {
        Platform::putEnv('COMPOSER_HTACCESS_PROTECT', '0');
        $config = new Config;
        $result = $config->getSourceOfValue('htaccess-protect');
        Platform::clearEnv('COMPOSER_HTACCESS_PROTECT');

        $this->assertEquals('COMPOSER_HTACCESS_PROTECT', $result);
    }
}
