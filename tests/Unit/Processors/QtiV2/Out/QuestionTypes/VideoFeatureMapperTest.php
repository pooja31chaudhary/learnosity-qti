<?php

namespace LearnosityQti\Tests\Unit\Processors\QtiV2\Out\QuestionTypes;

use LearnosityQti\Entities\QuestionTypes\videoplayer;
use LearnosityQti\Processors\QtiV2\Out\QuestionTypes\VideoplayerMapper;
use qtism\data\content\interactions\MediaInteraction;

class VideoFeatureMapperTest extends \PHPUnit_Framework_TestCase {

    public function testSimpleCase()
    {
        $src = realpath($_SERVER["DOCUMENT_ROOT"]).'/Fixtures/assets/ef04cc3_f589cd84-c67f-4d34-bc1e-2367c64a797e.mp3';
		$feature = new videoplayer('videoplayer', 'hosted-video', $src);
        
		$videoPlayerMapper = new VideoplayerMapper();
		list($interaction, $responseDeclaration, $responseProcessing) = $videoPlayerMapper->convert($feature, 'testIdentifier', 'testIdentifierLabel');
        
        // Check usual
        $this->assertTrue($interaction instanceof MediaInteraction);
        $this->assertEquals('testIdentifier', $interaction->getResponseIdentifier());
        $this->assertEquals('testIdentifierLabel', $interaction->getLabel());
		$this->assertEquals('5', $interaction->getMaxPlays());
		$this->assertEquals('1', $interaction->getMinPlays());
        $this->assertNotNull($responseDeclaration);
        $this->assertNull($responseProcessing);
    }
}
