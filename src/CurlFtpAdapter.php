<?php

namespace VladimirYuldashev\Flysystem;

use DateTime;
use Generator;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Ftp\InvalidListResponseReceived;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use \Normalizer;
use RuntimeException;

class CurlFtpAdapter implements FilesystemAdapter
{
    const SYSTEM_TYPE_WINDOWS = 'windows';
    const SYSTEM_TYPE_UNIX = 'unix';

    protected $configurable = [
        'host',
        'port',
        'username',
        'password',
        'root',
        'ftps',
        'ssl',
        'sslVerifyPeer',
        'sslVerifyHost',
        'utf8',
        'timeout',
        'passive',
        'skipPasvIp',
        'proxyHost',
        'proxyPort',
        'proxyUsername',
        'proxyPassword',
        'verbose',
        'enableTimestampsOnUnixListings',
    ];

    public function __construct(
        array $options,
        VisibilityConverter $visibilityConverter = null,
        MimeTypeDetector $mimeTypeDetector = null
    )
    {
        $this->visibilityConverter = $visibilityConverter ?? new PortableVisibilityConverter();
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
        $this->setConfig($options);
    }

    private $visibilityConverter;

    private $mimeTypeDetector;

    /** @var Curl */
    protected $connection;

    /** @var int unix timestamp when connection was established */
    protected $connectionTimestamp = 0;

    /** @var string */
    private $host;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var int */
    private $port = 21;

    /** @var int */
    private $timeout = 90;

    /** @var string */
    private $root = '';

    /** @var bool */
    protected $isPureFtpd = false;

    /** @var bool */
    protected $ftps = true;

    /** @var @int */
    protected $sslVerifyPeer = 1;

    /** @var @int */
    protected $sslVerifyHost = 2;

    /** @var bool */
    protected $utf8 = false;

    /** @var bool */
    protected $skipPasvIp = true;

    /** @var string */
    protected $proxyHost;

    /** @var int */
    protected $proxyPort;

    /** @var string */
    protected $proxyUsername;

    /** @var string */
    protected $proxyPassword;

    /** @var bool */
    protected $verbose = false;

    /**
     * @var bool
     */
    private $passive;

    /**
     * @var bool
     */
    private $ssl;

    /** @var bool */
    private $enableTimestampsOnUnixListings = false;

    /**
     * @var null|string
     */
    private $systemType;

    /**
     * @var string
     */
    private $rootDirectory = null;

    /** @var PathPrefixer */
    private $prefixer;

    public function setConfig(array $config): self
    {
        foreach ($this->configurable as $setting) {
            if ( ! isset($config[$setting])) {
                continue;
            }

            $method = 'set' . ucfirst($setting);

            if (method_exists($this, $method)) {
                $this->$method($config[$setting]);
            } else {
                throw new \Exception('missing setter: ' . $setting);
            }
        }

        return $this;
    }

    /**
     * @param string $host
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * @param int $port
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @param string $username
     */
    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    /**
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @param string $password
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * @param string $root
     */
    public function setRoot(string $root): void
    {
        $this->root = $root;
    }


    /**
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * @param bool $ftps
     */
    public function setFtps(bool $ftps): void
    {
        $this->ftps = $ftps;
    }

    /**
     * @param bool $ssl
     */
    public function setSsl(bool $ssl): void
    {
        $this->ssl = $ssl;
    }

    /**
     * @param int $sslVerifyPeer
     */
    public function setSslVerifyPeer(int $sslVerifyPeer): void
    {
        $this->sslVerifyPeer = $sslVerifyPeer;
    }

    /**
     * @param int $sslVerifyHost
     */
    public function setSslVerifyHost(int $sslVerifyHost): void
    {
        $this->sslVerifyHost = $sslVerifyHost;
    }

    /**
     * @param bool $utf8
     */
    public function setUtf8(bool $utf8): void
    {
        $this->utf8 = $utf8;
    }

