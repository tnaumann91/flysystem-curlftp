<?php

namespace VladimirYuldashev\Flysystem\Tests;

use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;
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
        $this->adapter->write($filename, 'foo', new Config);

        $result = $this->adapter->copy($filename, 'bar');

        $this->assertTrue($result);
        $this->assertNotFalse($this->adapter->has($filename));
        $this->assertNotFalse($this->adapter->has('bar'));
        $this->assertEquals($this->adapter->read($filename)['contents'], $this->adapter->read('bar')['contents']);

        $this->assertFalse($this->adapter->copy('foo-bar', 'bar-foo'));
    }

    /**
     * @dataProvider filesProvider
     *
     * @param $filename
     */
    public function testDelete($filename): void
    {
        $this->adapter->write($filename, 'foo', new Config);

        $result = $this->adapter->delete($filename);

        $this->assertTrue($result);
        $this->assertFalse($this->adapter->has($filename));
    }

    public function testCreateAndDeleteDir(): void
    {
        $result = $this->adapter->createDir('foo', new Config);

        $this->assertSame(['type' => 'dir', 'path' => 'foo'], $result);

        $result = $this->adapter->deleteDir('foo');

        $this->assertTrue($result);
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

        $this->assertSame([
            'type' => 'file',
            'path' => $name,
            'contents' => $contents,
        ], $response);
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
    public function testHas($name): void
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($name, $contents);

        $this->assertTrue((bool) $this->adapter->has($name));
    }

    /**
     * @dataProvider withSubFolderProvider
     *
     * @param $path
     */
    public function testHasInSubFolder($path): void
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($path, $contents);

        $this->assertTrue((bool) $this->adapter->has($path));
    }

    public function testGetMimeType(): void
    {
        $this->adapter->write('foo.json', 'bar', new Config);

        $this->assertSame('application/json', $this->adapter->getMimetype('foo.json')['mimetype']);
        $this->assertFalse($this->adapter->getMimetype('bar.json'));
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
