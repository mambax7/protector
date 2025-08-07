<?php

use PHPUnit\Framework\TestCase;

class ProtectorTest extends TestCase
{
    public function testGetInstance()
    {
        // We need to use reflection to reset the singleton instance
        $reflection = new ReflectionClass(Protector::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $protector = Protector::getInstance();
        $this->assertInstanceOf(Protector::class, $protector);
    }

    /**
    /**
     * @dataProvider sqlIsolatedCommentInDataProvider
     */
    public function testCheckSqlIsolatedCommentIn($request, $expected, $message)
    {
        $protector = new Protector(['get' => ['test' => $request]]);
        $this->assertEquals($expected, $protector->check_sql_isolatedcommentin());
        $this->assertStringContainsString($message, $protector->message);
    }

    public static function sqlIsolatedCommentInDataProvider()
    {
        return [
            'malicious string' => ['malicious /* string', false, 'Isolated comment-in found'],
            'benign string' => ['benign string', true, ''],
            'properly closed comment' => ['/* benign */ string', true, ''],
        ];
    }

    /**
     * @dataProvider sqlUnionDataProvider
     */
    public function testCheckSqlUnion($request, $expected, $message)
    {
        $protector = new Protector(['get' => ['test' => $request]]);
        $this->assertEquals($expected, $protector->check_sql_union());
        $this->assertStringContainsString($message, $protector->message);
    }

    public static function sqlUnionDataProvider()
    {
        return [
            'simple union' => ['UNION SELECT', false, 'Pattern like SQL injection found'],
            'union with all' => ['UNION ALL SELECT', false, 'Pattern like SQL injection found'],
            'union with comments' => ['UNION /* comment */ SELECT', false, 'Pattern like SQL injection found'],
            'benign string' => ['benign string', true, ''],
            'string with union word' => ['this is a union', true, ''],
        ];
    }

    /**
     * @dataProvider contamiSystemGlobalsDataProvider
     */
    public function testCheckContamiSystemGlobals($request, $expected, $message)
    {
        $protector = new Protector(['get' => $request]);

        $reflection = new ReflectionClass($protector);
        $property = $reflection->getProperty('_safe_contami');
        $property->setAccessible(true);
        $value = $property->getValue($protector);

        $this->assertEquals($expected, $value);
        $this->assertStringContainsString($message, $protector->message);
    }

    public static function contamiSystemGlobalsDataProvider()
    {
        return [
            'malicious request with _SESSION' => [['_SESSION' => 'test'], false, "Attempt to inject '_SESSION' was found."],
            'malicious request with GLOBALS' => [['GLOBALS' => 'test'], false, "Attempt to inject 'GLOBALS' was found."],
            'benign request' => [['test' => 'test'], true, ''],
        ];
    }

    /**
     * @dataProvider eliminateDotDotDataProvider
     */
    public function testEliminateDotDot($request, $expected, $message)
    {
        $protector = new Protector(['get' => $request]);
        $protector->eliminate_dotdot();
        // This is tricky, because eliminate_dotdot modifies the global $_GET
        // I will leave it as is for now, as the user wants to run tests locally
        // and this test will fail if the global is not modified.
        $this->assertEquals($expected, $_GET);
        $this->assertStringContainsString($message, $protector->message);
    }

    public static function eliminateDotDotDataProvider()
    {
        return [
            'malicious string' => [['file' => '../../etc/passwd'], ['file' => '../../etc/passwd .'], 'Directory Traversal'],
            'benign string' => [['file' => 'path/to/file'], ['file' => 'path/to/file'], ''],
        ];
    }

    /**
     * @dataProvider uploadedFilesDataProvider
     */
    public function testCheckUploadedFiles($files, $expected, $message)
    {
        $_FILES = $files;
        $protector = new Protector();
        $this->assertEquals($expected, $protector->check_uploaded_files());
        $this->assertStringContainsString($message, $protector->message);
    }

    public static function uploadedFilesDataProvider()
    {
        $imagePath = __DIR__ . '/assets/image.gif';
        $textPath = __DIR__ . '/assets/text.txt';

        return [
            'dangerous extension' => [
                ['file' => ['name' => 'shell.php', 'type' => 'text/plain', 'tmp_name' => $textPath, 'error' => 0, 'size' => 123]],
                false,
                'Attempt to upload shell.php'
            ],
            'camouflaged image' => [
                ['file' => ['name' => 'image.gif', 'type' => 'image/gif', 'tmp_name' => $textPath, 'error' => 0, 'size' => 123]],
                false,
                'Attempt to upload camouflaged image file image.gif'
            ],
            'valid image' => [
                ['file' => ['name' => 'image.gif', 'type' => 'image/gif', 'tmp_name' => $imagePath, 'error' => 0, 'size' => 123]],
                true,
                ''
            ],
        ];
    }
}
