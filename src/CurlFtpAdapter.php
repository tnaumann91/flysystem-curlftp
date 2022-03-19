<?php

namespace VladimirYuldashev\Flysystem;

use DateTime;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use \Normalizer;
use RuntimeException;

class CurlFtpAdapter implements FilesystemAdapter
{
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
    }

    private $visibilityConverter;

    private $mimeTypeDetector;

    /** @var Curl */
    protected $connection;

    /** @var int unix timestamp when connection was established */
    protected $connectionTimestamp = 0;

    /** @var bool */
    protected $isPureFtpd;

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
    public function getProxyHost(): string
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
    public function getProxyPort(): int
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
    public function getProxyUsername(): string
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
        $this->verbose = (bool) $verbose;
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
        $this->setUtf8Mode();
        $this->setConnectionRoot();
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
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        $this->writeStream($path, $stream, $config);
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

        $result = $connection->exec([
            CURLOPT_URL => $this->getBaseUri().'/'.$path,
            CURLOPT_UPLOAD => 1,
            CURLOPT_INFILE => $contents,
        ]);

        if ($result === false) {
            throw new UnableToWriteFile('Curl returned false value');
        }
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $connection = $this->getConnection();

        $moveCommands = [
            'RNFR '.$path,
            'RNTO '.$newpath,
        ];

        $response = $this->rawPost($connection, $moveCommands);
        list($code) = explode(' ', end($response), 2);

        return (int) $code === 250;
    }

    /**
     * Copy a file.
     *
     * @param string $source
     * @param string $destination
     *
     * @throws UnableToCopyFile
     * @throws FilesystemException
     */
    public function copy(string $source, string $destination, Config $config) : void
    {
        $file = $this->read($source);

        if ($file === false) {
            // TODO throw return false;
        }

        $this->write($destination, $file['contents'], $config);
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path) : void
    {
        $connection = $this->getConnection();

        $response = $this->rawCommand($connection, 'DELE '.$path);
        [$code] = explode(' ', end($response), 2);

        if ((int) $code !== 250) {
            throw new UnableToDeleteFile();
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
            throw new UnableToDeleteDirectory();
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
            throw new UnableToCreateDirectory();
        }
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility(string $path, string $visibility) : void
    {
        $connection = $this->getConnection();

        if ($visibility === AdapterInterface::VISIBILITY_PUBLIC) {// TODO use visibilityConverter
            $mode = $this->getPermPublic();
        } else {
            $mode = $this->getPermPrivate();
        }

        $request = sprintf('SITE CHMOD %o %s', $mode, $path);
        $response = $this->rawCommand($connection, $request);
        [$code] = explode(' ', end($response), 2);
        if ((int) $code !== 200) {
            return false;
        }

        return $this->getMetadata($path);
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
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

        $result = $connection->exec([
            CURLOPT_URL => $this->getBaseUri().'/'.$path,
            CURLOPT_FILE => $stream,
        ]);

        if (! $result) {
            fclose($stream);
            throw new UnableToReadFile();
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

        $request = rtrim('LIST -A '.$this->normalizePath($path));

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
    public function getMimetype(string $path)
    {
        if (! $metadata = $this->getMetadata($path)) {
            return false;
        }

        $metadata['mimetype'] = MimeType::detectByFilename($path);

        return $metadata;
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp(string $path)
    {
        $response = $this->rawCommand($this->getConnection(), 'MDTM '.$path);
        [$code, $time] = explode(' ', end($response), 2);
        if ($code !== '213') {
            return false;
        }

        if (strpos($time, '.')) {
            $datetime = DateTime::createFromFormat('YmdHis.u', $time);
        } else {
            $datetime = DateTime::createFromFormat('YmdHis', $time);
        }

        if (! $datetime) {
            return false;
        }

        return ['path' => $path, 'timestamp' => $datetime->getTimestamp()];
    }

    /**
     * {@inheritdoc}
     *
     * @param string $directory
     */
    protected function listDirectoryContents(string $directory, $recursive = false)
    {
        if ($recursive === true) {
            return $this->listDirectoryContentsRecursive($directory);
        }

        $request = rtrim('LIST -aln '.$this->normalizePath($directory));

        $connection = $this->getConnection();
        $result = $connection->exec([CURLOPT_CUSTOMREQUEST => $request]);
        if ($result === false) {
            return [];
        }

        if ($directory === '/') {
            $directory = '';
        }

        return $this->normalizeListing(explode(PHP_EOL, $result), $directory);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $directory
     */
    protected function listDirectoryContentsRecursive(string $directory)
    {
        $request = rtrim('LIST -aln '.$this->normalizePath($directory));

        $connection = $this->getConnection();
        $result = $connection->exec([CURLOPT_CUSTOMREQUEST => $request]);

        $listing = $this->normalizeListing(explode(PHP_EOL, $result), $directory);
        $output = [];

        foreach ($listing as $item) {
            $output[] = $item;
            if ($item['type'] === 'dir') {
                $output = array_merge($output, $this->listDirectoryContentsRecursive($item['path']));
            }
        }

        return $output;
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
    protected function normalizePath(string $path): string
    {
        if (empty($path)) {
            return '';
        }

        $path = Normalizer::normalize($path);// TODO use PathNormalizer

        if ($this->isPureFtpdServer()) {
            $path = str_replace(' ', '\ ', $path);
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

        return explode(PHP_EOL, trim($response));
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

    /**
     * Set the connection root.
     */
    protected function setConnectionRoot(): void
    {
        $root = $this->getRoot();
        if (empty($root)) {
            return;
        }

        // We can't use the getConnection, because it will lead to an infinite cycle
        $response = $this->rawCommand($this->connection, 'CWD '.$root);
        [$code] = explode(' ', end($response), 2);
        if ((int) $code !== 250) {
            throw new RuntimeException('Root is invalid or does not exist: '.$this->getRoot());
        }
    }

    public function fileExists(string $path): bool
    {
        // TODO: Implement fileExists() method.
    }

    public function visibility(string $path): FileAttributes
    {
        // TODO: Implement visibility() method.
    }

    public function mimeType(string $path): FileAttributes
    {
        // TODO: Implement mimeType() method.
    }

    public function lastModified(string $path): FileAttributes
    {
        // TODO: Implement lastModified() method.
    }

    public function fileSize(string $path): FileAttributes
    {
        // TODO: Implement fileSize() method.
    }

    public function listContents(string $path, bool $deep): iterable
    {
        // TODO: Implement listContents() method.
    }

    public function move(string $source, string $destination, Config $config): void
    {
        // TODO: Implement move() method.
    }
}
