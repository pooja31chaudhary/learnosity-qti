<?php

namespace Learnosity\Tests\Unit\Processors\QtiV2\In\Interactions;

use Learnosity\Entities\QuestionTypes\imageclozeassociation;
use Learnosity\Entities\QuestionTypes\imageclozeassociation_validation;
use Learnosity\Entities\QuestionTypes\imageclozeassociation_validation_valid_response;
use Learnosity\Processors\QtiV2\In\Interactions\GraphicGapMatchInteractionMapper;
use Learnosity\Processors\QtiV2\In\ResponseProcessingTemplate;
use Learnosity\Services\LogService;
use Learnosity\Tests\Unit\Processors\QtiV2\In\Fixtures\GraphicGapInteractionBuilder;
use Learnosity\Tests\Unit\Processors\QtiV2\In\Fixtures\ResponseDeclarationBuilder;
use Learnosity\Utils\StringUtil;
use qtism\common\datatypes\DirectedPair;
use qtism\data\content\xhtml\Object;

class GraphicGapMatchInteractionTest extends \PHPUnit_Framework_TestCase
{
    public function testWithMapResponseValidationMissingAssociableIdentifier()
    {
        $bgObject = new Object('http://img.png', 'image/png');
        $bgObject->setWidth(100);
        $bgObject->setHeight(200);
        $testInteraction = GraphicGapInteractionBuilder::build(
            'testInteraction',
            $bgObject,
            [
                'A' => 'img_A.png',
                'B' => 'img_B.png',
                'C' => 'img_C.png',
            ],
            [
                'G1' => [0, 0, 10, 10],
                'G2' => [0, 0, 10, 10]
            ]
        );

        $responseProcessingTemplate = ResponseProcessingTemplate::mapResponse();
        $validResponseIdentifier = [
            'A G1' => [1, false]
        ];
        $responseDeclaration = ResponseDeclarationBuilder::buildWithMapping(
            'testIdentifier',
            $validResponseIdentifier,
            'DirectedPair'
        );
        $mapper = new GraphicGapMatchInteractionMapper($testInteraction, $responseDeclaration, $responseProcessingTemplate);
        /** @var imageclozeassociation $q */
        $q = $mapper->getQuestionType();
        $this->assertEquals('imageclozeassociation', $q->get_type());
        $this->assertEquals(['<img src="img_A.png"/>', '<img src="img_B.png"/>', '<img src="img_C.png"/>'], $q->get_possible_responses());
        $this->assertFalse($q->get_duplicate_responses());
        $this->assertNull($q->get_validation());

        $containsWarning = false;
        foreach (LogService::read() as $message) {
            if (StringUtil::contains($message, 'Amount of gap identifiers 2 does not match the amount 1 for <responseDeclaration>')) {
                return true;
            }
        }
        $this->assertTrue($containsWarning);
    }

