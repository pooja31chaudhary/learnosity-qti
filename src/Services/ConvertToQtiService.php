<?php

namespace LearnosityQti\Services;

use DOMDocument;
use DOMElement;
use Exception;
use LearnosityQti\Converter;
use LearnosityQti\Domain\JobDataTrait;
use LearnosityQti\Processors\IMSCP\Entities\File;
use LearnosityQti\Processors\IMSCP\Entities\ImsManifestMetadata;
use LearnosityQti\Processors\IMSCP\Entities\Manifest;
use LearnosityQti\Processors\IMSCP\Entities\Resource;
use LearnosityQti\Processors\QtiV2\Out\Constants as LearnosityExportConstant;
use LearnosityQti\Utils\General\CopyDirectoreyHelper;
use LearnosityQti\Utils\General\FileSystemHelper;
use LearnosityQti\Utils\UuidUtil;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;

class ConvertToQtiService
{
    use JobDataTrait;

    const RESOURCE_TYPE_ITEM = 'imsqti_item_xmlv2p1';
    const INFO_OUTPUT_PREFIX = '';
    const CONVERT_LOG_FILENAME = 'converttoqti.log';
    const MANIFEST_FILE_NAME = 'imsmanifest.xml';
    const IMS_CONTENT_PACKAGE_NAME = 'imsqti.zip';
    const SHARED_PASSAGE_FOLDER_NAME = 'sharedpassage';

    protected $inputPath;
    protected $outputPath;
    protected $output;
    protected $format;
    protected $organisationId;
    protected $itemReference;

    /* Runtime options */
    protected $dryRun                     = false;
    protected $shouldAppendLogs           = false;
    protected $shouldGuessItemScoringType = true;
    protected $shouldUseManifest          = true;

    /* Job-specific configurations */
    // Overrides identifiers to be the same as the filename
    protected $useFileNameAsIdentifier    = false;
    // Uses the identifier found in learning object metadata if available
    protected $useMetadataIdentifier      = true;
    // Resource identifiers sometimes (but not always) match the assessmentItem identifier, so this can be useful
    protected $useResourceIdentifier      = false;

    private $assetsFixer;
    protected static $instance = null;

    protected function __construct($inputPath, $outputPath, OutputInterface $output, $format, $organisationId = null)
    {
        $this->inputPath      = $inputPath;
        $this->outputPath     = $outputPath;
        $this->output         = $output;
        $this->format         = $format;
        $this->organisationId = $organisationId;
        $this->finalPath      = 'final';
        $this->logPath        = 'log';
        $this->rawPath        = 'raw';
        $this->itemReference = array();
    }
    
    // The object is created from within the class itself
    // only if the class has no instance.
    public static function initClass($inputPath, $outputPath, OutputInterface $output, $organisationId = null)
    {
        if (!self::$instance) {
            self::$instance = new ConvertToQtiService($inputPath, $outputPath, $output, $organisationId);
        }
        return self::$instance;
    }
    
    // Return instance of the class
    public static function getInstance()
    {
        return self::$instance;
    }
    
    public function getInputPath()
    {
        return $this->inputPath;
    }

    public function getFormat()
    {
        return $this->format;
    }

    public function process()
    {
        $errors = $this->validate();
        $result = [
            'status' => null,
            'message' => []
        ];

        if (!empty($errors)) {
            $result['status'] = false;
            $result['message'] = $errors;
            return $result;
        }

        // Setup output (or -o) subdirectories
        FileSystemHelper::createDirIfNotExists($this->outputPath . '/' . $this->finalPath);
        FileSystemHelper::createDirIfNotExists($this->outputPath . '/' . $this->logPath);
        FileSystemHelper::createDirIfNotExists($this->outputPath . '/' . $this->rawPath);

        $result = $this->parseContent();

        $this->tearDown();

        return $result;
    }
    
