<?php

namespace LearnosityQti\Processors\QtiV2\In\Validation;

use \LearnosityQti\Exceptions\MappingException;
use \LearnosityQti\Processors\QtiV2\In\ResponseProcessingTemplate;
use \LearnosityQti\Services\LogService;
use \qtism\data\state\ResponseDeclaration;
use \qtism\data\processing\ResponseProcessing;
use \qtism\data\rules\ResponseCondition;
use \qtism\data\QtiComponent;
use \qtism\data\expressions\operators\IsNull;
use \qtism\data\rules\ResponseRuleCollection;
use \qtism\data\expressions\operators\Match;
use \qtism\data\expressions\operators\Equal;
use \qtism\data\expressions\BaseValue;
use \qtism\data\expressions\Variable;
use \qtism\data\state\OutcomeDeclarationCollection;
use \qtism\data\state\Mapping;
use \qtism\data\rules\ResponseElse;
use \qtism\data\expressions\MapResponse;

abstract class BaseInteractionValidationBuilder
{
    protected $responseDeclaration;
    protected $outcomeDeclarations;

    public function __construct(
        ResponseDeclaration $responseDeclaration = null,
        OutcomeDeclarationCollection $outcomeDeclarations = null
    ) {
        $this->responseDeclaration = $responseDeclaration;
        $this->outcomeDeclarations = $outcomeDeclarations;
    }

    protected function getMatchCorrectTemplateValidation(array $scores = null)
    {
        LogService::log(
            'Does not support `match_correct` response processing template for this interaction. ' .
            'Fail mapping validation object'
        );
        return null;
    }

    protected function getMapResponseTemplateValidation()
    {
        LogService::log(
            'Does not support `map_response` response processing template for this interaction. ' .
            'Fail mapping validation object'
        );
        return null;
    }

    protected function getNoTemplateResponsesValidation()
    {
        LogService::log('No response processing detected');
        return null;
    }

    /**
     * Process the built-in responseProcessing rules
     *
     * @param \qtism\data\processing\ResponseProcessing
     * @return array
     */
    protected function getBuiltinResponseValidation(ResponseProcessing $responseProcessing = null)
    {
        if (empty($responseProcessing)) {
            return null;
        }

        $results = [];

        $responseRules = $responseProcessing->getResponseRules();

        // step through each ProcessingRule object and parse them
        foreach ($responseRules as $responseRule) {
            switch (true) {
                case $responseRule instanceof ResponseCondition:
                    $results = array_merge_recursive($results, $this->processResponseCondition($responseRule));
                    break;

                case $responseRule instanceof SetOutcomeValue:
                    LogService::log('ResponseProcessing: Skipping top level SetOutcomeValue in response processing');
                    // skipping top level SetOutcomeValue since they are for item-level validation
                    break;

                default:
                    LogService::log('ResponseProcessing: Unsupported processing rule: ' . get_class($responseRule));
            }
        }

        return $results;
    }

