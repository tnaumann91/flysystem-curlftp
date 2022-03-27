<?php

namespace VladimirYuldashev\Flysystem\Tests;

use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;

class CurlFtpAdapterTest extends TestCase
{
    /**
     * @dataProvider filesProvider
     *
     * @param $filename
     * @throws \League\Flysystem\FilesystemException
     */
    public function testWrite($filename): void
    {
        $contents = $this->faker()->text;

        $this->adapter->write($filename, $contents, new Config);
        $this->assertSame($contents, $this->adapter->read($filename));
    }

    /**
     * @dataProvider filesProvider
     *
     * @param $filename
     */
    public function testMove($filename): void
    {
        $this->adapter->write($filename, 'foo', new Config());

        $newFilename = $this->randomFileName();

        $this->adapter->move($filename, $newFilename, new Config());

        $this->assertFalse($this->adapter->fileExists($filename));
        $this->assertNotFalse($this->adapter->fileExists($newFilename));
    }

    /**
     * @dataProvider filesProvider
     *
     * @param $filename
     */
    public function testCopy($filename): void
    {
        $this->adapter->write($filename, 'foo', new Config());

        $this->adapter->copy($filename, 'bar', new Config());

        $this->assertNotFalse($this->adapter->fileExists($filename));
        $this->assertNotFalse($this->adapter->fileExists('bar'));
        $this->assertEquals($this->adapter->read($filename), $this->adapter->read('bar'));

        $this->expectException(UnableToCopyFile::class);
        $this->expectExceptionMessage('Unable to copy file from foo-bar to bar-foo');
        $this->adapter->copy('foo-bar', 'bar-foo', new Config());
    }

    /**
     * @dataProvider filesProvider
     *
     * @param $filename
     */
    public function testDelete($filename): void
    {
        $this->adapter->write($filename, 'foo', new Config());

        $this->adapter->delete($filename);

        $this->assertFalse($this->adapter->fileExists($filename));
    }

    public function testCreateAndDeleteDir(): void
    {
        $this->adapter->createDirectory('foo', new Config());
        $this->adapter->deleteDirectory('foo');

        $this->expectException(UnableToDeleteDirectory::class);
        $this->adapter->deleteDirectory('bar');
    }

    /**
     * @dataProvider filesProvider
     *
     * @param $filename
     */
    public function testGetSetVisibility($filename): void
    {
        $this->adapter->write($filename, 'foo', new Config());

        $this->adapter->setVisibility($filename, 'public');
        $fileAttributes = $this->adapter->visibility($filename);

        $this->assertSame('public', $fileAttributes->visibility());

        $this->adapter->setVisibility($filename, 'private');
        $fileAttributes = $this->adapter->visibility($filename);

        $this->assertSame('private', $fileAttributes->visibility());

        $this->expectException(UnableToSetVisibility::class);
        $this->adapter->setVisibility('bar', 'public');
    }

    /**
     * @dataProvider filesProvider
     *
     * @param $name
     */
    public function testRead($name): void
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($name, $contents);

        $response = $this->adapter->read($name);

        $this->assertEquals($contents, $response);
    }

    public function testGetMetadata(): void
    {
        $this->assertSame(['type' => 'dir', 'path' => ''], $this->adapter->getMetadata(''));
    }

    /**
     * @dataProvider filesProvider
     *
     * @param $name
     */
    public function testFileExists($name): void
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($name, $contents);

        $this->assertTrue($this->adapter->fileExists($name));

        $fileName = $this->randomFileName();
        $this->assertFalse($this->adapter->fileExists($fileName));
    }

    /**
     * @dataProvider withSubFolderProvider
     *
     * @param $path
     */
    public function testFileExistsInSubFolder($path): void
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($path, $contents);

        $this->assertTrue($this->adapter->fileExists($path));
    }

    /**
     * @return void
     * @throws FilesystemException
     */
    public function testGetMimeType(): void
    {
        $this->adapter->write('foo.json', 'bar', new Config);
        $fileAttributes = $this->adapter->mimetype('foo.json');
        $this->assertSame('application/json', $fileAttributes->mimeType());

        $this->expectException(UnableToRetrieveMetadata::class);
        $this->adapter->mimetype('bar.zbar');
    }

    public function testLastModified(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->adapter->lastModified('foo');
    }

    /**
     * @dataProvider withSubFolderProvider
     *
     * @param $path
     */
    public function testListContents($path): void
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($path, $contents);

        $this->assertCount(1, $this->adapter->listContents(dirname($path)));
    }

    /**
     * @dataProvider withSubFolderProvider
     *
     * @param $path
     */
    public function testListContentsEmptyPath($path): void
    {
        $this->assertCount(0, $this->adapter->listContents(dirname($path)));
    }

    public function filesProvider()
    {
        return [
            ['test.txt'],
            ['..test.txt'],
            ['test 1.txt'],
            ['test  2.txt'],
            ['тест.txt'],
            [$this->randomFileName()],
            [$this->randomFileName()],
            [$this->randomFileName()],
            [$this->randomFileName()],
            [$this->randomFileName()],
        ];
    }

    public function withSubFolderProvider()
    {
        return [
            ['test/test.txt'],
            ['тёст/тёст.txt'],
            ['test 1/test.txt'],
            ['test/test 1.txt'],
            ['test  1/test  2.txt'],
            [$this->faker()->word.'/'.$this->randomFileName()],
            [$this->faker()->word.'/'.$this->randomFileName()],
            [$this->faker()->word.'/'.$this->randomFileName()],
            [$this->faker()->word.'/'.$this->randomFileName()],
            [$this->faker()->word.'/'.$this->randomFileName()],
        ];
    }
}