    /**
    * Decorate the IMS root element of the Manifest with the appropriate
    * namespaces and schema definition.
    *
    * @param DOMElement $rootElement The root DOMElement object of the document to decorate.
    */
    protected function decorateImsManifestRootElement(DOMElement $rootElement)
    {
        $xsdLocation = 'http://www.imsglobal.org/xsd/imscp_v1p1 http://www.imsglobal.org/xsd/qti/qtiv2p1/qtiv2p1_imscpv1p2_v1p0.xsd http://www.w3.org/Math/XMLSchema/mathml2/mathml2.xsd';
        $xmlns = "http://www.imsglobal.org/xsd/imscp_v1p1";
        $rootElement->setAttribute('xmlns', $xmlns);
        $rootElement->setAttribute("xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance");
        $rootElement->setAttribute("xsi:schemaLocation", $xsdLocation);
		
    }

    /**
     * Performs a conversion on each directory (one level deep)
     * inside the given source directory.
     */
    private function parseContent()
    {
        $results = [];
        $jsonFiles = $this->parseInputFolders();
        
        $finalManifest = $this->getJobManifestTemplate();
        $this->output->writeln("<info>" . static::INFO_OUTPUT_PREFIX . "Processing JSON directory: {$this->inputPath} </info>");

        foreach ($jsonFiles as $file) {
            if (file_exists($file)) {
                $results[] = $this->convertLearnosityInDirectory($file);
            } else {
                $this->output->writeln("<info>" . static::INFO_OUTPUT_PREFIX . "Learnosity JSON file ".basename($file). " Not found in: {$this->inputPath}/items </info>");
            }
        }
        $resourceInfo = $this->updateJobManifest($finalManifest, $results);
        $finalManifest->setResources($resourceInfo);
        $this->persistResultsFile($results, realpath($this->outputPath) . '/' . $this->rawPath . '/');
        $this->flushJobManifest($finalManifest, $results);
        CopyDirectoreyHelper::copyFiles(realpath($this->inputPath) . '/assets', realpath($this->outputPath) . '/' . $this->rawPath . '/assets');
        $this->createIMSContntPackage(realpath($this->outputPath) . '/' . $this->rawPath . '/');
    }

    /**
     * Performs a conversion on QTI content packages found in the given root source directory.
     *
     * @param  string $sourceDirectory
     * @param  string $relativeSourceDirectoryPath
     *
     * @return array - the results of the conversion
     */
    private function convertLearnosityInDirectory($file)
    {
        $this->output->writeln("<comment>Converting Learnosity JSON {$file}</comment>");
        return $this->convertAssessmentItem(json_decode(file_get_contents($file), true));
    }

    
	// Traverse the -i option and find all paths with files
    private function parseInputFolders()
    {
        $folders = [];
        // Look for json files in the current path
        $finder = new Finder();
        $finder->files()->in($this->inputPath . '/activities');
        if ($finder->count() > 0) {
            foreach ($finder as $json) {
                $activityJson = json_decode(file_get_contents($json));
                $this->itemReferences = $activityJson->data->items;
                if (!empty($this->itemReferences)) {
                    foreach ($this->itemReferences as $itemref) {
                        $itemref = md5($itemref);
                        $folders[] = $this->inputPath . '/items/' . $itemref . '.json';
                    }
                } else {
                    $this->output->writeln("<error>Error converting : No item refrences found in the activity json</error>");
                }
            }
        } else {
            $finder->files()->in($this->inputPath . '/items');
            foreach ($finder as $json) {
                $folders[] = $this->inputPath . '/items/' . $json->getRelativePathname();
            }
        }
        return $folders;
    }