    /**
     * @param bool $passive
     */
    public function setPassive(bool $passive): void
    {
        $this->passive = $passive;
    }

    /**
     * @param bool $skipPasvIp
     */
    public function setSkipPasvIp(bool $skipPasvIp): void
    {
        $this->skipPasvIp = $skipPasvIp;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return string
     */
    public function getRoot(): string
    {
        return $this->root;
    }

    /**
     * @return string
     */
    public function getProxyHost(): ?string
    {
        return $this->proxyHost;
    }

    /**
     * @param string $proxyHost
     */
    public function setProxyHost(string $proxyHost): void
    {
        $this->proxyHost = $proxyHost;
    }

    /**
     * @return int
     */
    public function getProxyPort(): ?int
    {
        return $this->proxyPort;
    }

    /**
     * @param int $proxyPort
     */
    public function setProxyPort(int $proxyPort): void
    {
        $this->proxyPort = $proxyPort;
    }

    /**
     * @return string
     */
    public function getProxyUsername(): ?string
    {
        return $this->proxyUsername;
    }

    /**
     * @param string $proxyUsername
     */
    public function setProxyUsername(string $proxyUsername): void
    {
        $this->proxyUsername = $proxyUsername;
    }

    /**
     * @return string
     */
    public function getProxyPassword(): string
    {
        return $this->proxyPassword;
    }

    /**
     * @param string $proxyPassword
     */
    public function setProxyPassword(string $proxyPassword): void
    {
        $this->proxyPassword = $proxyPassword;
    }

    /**
     * @param bool $verbose
     */
    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function setTimestampsOnUnixListingsEnabled(bool $timestampsOnUnixListingsEnabled)
    {
        $this->enableTimestampsOnUnixListings = $timestampsOnUnixListingsEnabled;
    }

    /**
     * @return bool
     */
    public function timestampsOnUnixListingsEnabled(): bool
    {
        return $this->enableTimestampsOnUnixListings ?? false;
    }

    /**
     * @return Curl
     */
    private function getConnection(): Curl
    {
        if ( ! $this->isConnected()) {
            $this->disconnect();
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * Establish a connection.
     */
    public function connect(): void
    {
        $this->connection = new Curl();
        $this->connection->setOptions([
            CURLOPT_URL => $this->getBaseUri(),
            CURLOPT_USERPWD => $this->getUsername().':'.$this->getPassword(),
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->getTimeout(),
        ]);

        if ($this->ssl) {
            $this->connection->setOption(CURLOPT_USE_SSL, CURLFTPSSL_ALL);
        }

        if (! $this->passive) {
            $this->connection->setOption(CURLOPT_FTPPORT, '-');
        }

        if ($this->skipPasvIp) {
            $this->connection->setOption(CURLOPT_FTP_SKIP_PASV_IP, $this->skipPasvIp);
        }

        $this->connection->setOption(CURLOPT_SSL_VERIFYHOST, $this->sslVerifyHost);
        $this->connection->setOption(CURLOPT_SSL_VERIFYPEER, $this->sslVerifyPeer);

        if ($proxyUrl = $this->getProxyHost()) {
            $proxyPort = $this->getProxyPort();
            $this->connection->setOption(CURLOPT_PROXY, $proxyPort ? $proxyUrl.':'.$proxyPort : $proxyUrl);
            $this->connection->setOption(CURLOPT_HTTPPROXYTUNNEL, true);
        }

        if ($username = $this->getProxyUsername()) {
            $this->connection->setOption(CURLOPT_PROXYUSERPWD, $username.':'.$this->getProxyPassword());
        }

        if ($this->verbose) {
            $this->connection->setOption(CURLOPT_VERBOSE, $this->verbose);
        }

        $this->pingConnection();
        $this->connectionTimestamp = time();
        $this->rootDirectory = $this->resolveConnectionRoot($this->connection);
        $this->prefixer = new PathPrefixer($this->rootDirectory);
        $this->setUtf8Mode();
        $this->rootDirectory = $this->resolveConnectionRoot($this->connection);
        $this->prefixer = new PathPrefixer($this->rootDirectory);
    }

    /**
     * Close the connection.
     */
    public function disconnect(): void
    {
        if ($this->connection !== null) {
            $this->connection = null;
        }
        $this->isPureFtpd = null;
    }

    /**
     * Check if a connection is active.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connection !== null && ! $this->hasConnectionReachedTimeout();
    }

    /**
     * @return bool
     */
    protected function hasConnectionReachedTimeout(): bool
    {
        return $this->connectionTimestamp + $this->getTimeout() < time();
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @throws UnableToWriteFile
     * @throws FilesystemException
     */
    public function write(string $path, string $contents, Config $config) : void
    {
        try {
            $writeStream = fopen('php://temp', 'w+b');
            fwrite($writeStream, $contents);
            rewind($writeStream);

            $this->writeStream($path, $writeStream, $config);
        } finally {
            isset($writeStream) && is_resource($writeStream) && fclose($writeStream);
        }
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $contents
     * @param Config   $config Config object

     * @throws UnableToWriteFile
     * @throws FilesystemException
     **/
    public function writeStream(string $path, $contents, Config $config) : void
    {
        $connection = $this->getConnection();
        $location = $this->prefixer()->prefixPath($path);

        try {
            $this->ensureParentDirectoryExists($location, $config->get(Config::OPTION_DIRECTORY_VISIBILITY));
        } catch (UnableToCreateDirectory $exception) {
            throw UnableToWriteFile::atLocation($location, 'creating parent directory failed', $exception);
        }


        $result = $connection->exec([
            CURLOPT_URL => $this->getBaseUri().'/'. ltrim(rawurlencode($location), '/'),
            CURLOPT_UPLOAD => 1,
            CURLOPT_INFILE => $contents,
        ]);

        if ($result === false) {
            throw UnableToWriteFile::atLocation($path, $this->getConnection()->getLastError());
        }
    }

    /**
     * Rename a file.
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @return void
     */
    public function move(string $source, string $destination, Config $config) : void
    {
        $connection = $this->getConnection();

        $moveCommands = [
            'RNFR '.$source,
            'RNTO '.$destination,
        ];

        $response = $this->rawPost($connection, $moveCommands);
        list($code) = explode(' ', end($response), 2);

        if ((int) $code === 250) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * Copy a file.
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @throws FilesystemException
     */
    public function copy(string $source, string $destination, Config $config) : void
    {
        try {
            $file = $this->read($source);
            $this->write($destination, $file, $config);
        } catch (UnableToReadFile | UnableToWriteFile $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return void
     */
    public function delete(string $path) : void
    {
        $connection = $this->getConnection();

        $response = $this->rawCommand($connection, 'DELE '.$path);
        [$code] = explode(' ', end($response), 2);

        if ((int) $code !== 250) {
            throw UnableToDeleteFile::atLocation($path, "Server responded with code {$code}");
        }
    }

    /**
     * Delete a directory.
     *
     * @param string $path
     *
     * @return void
     */
    public function deleteDirectory(string $path) : void
    {
        $connection = $this->getConnection();

        $response = $this->rawCommand($connection, 'RMD '.$path);
        [$code] = explode(' ', end($response), 2);

        if ((int) $code !== 250) {
            throw UnableToDeleteFile::atLocation($path, "Server responded with code {$code}");
        }
    }

    /**
     * Create a directory.
     *
     * @param string $path directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDirectory(string $path, Config $config) : void
    {
        $connection = $this->getConnection();

        $response = $this->rawCommand($connection, 'MKD '.$path);
        [$code] = explode(' ', end($response), 2);
        if ((int) $code !== 257) {
            throw UnableToCreateDirectory::atLocation($path, "Server responded with code {$code}");
        }
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     */
    public function setVisibility(string $path, string $visibility) : void
    {
        $connection = $this->getConnection();
        $mode = $this->visibilityConverter->forFile($visibility);
        $location = $this->prefixer()->prefixPath($path);

        $request = sprintf('SITE CHMOD %o %s', $mode, $this->escapePath($location));
        $response = $this->rawCommand($connection, $request);
        [$code] = explode(' ', end($response), 2);

        if ((int) $code !== 200) {
            throw UnableToSetVisibility::atLocation($path);
        }
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return string
     */
    public function read(string $path) : string
    {
        $stream = $this->readStream($path);

        $content = stream_get_contents($stream);
        fclose($stream);
        unset($stream);

        return $content;
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return resource
     */
    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new UnableToReadFile("Failed to open PHP temp handle");
        }

        $connection = $this->getConnection();
        $location = $this->prefixer()->prefixPath($path);

        $result = $connection->exec([
            CURLOPT_URL => $this->getBaseUri() . '/' . ltrim(rawurlencode($location), '/'),
            CURLOPT_FILE => $stream,
        ]);

        if ($result === false) {
            fclose($stream);
            throw UnableToReadFile::fromLocation($location, $this->getConnection()->getLastError());
        }

        rewind($stream);

        return $stream;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata(string $path)
    {
        if ($path === '') {
            return ['type' => 'dir', 'path' => ''];
        }

        $request = rtrim('LIST -A '.$this->escapePath($path));

        $connection = $this->getConnection();
        $result = $connection->exec([CURLOPT_CUSTOMREQUEST => $request]);
        if ($result === false) {
            return false;
        }
        $listing = $this->normalizeListing(explode(PHP_EOL, $result), '');

        return current($listing);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function mimetype(string $path) : FileAttributes
    {
        $mimeType = $this->mimeTypeDetector->detectMimeType($path, '');
        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unknown extension');
        }

        return new FileAttributes($path, null, null, null, $mimeType);
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function lastModified(string $path) : FileAttributes
    {
        $location = $this->prefixer()->prefixPath($path);
        $response = $this->rawCommand($this->getConnection(), 'MDTM '.$this->escapePath($location));
        [$code, $time] = explode(' ', end($response), 2);
        if ($code !== '213' || $time < 0) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        if (strpos($time, '.')) {
            $datetime = DateTime::createFromFormat('YmdHis.u', $time);
        } else {
            $datetime = DateTime::createFromFormat('YmdHis', $time);
        }

        if (! $datetime) {
            throw UnableToRetrieveMetadata::lastModified($path, "Unable to parse server response for MDTM command: $time");
        }

        return new FileAttributes($path, null, null, $datetime->getTimestamp());
    }

    /**
     * {@inheritdoc}
     *
     * @param string $path
     */
    public function listContents(string $path, $deep = false) : iterable
    {
        if ($deep === true) {
            yield from $this->listDirectoryContentsRecursive($path);
        } else {
            $request = rtrim('LIST -aln '.$this->escapePath($path));

            $connection = $this->getConnection();
            $result = $connection->exec([CURLOPT_CUSTOMREQUEST => $request]);
            if ($result === false) {
                return [];
            }

            if ($path === '/') {
                $path = '';
            }

            yield from $this->normalizeListing(explode(PHP_EOL, $result), $path);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param string $directory
     */
    protected function listDirectoryContentsRecursive(string $directory): Generator
    {
        $request = rtrim('LIST -aln '.$this->escapePath($directory));

        $connection = $this->getConnection();
        $listing = $connection->exec([CURLOPT_CUSTOMREQUEST => $request]);

        /** @var StorageAttributes[] $listing */
        $listing = $this->normalizeListing($listing, $directory);

        foreach ($listing as $item) {
            yield $item;

            if ( ! $item->isDir()) {
                continue;
            }

            $children = $this->listDirectoryContentsRecursive($item->path());

            foreach ($children as $child) {
                yield $child;
            }
        }
    }

    /**
     * Normalize a permissions string.
     *
     * @param string $permissions
     *
     * @return int
     */
    protected function normalizePermissions(string $permissions): int
    {
        // remove the type identifier
        $permissions = substr($permissions, 1);
        // map the string rights to the numeric counterparts
        $map = ['-' => '0', 'r' => '4', 'w' => '2', 'x' => '1'];
        $permissions = strtr($permissions, $map);
        // split up the permission groups
        $parts = str_split($permissions, 3);
        // convert the groups
        $mapper = function ($part) {
            return array_sum(str_split($part));
        };

        // converts to decimal number
        return octdec(implode('', array_map($mapper, $parts)));
    }

    /**
     * Normalize path depending on server.
     *
     * @param string $path
     *
     * @return string
     */
    protected function escapePath(string $path): string
    {
        if (empty($path)) {
            return '';
        }

        if ($this->isPureFtpdServer()) {
            $path = str_replace(['*', '[', ']'], ['\\*', '\\[', '\\]'], $path);
        }

        $path = str_replace('*', '\\*', $path);

        return $path;
    }

    /**
     * @return bool
     */
    protected function isPureFtpdServer(): bool
    {
        if ($this->isPureFtpd === null) {
            $response = $this->rawCommand($this->getConnection(), 'HELP');
            $response = end($response);
            $this->isPureFtpd = stripos($response, 'Pure-FTPd') !== false;
        }

        return $this->isPureFtpd;
    }

    /**
     * Sends an arbitrary command to an FTP server.
     *
     * @param Curl $connection The CURL instance
     * @param string $command    The command to execute
     *
     * @return array Returns the server's response as an array of strings
     */
    protected function rawCommand(Curl $connection, string $command): array
    {
        $response = '';
        $callback = static function ($ch, $string) use (&$response) {
            $response .= $string;

            return strlen($string);
        };
        $connection->exec([
            CURLOPT_CUSTOMREQUEST => $command,
            CURLOPT_HEADERFUNCTION => $callback,
        ]);

        return explode(PHP_EOL, trim($response)); // TODO throw on error
    }

    /**
     * Sends an arbitrary command to an FTP server using POSTQUOTE option. This makes sure all commands are run
     * in succession and increases chance of success for complex operations like "move/rename file".
     *
     * @param Curl $connection The CURL instance
     * @param  array $commandsArray    The commands to execute
     *
     * @return array Returns the server's response as an array of strings
     */
    protected function rawPost(Curl $connection, array $commandsArray): array
    {
        $response = '';
        $callback = function ($ch, $string) use (&$response) {
            $response .= $string;

            return strlen($string);
        };
        $connection->exec([
            CURLOPT_POSTQUOTE => $commandsArray,
            CURLOPT_HEADERFUNCTION => $callback,
        ]);

        return explode(PHP_EOL, trim($response));
    }

    /**
     * Returns the base url of the connection.
     *
     * @return string
     */
    protected function getBaseUri(): string
    {
        $protocol = $this->ftps ? 'ftps' : 'ftp';

        return $protocol.'://'.$this->getHost().':'.$this->getPort();
    }

    /**
     * Check the connection is established.
     */
    protected function pingConnection(): void
    {
        // We can't use the getConnection, because it will lead to an infinite cycle
        if ($this->connection->exec() === false) {
            throw new RuntimeException('Could not connect to host: '.$this->getHost().', port:'.$this->getPort());
        }
    }

    /**
     * Set the connection to UTF-8 mode.
     */
    protected function setUtf8Mode(): void
    {
        if (! $this->utf8) {
            return;
        }

        $response = $this->rawCommand($this->connection, 'OPTS UTF8 ON');
        [$code, $message] = explode(' ', end($response), 2);
        if ($code !== '200') {
            throw new RuntimeException(
                'Could not set UTF-8 mode for connection: '.$this->getHost().'::'.$this->getPort()
            );
        }
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->fileSize($path);
            return true;
        } catch (UnableToRetrieveMetadata $exception) {
            return false;
        }
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->fetchMetadata($path, StorageAttributes::ATTRIBUTE_VISIBILITY);
    }

    private function fetchMetadata(string $path, string $type): FileAttributes
    {
        $location = $this->prefixer()->prefixPath($path);

        $object = $this->rawCommand($this->getConnection(), 'STAT ' . $this->escapePath($location));

        if (empty($object) || count($object) < 3 || substr($object[count($object) - 2], 0, 5) === "ftpd:") {
            throw UnableToRetrieveMetadata::create($path, $type, error_get_last()['message'] ?? '');
        }

        $attributes = $this->normalizeObject($object[count($object) - 2], '');

        if ( ! $attributes instanceof FileAttributes) {
            throw UnableToRetrieveMetadata::create(
                $path,
                $type,
                'expected file, ' . ($attributes instanceof DirectoryAttributes ? 'directory found' : 'nothing found')
            );
        }

        return $attributes;
    }

    public function fileSize(string $path): FileAttributes
    {
        $connection = $this->getConnection();
        $location = $this->prefixer()->prefixPath($path);

        $result = $this->rawCommand($connection, 'SIZE ' . $this->escapePath($location));
        [$code, $message] = explode(' ', end($result), 2);

        if ((int) $code !== 213) {
            throw UnableToRetrieveMetadata::fileSize($location, $connection->getLastError());
        }

        return new FileAttributes($location, (int) $message);

    }

    private function normalizeListing(array $listing, string $prefix = ''): Generator
    {
        $base = $prefix;

        foreach ($listing as $item) {
            if ($item === '' || preg_match('#.* \.(\.)?$|^total#', $item)) {
                continue;
            }

            if (preg_match('#^.*:$#', $item)) {
                $base = preg_replace('~^\./*|:$~', '', $item);
                continue;
            }

            yield $this->normalizeObject($item, $base);
        }
    }

    private function normalizeObject(string $item, string $base): StorageAttributes
    {
        $this->systemType === null && $this->systemType = $this->detectSystemType($item);

        if ($this->systemType === self::SYSTEM_TYPE_UNIX) {
            return $this->normalizeUnixObject($item, $base);
        }

        return $this->normalizeWindowsObject($item, $base);
    }

    private function detectSystemType(string $item): string
    {
        return preg_match(
            '/^[0-9]{2,4}-[0-9]{2}-[0-9]{2}/',
            $item
        ) ? self::SYSTEM_TYPE_WINDOWS : self::SYSTEM_TYPE_UNIX;
    }

    private function normalizeWindowsObject(string $item, string $base): StorageAttributes
    {
        $item = preg_replace('#\s+#', ' ', trim($item), 3);
        $parts = explode(' ', $item, 4);

        if (count($parts) !== 4) {
            throw new InvalidListResponseReceived("Metadata can't be parsed from item '$item' , not enough parts.");
        }

        [$date, $time, $size, $name] = $parts;
        $path = $base === '' ? $name : rtrim($base, '/') . '/' . $name;

        if ($size === '<DIR>') {
            return new DirectoryAttributes($path);
        }

        // Check for the correct date/time format
        $format = strlen($date) === 8 ? 'm-d-yH:iA' : 'Y-m-dH:i';
        $dt = DateTime::createFromFormat($format, $date . $time);
        $lastModified = $dt ? $dt->getTimestamp() : (int) strtotime("$date $time");

        return new FileAttributes($path, (int) $size, null, $lastModified);
    }

    private function normalizeUnixObject(string $item, string $base): StorageAttributes
    {
        $item = preg_replace('#\s+#', ' ', trim($item), 7);
        $parts = explode(' ', $item, 9);

        if (count($parts) !== 9) {
            throw new InvalidListResponseReceived("Metadata can't be parsed from item '$item' , not enough parts.");
        }

        [$permissions, /* $number */, /* $owner */, /* $group */, $size, $month, $day, $timeOrYear, $name] = $parts;
        $isDirectory = $this->listingItemIsDirectory($permissions);
        $permissions = $this->normalizePermissions($permissions);
        $path = $base === '' ? $name : rtrim($base, '/') . '/' . $name;
        $lastModified = $this->timestampsOnUnixListingsEnabled() ? $this->normalizeUnixTimestamp(
            $month,
            $day,
            $timeOrYear
        ) : null;

        if ($isDirectory) {
            return new DirectoryAttributes(
                $path, $this->visibilityConverter->inverseForDirectory($permissions), $lastModified
            );
        }

        $visibility = $this->visibilityConverter->inverseForFile($permissions);

        return new FileAttributes($path, (int) $size, $visibility, $lastModified);
    }

    private function listingItemIsDirectory(string $permissions): bool
    {
        return substr($permissions, 0, 1) === 'd';
    }

    private function normalizeUnixTimestamp(string $month, string $day, string $timeOrYear): int
    {
        if (is_numeric($timeOrYear)) {
            $year = $timeOrYear;
            $hour = '00';
            $minute = '00';
        } else {
            $year = date('Y');
            [$hour, $minute] = explode(':', $timeOrYear);
        }
        $seconds = '00';

        $dateTime = DateTime::createFromFormat('Y-M-j-G:i:s', "{$year}-{$month}-{$day}-{$hour}:{$minute}:{$seconds}");

        return $dateTime->getTimestamp();
    }

    private function ensureParentDirectoryExists(string $path, ?string $visibility): void
    {
        $dirname = dirname($path);

        if ($dirname === '' || $dirname === '.') {
            return;
        }

        $this->ensureDirectoryExists($dirname, $visibility);
    }

    /**
     * @param string $dirname
     * @param string|null $visibility
     */
    private function ensureDirectoryExists(string $dirname, ?string $visibility): void
    {
        $connection = $this->getConnection();

        $dirPath = '';
        $parts = explode('/', trim($dirname, '/'));
        $mode = $visibility ? $this->visibilityConverter->forDirectory($visibility) : false;

        foreach ($parts as $part) {
            $dirPath .= '/' . $part;
            $location = $this->prefixer()->prefixPath($dirPath);

            $response = $this->rawCommand($connection, 'CWD ' . $this->escapePath($location));
            [$code, $message] = explode(' ', end($response), 2);
            if ((int) $code === 250) {
                continue;
            }

            $response = $this->rawCommand($connection, 'MKD ' . $this->escapePath($location));
            [$code, $message] = explode(' ', end($response), 2);

            if ((int) $code === 250) {
                $errorMessage = $message ?? 'unable to create the directory';
                throw UnableToCreateDirectory::atLocation($dirPath, $errorMessage);
            }

            if ($mode !== false) {
                try {
                    $this->setVisibility($location, $visibility);
                } catch (UnableToSetVisibility $exception) {
                    throw UnableToCreateDirectory::atLocation($dirPath, 'unable to chmod the directory');
                }
            }
        }
    }

    /**
     * @param Curl $connection
     * @return string
     */
    private function resolveConnectionRoot(Curl $connection): string
    {
        $root = $this->getRoot();

        if ($root !== '') {
            $response = $this->rawCommand($connection, 'CWD ' . $this->escapePath($root));
            [$code, $message] = explode(' ', end($response), 2);
            if ((int) $code !== 250) {
                throw new RuntimeException('Root is invalid or does not exist: '.$root);
            }
        }

        $response = $this->rawCommand($connection, 'PWD');
        [$code, $message] = explode(' ', end($response), 2);

        return trim($message, '"');
    }

    /**
     * @return PathPrefixer
     */
    private function prefixer(): PathPrefixer
    {
        if ($this->rootDirectory === null) {
            $this->getConnection();
        }

        return $this->prefixer;
    }
}
