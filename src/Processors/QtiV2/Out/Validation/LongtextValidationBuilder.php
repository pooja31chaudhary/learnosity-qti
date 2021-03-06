<?php

namespace LearnosityQti\Processors\QtiV2\Out\Validation;

use LearnosityQti\Entities\QuestionTypes\longtextV2_validation;
use LearnosityQti\Entities\QuestionTypes\longtext_validation;
use LearnosityQti\Processors\QtiV2\Out\ResponseDeclarationBuilders\QtiCorrectResponseBuilder;
use qtism\common\enums\BaseType;
use qtism\common\enums\Cardinality;
use qtism\data\state\ResponseDeclaration;

class LongtextValidationBuilder extends AbstractQuestionValidationBuilder
{
    
    protected function buildResponseDeclaration($responseIdentifier, $validation)
    {
        /** @var longtext_validation $validation */
        $responseDeclaration = new ResponseDeclaration($responseIdentifier);

        $responseDeclaration->setCardinality(Cardinality::SINGLE);
        $responseDeclaration->setBaseType(BaseType::STRING);

        return $responseDeclaration;
    }
}