    /**
     * Converts Learnosity JSON to QTI
     *
     * @param  string $jsonString
     *
     * @return array - the results of the conversion
     *
     * @throws Exception - if the conversion fails
     */
    private function convertAssessmentItem($json)
    {
        $result = [];
        $finalXml = [];
        $content = $json['content'];
        $features = $json['features'];
        if (is_array($features) && sizeof($features) > 0) {
            $this->createSharedPassageFolder($this->outputPath . '/' . $this->rawPath);
        }
        
        if (!empty($json['questions']) && (sizeof($features)>=1)) {

            $referenceArray = $this->getReferenceArray($json);
            foreach ($json['questions'] as $question) {

                $question['content'] = $content;
                $featureReference = $this->getFeatureReference($question['reference'], $referenceArray);
                if ($featureReference != "") {
                    $question['feature'] = $this->getFeature($featureReference, $features);
                } else {
                    $question['feature'] = [];
                }

                if (in_array($question['data']['type'], LearnosityExportConstant::$supportedQuestionTypes)) {
                    $result = Converter::convertLearnosityToQtiItem($question);
                    $result[0] = str_replace('/vendor/learnosity/itembank/', '', $result[0]);
                    $finalXml['questions'][] = $result;
                } else {
                    $result = [
                        '',
                        [
                            'Ignoring' . $question['data']['type'] . ' , currently unsupported'
                        ]
                    ];
                    $this->output->writeln("<error>Question type `{$question['data']['type']}` not yet supported, ignoring</error>");
                }
            }
        }

        if (!empty($json['questions']) && empty($json['features'])) {

            foreach ($json['questions'] as $question) {
                $question['content'] = $content;
                $question['feature'] = [];
                if (in_array($question['data']['type'], LearnosityExportConstant::$supportedQuestionTypes)) {
                    $result = Converter::convertLearnosityToQtiItem($question);
                    $result[0] = str_replace('/vendor/learnosity/itembank/', '', $result[0]);
                    $finalXml['questions'][] = $result;
                } else {
                    $result = [
                        '',
                        [
                            'Ignoring' . $question['data']['type'] . ' , currently unsupported'
                        ]
                    ];
                    $this->output->writeln("<error>Question type `{$question['data']['type']}` not yet supported, ignoring</error>");
                }
            }
        }

        if (!empty($json['features']) && empty($json['questions'])) {
            foreach ($json['features'] as $feature) {
                $feature['content'] = $content;
                if (in_array($feature['data']['type'], LearnosityExportConstant::$supportedFeatureTypes)) {
                    $result = Converter::convertLearnosityToQtiItem($feature);
                    $result[0] = str_replace('/vendor/learnosity/itembank/', '', $result[0]);
                    $finalXml['features'][] = $result;
                } else {
                    $result = [
                        '',
                        [
                            'Ignoring' . $feature['data']['type'] . ' , currently unsupported'
                        ]
                    ];
                    $this->output->writeln("<error>Feature type `{$feature['data']['type']}` not yet supported, ignoring</error>");
                }
            }
        }
        return [
            'qti' => $finalXml,
            'json' => $json
        ];
    }

     /**
     * Flush and write the given job manifest.
     *
     * @param array $manifest
     */
    private function flushJobManifest(Manifest $manifestContent, array $results)
    {
        $manifestFileBasename = static::MANIFEST_FILE_NAME;
        $imsManifestXml = new DOMDocument("1.0", "UTF-8");
        $imsManifestXml->formatOutput = true;
        $element = $imsManifestXml->createElement("manifest");
        $element->setAttribute("identifier", $manifestContent->getIdentifier());
        $imsManifestXml->appendChild($element);
        
        $manifestMetaData = $this->addManifestMetadata($manifestContent, $imsManifestXml);
        $element->appendChild($manifestMetaData);
        
        $organization = $this->addOrganizationInfoInManifest($manifestContent, $imsManifestXml);
        $element->appendChild($organization);
        
        $resourceInfo = $this->addResourceInfoInManifest($manifestContent, $imsManifestXml);
        $element->appendChild($resourceInfo);
        
        $this->decorateImsManifestRootElement($element);
        $xml = $imsManifestXml->saveXML();
        $outputFilePath = realpath($this->outputPath) . '/' . $this->rawPath . '/';
        file_put_contents($outputFilePath . '/' . $manifestFileBasename, $xml);
    }
    
