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

namespace Composer\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Git
{
    /** @var string|false|null */
    private static $version = false;

    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var ProcessExecutor */
    protected $process;
    /** @var Filesystem */
    protected $filesystem;

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process, Filesystem $fs)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process;
        $this->filesystem = $fs;
    }

    /**
     * @param callable    $commandCallable
     * @param string      $url
     * @param string|null $cwd
     * @param bool        $initialClone
     *
     * @return void
     */
    public function runCommand($commandCallable, $url, $cwd, $initialClone = false)
    {
        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url, $this->io);

        if ($initialClone) {
            $origCwd = $cwd;
            $cwd = null;
        }

        if (Preg::isMatch('{^ssh://[^@]+@[^:]+:[^0-9]+}', $url)) {
            throw new \InvalidArgumentException('The source URL ' . $url . ' is invalid, ssh URLs should have a port number after ":".' . "\n" . 'Use ssh://git@example.com:22/path or just git@example.com:path if you do not want to provide a password or custom port.');
        }

        if (!$initialClone) {
            // capture username/password from URL if there is one and we have no auth configured yet
            $this->process->execute('git remote -v', $output, $cwd);
            if (Preg::isMatch('{^(?:composer|origin)\s+https?://(.+):(.+)@([^/]+)}im', $output, $match) && !$this->io->hasAuthentication($match[3])) {
                $this->io->setAuthentication($match[3], rawurldecode($match[1]), rawurldecode($match[2]));
            }
        }

        $protocols = $this->config->get('github-protocols');
        if (!is_array($protocols)) {
            throw new \RuntimeException('Config value "github-protocols" must be an array, got ' . gettype($protocols));
        }
        // public github, autoswitch protocols
        if (Preg::isMatch('{^(?:https?|git)://' . self::getGitHubDomainsRegex($this->config) . '/(.*)}', $url, $match)) {
            $messages = array();
            foreach ($protocols as $protocol) {
                if ('ssh' === $protocol) {
                    $protoUrl = "git@" . $match[1] . ":" . $match[2];
                } else {
                    $protoUrl = $protocol . "://" . $match[1] . "/" . $match[2];
                }

                if (0 === $this->process->execute(call_user_func($commandCallable, $protoUrl), $ignoredOutput, $cwd)) {
                    return;
                }
                $messages[] = '- ' . $protoUrl . "\n" . Preg::replace('#^#m', '  ', $this->process->getErrorOutput());

                if ($initialClone && isset($origCwd)) {
                    $this->filesystem->removeDirectory($origCwd);
                }
            }

            // failed to checkout, first check git accessibility
            if (!$this->io->hasAuthentication($match[1]) && !$this->io->isInteractive()) {
                $this->throwException('Failed to clone ' . $url . ' via ' . implode(', ', $protocols) . ' protocols, aborting.' . "\n\n" . implode("\n", $messages), $url);
            }
        }

        // if we have a private github url and the ssh protocol is disabled then we skip it and directly fallback to https
        $bypassSshForGitHub = Preg::isMatch('{^git@' . self::getGitHubDomainsRegex($this->config) . ':(.+?)\.git$}i', $url) && !in_array('ssh', $protocols, true);

        $command = call_user_func($commandCallable, $url);

        $auth = null;
        $credentials = array();
        if ($bypassSshForGitHub || 0 !== $this->process->execute($command, $ignoredOutput, $cwd)) {
            $errorMsg = $this->process->getErrorOutput();
            // private github repository without ssh key access, try https with auth
            if (Preg::isMatch('{^git@' . self::getGitHubDomainsRegex($this->config) . ':(.+?)\.git$}i', $url, $match)
                || Preg::isMatch('{^https?://' . self::getGitHubDomainsRegex($this->config) . '/(.*?)(?:\.git)?$}i', $url, $match)
            ) {
                if (!$this->io->hasAuthentication($match[1])) {
                    $gitHubUtil = new GitHub($this->io, $this->config, $this->process);
                    $message = 'Cloning failed using an ssh key for authentication, enter your GitHub credentials to access private repos';

                    if (!$gitHubUtil->authorizeOAuth($match[1]) && $this->io->isInteractive()) {
                        $gitHubUtil->authorizeOAuthInteractively($match[1], $message);
                    }
                }

                if ($this->io->hasAuthentication($match[1])) {
                    $auth = $this->io->getAuthentication($match[1]);
                    $authUrl = 'https://' . rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@' . $match[1] . '/' . $match[2] . '.git';
                    $command = call_user_func($commandCallable, $authUrl);
                    if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                        return;
                    }

                    $credentials = array(rawurlencode($auth['username']), rawurlencode($auth['password']));
                    $errorMsg = $this->process->getErrorOutput();
                }
            } elseif (Preg::isMatch('{^https://(bitbucket\.org)/(.*?)(?:\.git)?$}i', $url, $match)) { //bitbucket oauth
                $bitbucketUtil = new Bitbucket($this->io, $this->config, $this->process);

                if (!$this->io->hasAuthentication($match[1])) {
                    $message = 'Enter your Bitbucket credentials to access private repos';

                    if (!$bitbucketUtil->authorizeOAuth($match[1]) && $this->io->isInteractive()) {
                        $bitbucketUtil->authorizeOAuthInteractively($match[1], $message);
                        $accessToken = $bitbucketUtil->getToken();
                        $this->io->setAuthentication($match[1], 'x-token-auth', $accessToken);
                    }
                } else { //We're authenticating with a locally stored consumer.
                    $auth = $this->io->getAuthentication($match[1]);

                    //We already have an access_token from a previous request.
                    if ($auth['username'] !== 'x-token-auth') {
                        $accessToken = $bitbucketUtil->requestToken($match[1], $auth['username'], $auth['password']);
                        if (!empty($accessToken)) {
                            $this->io->setAuthentication($match[1], 'x-token-auth', $accessToken);
                        }
                    }
                }

                if ($this->io->hasAuthentication($match[1])) {
                    $auth = $this->io->getAuthentication($match[1]);
                    $authUrl = 'https://' . rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@' . $match[1] . '/' . $match[2] . '.git';

                    $command = call_user_func($commandCallable, $authUrl);
                    if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                        return;
                    }

                    $credentials = array(rawurlencode($auth['username']), rawurlencode($auth['password']));
                    $errorMsg = $this->process->getErrorOutput();
                } else { // Falling back to ssh
                    $sshUrl = 'git@bitbucket.org:' . $match[2] . '.git';
                    $this->io->writeError('    No bitbucket authentication configured. Falling back to ssh.');
                    $command = call_user_func($commandCallable, $sshUrl);
                    if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                        return;
                    }

                    $errorMsg = $this->process->getErrorOutput();
                }
            } elseif (
                Preg::isMatch('{^(git)@' . self::getGitLabDomainsRegex($this->config) . ':(.+?\.git)$}i', $url, $match)
                || Preg::isMatch('{^(https?)://' . self::getGitLabDomainsRegex($this->config) . '/(.*)}i', $url, $match)
            ) {
                if ($match[1] === 'git') {
                    $match[1] = 'https';
                }

                if (!$this->io->hasAuthentication($match[2])) {
                    $gitLabUtil = new GitLab($this->io, $this->config, $this->process);
                    $message = 'Cloning failed, enter your GitLab credentials to access private repos';

                    if (!$gitLabUtil->authorizeOAuth($match[2]) && $this->io->isInteractive()) {
                        $gitLabUtil->authorizeOAuthInteractively($match[1], $match[2], $message);
                    }
                }

                if ($this->io->hasAuthentication($match[2])) {
                    $auth = $this->io->getAuthentication($match[2]);
                    if ($auth['password'] === 'private-token' || $auth['password'] === 'oauth2' || $auth['password'] === 'gitlab-ci-token') {
                        $authUrl = $match[1] . '://' . rawurlencode($auth['password']) . ':' . rawurlencode($auth['username']) . '@' . $match[2] . '/' . $match[3]; // swap username and password
                    } else {
                        $authUrl = $match[1] . '://' . rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@' . $match[2] . '/' . $match[3];
                    }

                    $command = call_user_func($commandCallable, $authUrl);
                    if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                        return;
                    }

                    $credentials = array(rawurlencode($auth['username']), rawurlencode($auth['password']));
                    $errorMsg = $this->process->getErrorOutput();
                }
            } elseif ($this->isAuthenticationFailure($url, $match)) { // private non-github/gitlab/bitbucket repo that failed to authenticate
                if (strpos($match[2], '@')) {
                    list($authParts, $match[2]) = explode('@', $match[2], 2);
                }

                $storeAuth = false;
                if ($this->io->hasAuthentication($match[2])) {
                    $auth = $this->io->getAuthentication($match[2]);
                } elseif ($this->io->isInteractive()) {
                    $defaultUsername = null;
                    if (isset($authParts) && $authParts) {
                        if (false !== strpos($authParts, ':')) {
                            list($defaultUsername, ) = explode(':', $authParts, 2);
                        } else {
                            $defaultUsername = $authParts;
                        }
                    }

                    $this->io->writeError('    Authentication required (<info>' . $match[2] . '</info>):');
                    $auth = array(
                        'username' => $this->io->ask('      Username: ', $defaultUsername),
                        'password' => $this->io->askAndHideAnswer('      Password: '),
                    );
                    $storeAuth = $this->config->get('store-auths');
                }

                if (null !== $auth) {
                    $authUrl = $match[1] . rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@' . $match[2] . $match[3];

                    $command = call_user_func($commandCallable, $authUrl);
                    if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                        $this->io->setAuthentication($match[2], $auth['username'], $auth['password']);
                        $authHelper = new AuthHelper($this->io, $this->config);
                        $authHelper->storeAuth($match[2], $storeAuth);

                        return;
                    }

                    $credentials = array(rawurlencode($auth['username']), rawurlencode($auth['password']));
                    $errorMsg = $this->process->getErrorOutput();
                }
            }

            if ($initialClone && isset($origCwd)) {
                $this->filesystem->removeDirectory($origCwd);
            }

            if (count($credentials) > 0) {
                $command = $this->maskCredentials($command, $credentials);
                $errorMsg = $this->maskCredentials($errorMsg, $credentials);
            }
            $this->throwException('Failed to execute ' . $command . "\n\n" . $errorMsg, $url);
        }
    }

    /**
     * @param string $url
     * @param string $dir
     *
     * @return bool
     */
    public function syncMirror($url, $dir)
    {
        if (Platform::getEnv('COMPOSER_DISABLE_NETWORK') && Platform::getEnv('COMPOSER_DISABLE_NETWORK') !== 'prime') {
            $this->io->writeError('<warning>Aborting git mirror sync of '.$url.' as network is disabled</warning>');

            return false;
        }

        // update the repo if it is a valid git repository
        if (is_dir($dir) && 0 === $this->process->execute('git rev-parse --git-dir', $output, $dir) && trim($output) === '.') {
            try {
                $commandCallable = function ($url): string {
                    $sanitizedUrl = Preg::replace('{://([^@]+?):(.+?)@}', '://', $url);

                    return sprintf('git remote set-url origin -- %s && git remote update --prune origin && git remote set-url origin -- %s && git gc --auto', ProcessExecutor::escape($url), ProcessExecutor::escape($sanitizedUrl));
                };
                $this->runCommand($commandCallable, $url, $dir);
            } catch (\Exception $e) {
                $this->io->writeError('<error>Sync mirror failed: ' . $e->getMessage() . '</error>', true, IOInterface::DEBUG);

                return false;
            }

            return true;
        }

        // clean up directory and do a fresh clone into it
        $this->filesystem->removeDirectory($dir);

        $commandCallable = function ($url) use ($dir): string {
            return sprintf('git clone --mirror -- %s %s', ProcessExecutor::escape($url), ProcessExecutor::escape($dir));
        };

        $this->runCommand($commandCallable, $url, $dir, true);

        return true;
    }

    /**
     * @param string $url
     * @param string $dir
     * @param string $ref
     *
     * @return bool
     */
    public function fetchRefOrSyncMirror($url, $dir, $ref)
    {
        if ($this->checkRefIsInMirror($dir, $ref)) {
            return true;
        }

        if ($this->syncMirror($url, $dir)) {
            return $this->checkRefIsInMirror($dir, $ref);
        }

        return false;
    }

    /**
     * @return string
     */
    public static function getNoShowSignatureFlag(ProcessExecutor $process)
    {
        $gitVersion = self::getVersion($process);
        if ($gitVersion && version_compare($gitVersion, '2.10.0-rc0', '>=')) {
            return ' --no-show-signature';
        }

        return '';
    }

    /**
     * @param string $dir
     * @param string $ref
     *
     * @return bool
     */
    private function checkRefIsInMirror($dir, $ref): bool
    {
        if (is_dir($dir) && 0 === $this->process->execute('git rev-parse --git-dir', $output, $dir) && trim($output) === '.') {
            $escapedRef = ProcessExecutor::escape($ref.'^{commit}');
            $exitCode = $this->process->execute(sprintf('git rev-parse --quiet --verify %s', $escapedRef), $ignoredOutput, $dir);
            if ($exitCode === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string   $url
     * @param string[] $match
     *
     * @return bool
     */
    private function isAuthenticationFailure($url, &$match): bool
    {
        if (!Preg::isMatch('{^(https?://)([^/]+)(.*)$}i', $url, $match)) {
            return false;
        }

        $authFailures = array(
            'fatal: Authentication failed',
            'remote error: Invalid username or password.',
            'error: 401 Unauthorized',
            'fatal: unable to access',
            'fatal: could not read Username',
        );

        $errorOutput = $this->process->getErrorOutput();
        foreach ($authFailures as $authFailure) {
            if (strpos($errorOutput, $authFailure) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return void
     */
    public static function cleanEnv()
    {
        // added in git 1.7.1, prevents prompting the user for username/password
        if (Platform::getEnv('GIT_ASKPASS') !== 'echo') {
            Platform::putEnv('GIT_ASKPASS', 'echo');
        }

        // clean up rogue git env vars in case this is running in a git hook
        if (Platform::getEnv('GIT_DIR')) {
            Platform::clearEnv('GIT_DIR');
        }
        if (Platform::getEnv('GIT_WORK_TREE')) {
            Platform::clearEnv('GIT_WORK_TREE');
        }

        // Run processes with predictable LANGUAGE
        if (Platform::getEnv('LANGUAGE') !== 'C') {
            Platform::putEnv('LANGUAGE', 'C');
        }

        // clean up env for OSX, see https://github.com/composer/composer/issues/2146#issuecomment-35478940
        Platform::clearEnv('DYLD_LIBRARY_PATH');
    }

    /**
     * @return non-empty-string
     */
    public static function getGitHubDomainsRegex(Config $config)
    {
        return '(' . implode('|', array_map('preg_quote', $config->get('github-domains'))) . ')';
    }

    /**
     * @return non-empty-string
     */
    public static function getGitLabDomainsRegex(Config $config)
    {
        return '(' . implode('|', array_map('preg_quote', $config->get('gitlab-domains'))) . ')';
    }

    /**
     * @param non-empty-string $message
     * @param string           $url
     *
     * @return never
     */
    private function throwException($message, $url): void
    {
        // git might delete a directory when it fails and php will not know
        clearstatcache();

        if (0 !== $this->process->execute('git --version', $ignoredOutput)) {
            throw new \RuntimeException(Url::sanitize('Failed to clone ' . $url . ', git was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput()));
        }

        throw new \RuntimeException(Url::sanitize($message));
    }

    /**
     * Retrieves the current git version.
     *
     * @return string|null The git version number, if present.
     */
    public static function getVersion(ProcessExecutor $process)
    {
        if (false === self::$version) {
            self::$version = null;
            if (0 === $process->execute('git --version', $output) && Preg::isMatch('/^git version (\d+(?:\.\d+)+)/m', $output, $matches)) {
                self::$version = $matches[1];
            }
        }

        return self::$version;
    }

    /**
     * @param string   $error
     * @param string[] $credentials
     *
     * @return string
     */
    private function maskCredentials($error, array $credentials): string
    {
        $maskedCredentials = array();

        foreach ($credentials as $credential) {
            if (in_array($credential, array('private-token', 'x-token-auth', 'oauth2', 'gitlab-ci-token', 'x-oauth-basic'))) {
                $maskedCredentials[] = $credential;
            } elseif (strlen($credential) > 6) {
                $maskedCredentials[] = substr($credential, 0, 3) . '...' . substr($credential, -3);
            } elseif (strlen($credential) > 3) {
                $maskedCredentials[] = substr($credential, 0, 3) . '...';
            } else {
                $maskedCredentials[] = 'XXX';
            }
        }

        return str_replace($credentials, $maskedCredentials, $error);
    }
}
