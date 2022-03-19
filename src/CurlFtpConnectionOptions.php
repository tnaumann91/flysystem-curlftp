<?php

namespace VladimirYuldashev\Flysystem;

use League\Flysystem\Ftp\FtpConnectionOptions;

class CurlFtpConnectionOptions
{

    private $ftpConnectionOptions;

    /** @var bool */
    private $ftps;

    /** @var bool */
    private $verifyDisabled;

    public function __construct(
        string  $host,
        string  $root,
        string  $username,
        string  $password,
        int     $port = 21,
        bool    $ssl = false,
        bool    $ftps = true,
        bool    $verifyDisabled = false,
        int     $timeout = 90,
        bool    $utf8 = false,
        bool    $passive = true,
        int     $transferMode = FTP_BINARY,
        ?string $systemType = null,
        ?bool   $ignorePassiveAddress = null,
        bool    $enableTimestampsOnUnixListings = false,
        bool    $recurseManually = false
    )
    {
        $this->ftpConnectionOptions = new FtpConnectionOptions(
            $host,
            $root,
            $username,
            $password,
            $port,
            $ssl,
            $timeout,
            $utf8,
            $passive,
            $transferMode,
            $systemType,
            $ignorePassiveAddress,
            $enableTimestampsOnUnixListings,
            $recurseManually
        );

        $this->ftps = $ftps;
        $this->verifyDisabled = $verifyDisabled;
    }

    /**
     * @return string
     */
    public function host(): string
    {
        return $this->ftpConnectionOptions->host();
    }

    /**
     * @return string
     */
    public function root(): string
    {
        return $this->ftpConnectionOptions->root();
    }

    /**
     * @return string
     */
    public function username(): string
    {
        return $this->ftpConnectionOptions->username();
    }

    /**
     * @return string
     */
    public function password(): string
    {
        return $this->ftpConnectionOptions->password();
    }

    /**
     * @return int
     */
    public function port(): int
    {
        return $this->ftpConnectionOptions->port();
    }

    /**
     * @return bool
     */
    public function ssl(): bool
    {
        return $this->ftpConnectionOptions->ssl();
    }

    /**
     * @return int
     */
    public function timeout(): int
    {
        return $this->ftpConnectionOptions->timeout();
    }

    /**
     * @return bool
     */
    public function utf8(): bool
    {
        return $this->ftpConnectionOptions->utf8();
    }

    /**
     * @return bool
     */
    public function passive(): bool
    {
        return $this->ftpConnectionOptions->passive();
    }

    /**
     * @return int
     */
    public function transferMode(): int
    {
        return $this->ftpConnectionOptions->transferMode();
    }

    /**
     * @return string|null
     */
    public function systemType(): ?string
    {
        return $this->ftpConnectionOptions->systemType();
    }

    /**
     * @return bool|null
     */
    public function ignorePassiveAddress(): ?bool
    {
        return $this->ftpConnectionOptions->ignorePassiveAddress();
    }

    /**
     * @return bool
     */
    public function timestampsOnUnixListingsEnabled(): bool
    {
        return $this->ftpConnectionOptions->timestampsOnUnixListingsEnabled();
    }

    /**
     * @return bool
     */
    public function recurseManually(): bool
    {
        return $this->ftpConnectionOptions->recurseManually();
    }

    /**
     * @return bool
     */
    public function isFtps(): bool
    {
        return $this->ftps;
    }

    /**
     * @return bool
     */
    public function isVerifyDisabled(): bool
    {
        return $this->verifyDisabled;
    }

    /**
     * @param array $options
     * @return CurlFtpConnectionOptions
     */
    public static function fromArray(array $options): CurlFtpConnectionOptions
    {
        return new CurlFtpConnectionOptions(
            $options['host'] ?? 'invalid://host-not-set',
            $options['root'] ?? 'invalid://root-not-set',
            $options['username'] ?? 'invalid://username-not-set',
            $options['password'] ?? 'invalid://password-not-set',
            $options['port'] ?? 21,
            $options['ssl'] ?? false,
            $options['ftps'] ?? false,
            $options['verifyDisabled'] ?? false,
            $options['timeout'] ?? 90,
            $options['utf8'] ?? false,
            $options['passive'] ?? true,
            $options['transferMode'] ?? FTP_BINARY,
            $options['systemType'] ?? null,
            $options['ignorePassiveAddress'] ?? null,
            $options['timestampsOnUnixListingsEnabled'] ?? false,
            $options['recurseManually'] ?? true
        );
    }

}