    private function createIMSContntPackage($contentDirPath)
    {
        // Get real path for our folder
        $rootPath = $contentDirPath;
        
        // Initialize archive object
        $zip = new ZipArchive();
        $zip->open($contentDirPath.'/'. self::IMS_CONTENT_PACKAGE_NAME, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // Create recursive directory iterator
        /** @var SplFileInfo[] $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath, true), RecursiveIteratorIterator::LEAVES_ONLY);
          
        foreach ($files as $name => $file) {
            // Skip directories (they would be added automatically)
            if (!$file->isDir()) {
                // Get real and relative path for current file
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath));

                // Add current file to archive
                $zip->addFile($filePath, $relativePath);
            }
        }

        //Zip archive will be created only after closing object
        $zip->close();
    }

    private function addManifestMetadata(Manifest $manifestContent, DOMDocument $imsManifestXml)
    {
        $manifestMetadata = $imsManifestXml->createElement("metadata");
        $manifestMetadataContent = $manifestContent->getImsManifestMetaData();
        $schema = $imsManifestXml->createElement("schema", $manifestMetadataContent->getSchema());
        $manifestMetadata->appendChild($schema);
        
        $schemaVersion = $imsManifestXml->createElement("schemaversion", $manifestMetadataContent->getSchemaversion());
        $manifestMetadata->appendChild($schemaVersion);
        
        return $manifestMetadata;
    }
    
    private function addOrganizationInfoInManifest(Manifest $manifestContent, DOMDocument $imsManifestXml)
    {
        $organization = $imsManifestXml->createElement("organizations");
        return $organization;
    }
    
    private function addResourceInfoInManifest(Manifest $manifestContent, DOMDocument $imsManifestXml)
    {
        $resources = $imsManifestXml->createElement("resources");
        $resourcesContent = $manifestContent->getResources();
        foreach ($resourcesContent[0] as $resourceContent) {
            //$resourceContent = $resourceContent[0];
            $resource = $imsManifestXml->createElement("resource");
            $resource->setAttribute("identifier", $resourceContent->getIdentifier());
            $resource->setAttribute("type", $resourceContent->getType());
            $resource->setAttribute("href", $resourceContent->getHref());
            $metadara = $imsManifestXml->createElement("metadata");
            $resource->appendChild($metadara);
            $filesData = $resourceContent->getFiles();
            foreach ($filesData as $fileContent) {
                $file = $imsManifestXml->createElement("file");
                $file->setAttribute("href", $fileContent->getHref());
                $resource->appendChild($file);
            }
            $resources->appendChild($resource);
        }
        return $resources;
    }
    
    /**
     * Returns the base template for job manifests consumed by this job.
     *
     * @return array
     */
    private function getJobManifestTemplate()
    {
        $manifest = new Manifest();
        $manifest->setIdentifier('i'.UuidUtil::generate());
        $manifest->setImsManifestMetaData($this->createImsManifestMetaData());
        return $manifest;
    }
    
    private function createImsManifestMetaData()
    {
        $manifestMetaData = new ImsManifestMetadata();
        $manifestMetaData->setSchema("QTI2.1 Content");
        $manifestMetaData->setSchemaversion("2.1");
        $manifestMetaData->setTitle("QTI 2.1 Conversion Data");
        return $manifestMetaData;
    }
    
    private function createSharedPassageFolder($path)
    {
        // Desired folder structure
        $structure = $path."//".self::SHARED_PASSAGE_FOLDER_NAME;

        // To create the nested structure, the $recursive parameter
        // to mkdir() must be specified.
        if (!file_exists($structure)) {
            mkdir($structure, 0777, true);
        } else {
            $this->output->writeln("<error>sharedpassage directorey already created.</error>");
        }
    }

    /**
     * Writes a given results file to the specified output path.
     *
     * @param array  $results
     * @param string $outputFilePath
     */
    private function persistResultsFile(array $results, $outputFilePath)
    {
        if ($this->dryRun) {
            return;
        }

        $this->output->writeln("\n<info>" . static::INFO_OUTPUT_PREFIX . "Writing conversion results: " . $outputFilePath . '.json' . "</info>\n");
        
        foreach ($results as $result) {
            if (!empty($result['qti'])) {
                if (!empty($result['json']['questions'])) {
                    foreach ($result['qti']['questions'] as $key => $value) {
                        file_put_contents($outputFilePath . '/' . $result['json']['questions'][$key]['reference'] . '.xml', $value[0]);
                    }
                }

                if (!empty($result['json']['features']) && empty($result['json']['questions'])) {
                    foreach ($result['qti']['features'] as $key => $value) {
                        file_put_contents($outputFilePath . '/' . $result['json']['features'][$key]['reference'] . '.xml', $value[0]);
                    }
                }
            }
        }
    }
    
    /**
     * Updates a given job manifest in place with the contents of a specified
     * job partial result object.
     *
     * @param array $manifest - the job manifest to update
     * @param array $results  - the partial job result object to read
     */
    private function updateJobManifest(Manifest $manifest, array $results)
    {
        $resourcesArray = array();
        $additionalFileReferenceInfo = $this->getAdditionalFileInfoForManifestResource($results);
        foreach ($results as $result) {
            if (!empty($result['json']['questions'])) {
                $resourcesArray[] = $this->addQuestionReference($result['json']['questions'], $result, $additionalFileReferenceInfo);
            }

            if (!empty($result['json']['features']) && empty($result['json']['questions'])) {
                $resourcesArray[] = $this->addFeatureReference($result['json']['features'], $result, $additionalFileReferenceInfo);
            }
        }
        return $resourcesArray;
    }

    private function addQuestionReference($questions, $result, $additionalFileReferenceInfo)
    {
        $resources = array();
        if (!empty($result['qti']['questions'])) {
            foreach ($result['qti']['questions'] as $question) {
                $files = array();
                $resource = new Resource();
                $resource->setIdentifier('i'.$question['2']);
                $resource->setType(Resource::TYPE_PREFIX_ITEM."xmlv2p1");
                $resource->setHref($question['2'].".xml");
                if (array_key_exists($question['2'], $additionalFileReferenceInfo)) {
                    $files = $this->addAdditionalFileInfo($additionalFileReferenceInfo[$question['2']], $files);
                }
                if (!empty($question['3']) && array_key_exists($question['2'], $question['3'])) {
                    $files = $this->addFeatureHtmlFilesInfo($question['3'][$question['2']], $files);
                }
                if (!empty($question['3']) && array_key_exists('features', $question['3'])) {
                    $files = $this->addAdditionalFileInfo($additionalFileReferenceInfo[$question['3']['features']], $files);
                }

                $file = new File();
                $file->setHref($question['2'].".xml");
                $files[] = $file;
                $resource->setFiles($files);
                $resources[] = $resource;
            }
        }
        return $resources;
    }

    private function addFeatureReference($features, $result, $additionalFileReferenceInfo)
    {
        $resources = array();
        foreach ($features as $feature) {
            if (!empty($result['qti'])) {
                $files = array();
                $resource = new Resource();
                $resource->setIdentifier('i'.$feature['reference']);
                $resource->setType(Resource::TYPE_PREFIX_ITEM."xmlv2p1");
                $resource->setHref($feature['reference'].".xml");
                if (array_key_exists($feature['reference'], $additionalFileReferenceInfo)) {
                    $files = $this->addAdditionalFileInfo($additionalFileReferenceInfo[$feature['reference']], $files);
                }
                
                $file = new File();
                $file->setHref($feature['reference'].".xml");
                $files[] = $file;
                $resource->setFiles($files);
                $resources[] = $resource;
            }
        }
        return $resources;
    }
    
    private function addFeatureHtmlFilesInfo($featureHtmlArray, array $files)
    {
        foreach ($featureHtmlArray as $featureId => $featureHtml) {
            if (file_put_contents($this->outputPath . '/' . $this->rawPath . '/' . self::SHARED_PASSAGE_FOLDER_NAME . '/' . $featureId . '.html', $featureHtml)) {
                $file = new File();
                $file->setHref(self::SHARED_PASSAGE_FOLDER_NAME . '/' . $featureId . '.html');
                $files[] = $file;
            }
        }
        return $files;
    }

    private function addFeatureFilesInfo($featureArray, array $files)
    {
        foreach ($featureHtmlArray as $featureId => $featureHtml) {
            if (file_put_contents($this->outputPath . '/' . $this->rawPath . '/' . self::SHARED_PASSAGE_FOLDER_NAME . '/' . $featureId . '.html', $featureHtml)) {
                $file = new File();
                $file->setHref(self::SHARED_PASSAGE_FOLDER_NAME . '/' . $featureId . '.html');
                $files[] = $file;
            }
        }
        return $files;
    }
    
    private function addAdditionalFileInfo($filesInfo, $files)
    {
        foreach ($filesInfo as $id => $info) {
            $file = new File();
            $href = substr($info, strlen('/vendor/learnosity/itembank/'));
            $file->setHref($href);
            $files[] = $file;
        }
        return $files;
    }
    
    private function getAdditionalFileInfoForManifestResource(array $results)
    {
        $itemsReferenceArray = $this->itemReference;
        $learnosityManifestJson = json_decode(file_get_contents($this->inputPath. '/manifest.json'));
        $additionalFileInfoArray = array();
        if (isset($learnosityManifestJson->assets->items)) {
            $activityArray = $learnosityManifestJson->assets->items;
            foreach ($activityArray as $itemReference => $itemValue) {
                $questionArray = $itemValue;

                if (isset($questionArray->questions) && is_object($questionArray->questions)) {
                    foreach ($questionArray->questions as $questionKey => $questionValue) {
                        $valueArray = array();
                        foreach ($questionValue as $value) {
                            $valueArray[] = $value->replacement;
                        }
                        $additionalFileInfoArray[$questionKey] = $valueArray;
                    }
                }
                if (isset($questionArray->features) && is_object($questionArray->features)) {
                    foreach ($questionArray->features as $featureKey => $featureValue) {
                        $valueArray = array();
                        foreach ($featureValue as $value) {
                            if (isset($value->replacement)) {
                                $valueArray[] = $value->replacement;
                            }
                        }
                        $additionalFileInfoArray[$featureKey] = $valueArray;
                    }
                }
            }
        }
        
        return $additionalFileInfoArray;
    }

    private function tearDown()
    {
    }

    private function validate()
    {
        $errors = [];
        $jsonFolders = $this->parseInputFolders();
        if (empty($jsonFolders)) {
            array_push($errors, 'No files found in ' . $this->inputPath);
        }

        return $errors;
    }

    private function getReferenceArray($json)
    {
        $content = strip_tags($json['content'], "<span>");
        $contentArr = explode('</span>', $content);
        $referenceArr = [];
        for ($i=0; $i< sizeof($contentArr); $i++) {

            if (strpos($contentArr[$i], 'feature')) {
                $featureReference = trim(str_replace('<span class="learnosity-feature feature-', '', $contentArr[$i]));
                $featureReference = trim(str_replace('">', "", $featureReference));
            }
                       
            if (strpos($contentArr[$i], 'question')) {
                $questionReference = trim(str_replace('<span class="learnosity-response question-', '', $contentArr[$i]));
                $questionReference = trim(str_replace('">', "", $questionReference));
                $referenceArr[$i]['questionReference'] = $questionReference;
                if (isset($featureReference)) {
                    $referenceArr[$i]['featureReference'] = $featureReference;
                }
            }
        }
        return $referenceArr;
    }

    private function getFeatureReference($questionReference, $referenceArray)
    {
        $featureReference = '';
        foreach ($referenceArray as $reference) {
            if ($questionReference == $reference['questionReference']) {
                if (isset($reference['featureReference'])) {
                    $featureReference = $reference['featureReference'];
                    continue;
                }
            }
        }
        return $featureReference;
    }

    private function getFeature($featureReference, $features)
    {
        $featureArray = [];
        foreach ($features as $feature) {
            if ($feature['reference'] == $featureReference) {
                $featureArray[] = $feature;
            }
        }

        return $featureArray;
    }
}