    public function buildValidation(ResponseProcessingTemplate $responseProcessingTemplate)
    {
        try {
            switch ($responseProcessingTemplate->getTemplate()) {
                case ResponseProcessingTemplate::MATCH_CORRECT:
                    return $this->getMatchCorrectTemplateValidation();
                case ResponseProcessingTemplate::MAP_RESPONSE:
                case ResponseProcessingTemplate::CC2_MAP_RESPONSE:
                    return $this->getMapResponseTemplateValidation();
                case ResponseProcessingTemplate::NONE:
                    if (!empty($this->responseDeclaration)) {
                        // If the response processing template is not set, simply check whether `mapping` or `correctResponse` exists and
                        // simply use `em
                        if (!empty($this->responseDeclaration->getMapping()) && $this->responseDeclaration->getMapping()->getMapEntries()->count() > 0) {
                            LogService::log('Response processing is not set, the `validation` object is assumed to be mapped based on `mapping` map entries elements');
                            return $this->getMapResponseTemplateValidation();
                        }
                        if (!empty($this->responseDeclaration->getCorrectResponse()) && $this->responseDeclaration->getCorrectResponse()->getValues()->count() > 0) {
                            LogService::log('Response processing is not set, the `validation` object is assumed to be mapped based on `correctResponse` values elements');
                            return $this->getMatchCorrectTemplateValidation();
                        }
                    }
                    return $this->getNoTemplateResponsesValidation();

                case ResponseProcessingTemplate::BUILTIN:
                    // custom response processing rules
                    $responseProcessingScores = $this->getBuiltinResponseValidation($responseProcessingTemplate->getBuiltinResponseProcessing());
                    if (!empty($responseProcessingScores)) {
                        return $this->getMatchCorrectTemplateValidation($responseProcessingScores);
                    }

                    LogService::log('ResponseProcessing: Unrecognized scoring type: ' . print_r($results, true));
                    break;

                default:
                    LogService::log('ResponseProcessing: Unrecognised response processing template. Validation is not available');
            }
        } catch (MappingException $e) {
            LogService::log('Validation is not available. Critical error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Process the response condition. A response condition consists of:
     *  - ResponseIf
     *  - zero or more ResponseElseIf
     *  - zero or 1 ResponseElse
     *
     * @param \qtism\data\rules\ResponseCondition
     * @return array
     */
    protected function processResponseCondition(ResponseCondition $responseCondition)
    {
        // NOTE: turn this into an object
        $results = [];

        /*
         * We need to keep track of the interactionId, as we progress through the branches.
         * There are multiple ways to obtain this Id, and we only know where to look for when
         * we are inside the condition itself. This is why the processConditionBranch method
         * needs to return both the id and the scores.
         *
         * Usually, the ResponseElse block does not have an Id , so we rely on the value from
         * previous branches (If/ElseIf) to give us the id.
         */
        $interactionId = null;

        // process the ResponseIf, this object is required
        $responseIf = $responseCondition->getResponseIf();
        list($interactionId, $conditionResults) = $this->processConditionBranch($responseIf);
        $results = $this->mergeResponseResults($results, $conditionResults, $interactionId);

        // process the ResponseElseIf, optional argument, default to an empty ResponseElseIfCollection
        $responseElseIfs = $responseCondition->getResponseElseIfs();
        foreach ($responseElseIfs as $responseElseIf) {
            list($ignore, $conditionResults) = $this->processConditionBranch($responseElseIf);
            $results = $this->mergeResponseResults($results, $conditionResults, $interactionId);
        }

        // process the ResponseElse
        if ($responseCondition->hasResponseElse()) {
            $responseElse = $responseCondition->getResponseElse();
            list($ignore, $conditionResults) = $this->processConditionBranch($responseElse);
            $results = $this->mergeResponseResults($results, $conditionResults, $interactionId);
        }

        return $results;
    }

    /**
     * Merge the scoring results together, based on whether the interactionId exists or not.
     *
     * @param array $results - the overall results
     * @param array $ConditionResults - the current result set after parsing one condition
     * @param string $interactionId - the interactionId the current result set is for. Can be null.
     * @return array
     */
    protected function mergeResponseResults(array $results, array $conditionResults, $interactionId)
    {
        if (empty($interactionId)) {
            // we do not have an interactionId, this means the conditionResults belongs to the global scope
            $results = array_merge($results, $conditionResults);
        } elseif (isset($results[$interactionId])) {
            // existing data available for this id, we want to merge to that id
            $results[$interactionId] = array_merge_recursive($results[$interactionId], $conditionResults);
        } else {
            // we have an identifier, but no entry yet, so we create a new entry
            $results[$interactionId] = $conditionResults;
        }

        return $results;
    }

    /**
     * Process a ResponseIf, ResponseElseIf or a ResponseElse object.
     *
     * Those conditions are being inherited from QtiComponent, as the parent. While this is a little bit broad,
     * it is up to the caller to pass in the correct object.
     *
     * @param QtiComponent $conditionBranch
     * @return array
     */
    protected function processConditionBranch(QtiComponent $conditionBranch)
    {
        $results = [];
        $expression = null;
        $responseId = null;

        if (!($conditionBranch instanceof ResponseElse)) {
            // NOTE: the ResponseElse object does not have an expression, thus getExpression doesnt exist
            $expression = $conditionBranch->getExpression();
        }

        switch (true) {
            case $expression instanceof IsNull:
                // unattempted, get the first response rule as there should only be one.
                $responseId = $expression->getExpressions()[0]->getIdentifier();
                $responseRules = $conditionBranch->getResponseRules();

                if ($responseRules->count() > 1) {
                    LogService::log('ResponseProcessing: Expecting only one response rule for IsNull expression');
                }

                $outcomeValues = $this->getOutcomeValuesFromResponseRules($responseRules);
                $results['unattempted'] = $outcomeValues[0];
                break;

            case $expression instanceof Match:
                // correct answer, get the first response rule as there should only be one.
                $responseId = $expression->getExpressions()[0]->getIdentifier();
                $responseRules = $conditionBranch->getResponseRules();

                if ($responseRules->count() > 1) {
                    LogService::log('ResponseProcessing: Expecting only one response rule for Match expression');
                }

                $outcomeValues = $this->getOutcomeValuesFromResponseRules($responseRules);
                $results['correct'] = $outcomeValues[0];
                $results['scoring_type'] = 'match';
                break;

            case $expression instanceof Equal:
                // comparing a response to a value - can assume to be correct
                $responseRules = $conditionBranch->getResponseRules();
                $responseId = $expression->getExpressions()[0]->getIdentifier();
                $correctValue = $expression->getExpressions()[1]->getValue();

                $outcomeValues = $this->getOutcomeValuesFromResponseRules($responseRules);
                $results['correct'][] = [
                    'score' => $outcomeValues[0],
                    'answer' => $correctValue,
                ];
                $results['scoring_type'] = 'match';
                break;

            case is_null($expression):
                $responseRules = $conditionBranch->getResponseRules();

                $outcomeValues = $this->getOutcomeValuesFromResponseRules($responseRules);
                if (!empty($outcomeValues['map_response'])) {
                    $results['scoring_type'] = 'partial';
                    $results['score'] = $outcomeValues['score'];
                } else {
                    $results['incorrect'] = 0;
                    if (!empty($outcomeValues[0])) {
                        $results['incorrect'] = $outcomeValues[0];
                    }
                }
                break;

            default:
                LogService::log('ResponseProcessing: Unsupported expression: ' . get_class($expression));
        }

        return [$responseId, $results];
    }

    /**
     * Get the outcome values from the ResponseRuleCollection
     *
     * @param \qtism\data\rules\ResponseRuleCollection
     * @return array
     */
    protected function getOutcomeValuesFromResponseRules(ResponseRuleCollection $responseRules)
    {
        $results = [];

        if ($responseRules->count() === 0) {
            return $results;
        }

        // NOTE: the response rules elements are SetOutcomeValue objects
        foreach ($responseRules as $setOutcomeValue) {
            $expression = $setOutcomeValue->getExpression();

            // the expression here can either be a BaseValue or a Variable object
            switch (true) {
                case $expression instanceof BaseValue:
                    $results[] = $expression->getValue();
                    break;

                case $expression instanceof Variable:
                    $id = $expression->getIdentifier();

                    // look up the response declaration to get the base value
                    if (empty($this->outcomeDeclarations[$id])) {
                        // no mapping found for the specified identifier
                        LogService::log("ResponseProcessing: No variable mapping found in outcomeDeclaration block for: $id");
                        break;
                    }

                    $defaultValues = $this->outcomeDeclarations[$id]->getDefaultValue();

                    // we only want the first object as as the variable should only map to another, not multiple
                    $values = $defaultValues->getValues();
                    $results[] = $values[0]->getValue();
                    break;

                case $expression instanceof MapResponse:
                    $responseDeclaration = $this->responseDeclaration;
                    if (!empty($this->responseDeclarations[$expression->getIdentifier()])) {
                        $responseDeclaration = $this->responseDeclarations[$expression->getIdentifier()];
                    }

                    if (empty($responseDeclaration)) {
                        break;
                    }
                    $mapping = $responseDeclaration->getMapping();

                    if ($mapping instanceof Mapping) {
                        $score = null;
                        $maps = $mapping->getMapEntries();

                        foreach ($maps as $map) {
                            $score = $map->getMappedValue();
                            if ($score > 0) {
                                break;
                            }
                        }
                        $results['score'] = $score;
                        $results['map_response'] = true;
                    }
                    break;

                default:
                    LogService::log('ResponseProcessing: Unrecognized expression inside SetOutcomeValue: ' . get_class($expression));
            }
        }

        return $results;
    }

    /**
     * Return the scoring data for a particular interaction.
     *
     * @param array $scores - default null
     * @return array|null
     */
    protected function getScoresForInteraction(array $scores = null)
    {
        $result = null;

        if (isset($this->responseDeclaration)) {
            $interactionId = $this->responseDeclaration->getIdentifier();
            if (!empty($scores[$interactionId])) {
                $result = $scores[$interactionId];
            }
        }
        return $result;
    }

    /**
     * Extract the scoring data out of the response processing scores.
     *
     * @param array $responseScores - the scoring data for a response
     * @return array
     *   - score: float - default 1
     *   - mode: string - default exactMatch
     */
    protected function getValidationScoringData(array $responseScores = null)
    {
        $score = 1;
        if (!empty($responseScores['score'])) {
            $score = floatval($responseScores['score']);
        }
        if (!empty($responseScores['correct'])) {
            $score = floatval($responseScores['correct']);
        }

        $mode = 'exactMatch';
        if (!empty($responseScores['scoring_type']) && $responseScores['scoring_type'] === 'partial') {
            $mode = 'partialMatch';
        }

        return [$score, $mode];
    }
}