    public function testMapResponseValidation()
    {
        $bgObject = new Object('http://img.png', 'image/png');
        $bgObject->setWidth(100);
        $bgObject->setHeight(200);
        $testInteraction = GraphicGapInteractionBuilder::build(
            'testInteraction',
            $bgObject,
            [
                'A' => 'img_A.png',
                'B' => 'img_B.png',
                'C' => 'img_C.png',
            ],
            [
                'G1' => [0, 0, 10, 10],
                'G2' => [30, 40, 50, 60]
            ]
        );
        $responseProcessingTemplate = ResponseProcessingTemplate::mapResponse();
        $validResponseIdentifier = [
            'A G1' => [1, false],
            'B G1' => [2, false],
            'C G2' => [3, false]
        ];
        $responseDeclaration = ResponseDeclarationBuilder::buildWithMapping(
            'testIdentifier',
            $validResponseIdentifier,
            'DirectedPair'
        );

        $mapper = new GraphicGapMatchInteractionMapper($testInteraction, $responseDeclaration, $responseProcessingTemplate);
        /** @var imageclozeassociation $q */
        $q = $mapper->getQuestionType();
        $this->assertEquals('imageclozeassociation', $q->get_type());
        $this->assertEquals(['<img src="img_A.png"/>', '<img src="img_B.png"/>', '<img src="img_C.png"/>'], $q->get_possible_responses());
        $this->assertFalse($q->get_duplicate_responses());

        $this->assertEquals([
            ['x' => 0, 'y' => 0],
            ['x' => 30, 'y' => 20]
        ], $q->get_response_positions());

        $img = $q->get_image();
        $this->assertInstanceOf('Learnosity\Entities\QuestionTypes\imageclozeassociation_image', $img);
        $this->assertEquals('http://img.png', $img->get_src());

        /** @var imageclozeassociation_validation $validation */
        $validation = $q->get_validation();
        $this->assertInstanceOf(
            'Learnosity\Entities\QuestionTypes\imageclozeassociation_validation',
            $validation
        );
        $this->assertEquals('exactMatch', $validation->get_scoring_type());

        /** @var imageclozeassociation_validation_valid_response $validResponse */
        $validResponse = $validation->get_valid_response();
        $this->assertInstanceOf(
            'Learnosity\Entities\QuestionTypes\imageclozeassociation_validation_valid_response',
            $validResponse
        );
        $this->assertEquals(5, $validResponse->get_score());
        $this->assertEquals(['<img src="img_B.png"/>', '<img src="img_C.png"/>'], $validResponse->get_value());

        $altResponses = $validation->get_alt_responses();
        $this->assertCount(1, $altResponses);

        $this->assertEquals(4, $altResponses[0]->get_score());
        $this->assertEquals(['<img src="img_A.png"/>', '<img src="img_C.png"/>'], $altResponses[0]->get_value());
    }

    public function testMatchCorrectValidation()
    {
        $bgObject = new Object('http://img.png', 'image/png');
        $bgObject->setWidth(100);
        $bgObject->setHeight(200);
        $testInteraction = GraphicGapInteractionBuilder::build(
            'testInteraction',
            $bgObject,
            [
                'A' => 'img_A.png',
                'B' => 'img_B.png'
            ],
            [
                'G1' => [0, 0, 10, 10],
                'G2' => [30, 40, 50, 60]
            ]
        );

        $responseProcessingTemplate = ResponseProcessingTemplate::matchCorrect();
        $validResponseIdentifier = [
            new DirectedPair('A', 'G1'),
            new DirectedPair('A', 'G2'),
            new DirectedPair('B', 'G1'),
        ];
        $responseDeclaration =
            ResponseDeclarationBuilder::buildWithCorrectResponse('testIdentifier', $validResponseIdentifier);
        $mapper = new GraphicGapMatchInteractionMapper($testInteraction, $responseDeclaration, $responseProcessingTemplate);
        /** @var imageclozeassociation $q */
        $q = $mapper->getQuestionType();

        $this->assertEquals('imageclozeassociation', $q->get_type());
        $this->assertEquals(['<img src="img_A.png"/>', '<img src="img_B.png"/>'], $q->get_possible_responses());
        $this->assertTrue($q->get_duplicate_responses());

        $this->assertEquals([
            ['x' => 0, 'y' => 0],
            ['x' => 30, 'y' => 20]
        ], $q->get_response_positions());

        $img = $q->get_image();
        $this->assertInstanceOf('Learnosity\Entities\QuestionTypes\imageclozeassociation_image', $img);
        $this->assertEquals('http://img.png', $img->get_src());

        /** @var imageclozeassociation_validation $validation */
        $validation = $q->get_validation();
        $this->assertInstanceOf(
            'Learnosity\Entities\QuestionTypes\imageclozeassociation_validation',
            $validation
        );
        $this->assertEquals('exactMatch', $validation->get_scoring_type());

        /** @var imageclozeassociation_validation_valid_response $validResponse */
        $validResponse = $validation->get_valid_response();
        $this->assertInstanceOf(
            'Learnosity\Entities\QuestionTypes\imageclozeassociation_validation_valid_response',
            $validResponse
        );
        $this->assertEquals(1, $validResponse->get_score());
        $this->assertEquals(['<img src="img_A.png"/>', '<img src="img_A.png"/>'], $validResponse->get_value());

        $altResponses = $validation->get_alt_responses();
        $this->assertCount(1, $altResponses);

        $this->assertEquals(1, $altResponses[0]->get_score());
        $this->assertEquals(['<img src="img_B.png"/>', '<img src="img_A.png"/>'], $altResponses[0]->get_value());
    }
}
