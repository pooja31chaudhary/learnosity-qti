<?php

namespace Learnosity\Tests\Mappers\QtiV2;

use Learnosity\Mappers\Learnosity\Export\ItemWriter;
use Learnosity\Mappers\Learnosity\Export\QuestionWriter;
use Learnosity\Mappers\QtiV2\Import\ItemMapper;
use Learnosity\Utils\FileSystemUtil;
use PHPUnit_Framework_TestCase;

class ItemMapperTest extends PHPUnit_Framework_TestCase
{
    public function testParse()
    {
        $xml = FileSystemUtil::readFile(FileSystemUtil::getRootPath() . '/src/Tests/Fixtures/choices.xml');
        $mapper = new ItemMapper();
        list($item, $questions, $exceptions) = $mapper->parse($xml->getContents());

        $writer = new ItemWriter($item);
        $itemJson = $writer->convert($item);
        $questionConverter = new QuestionWriter();
        $qeustionJson = $questionConverter->convert(array_values($questions)[0]);

        echo 'Done!';
    }

    public function testMergingInteractions()
    {
        $xml    = FileSystemUtil::readFile(FileSystemUtil::getRootPath() . '/src/Tests/Fixtures/textentryinteraction.xml');
        $mapper = new ItemMapper();
        list($item, $questions, $exceptions) = $mapper->parse($xml->getContents());

        $writer            = new ItemWriter($item);
        $itemJson          = $writer->convert($item);
        $questionConverter = new QuestionWriter();
        $qeustionJson      = $questionConverter->convert(array_values($questions)[0]);

        echo 'Done!';
    }

    public function testParsingObjects()
    {
        $xml    = FileSystemUtil::readFile(FileSystemUtil::getRootPath() . '/src/Tests/Fixtures/withobjects.xml');
        $mapper = new ItemMapper();
        list($item, $questions, $exceptions) = $mapper->parse($xml->getContents());

        $writer            = new ItemWriter($item);
        $itemJson          = $writer->convert($item);
        $questionConverter = new QuestionWriter();
        $qeustionJson      = $questionConverter->convert(array_values($questions)[0]);

        echo 'Done!';
    }
}
