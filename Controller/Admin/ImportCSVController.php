<?php
namespace ImportCSV\Controller\Admin;

use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\FileFormat\Archive\AbstractArchiveBuilder;
use Thelia\Core\FileFormat\Archive\ArchiveBuilderManager;
use Thelia\Core\FileFormat\Archive\ArchiveBuilderManagerTrait;
use Thelia\Core\FileFormat\Formatting\AbstractFormatter;
use Thelia\Core\FileFormat\Formatting\FormatterManager;
use Thelia\Core\FileFormat\Formatting\FormatterManagerTrait;
use ImportCSV\Import\ImportCatalogue;
use Thelia\Form\Definition\AdminForm;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Loop\Import as ImportLoop;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Thelia\Form\Exception\FormValidationException;

class ImportCSVController extends BaseAdminController
{
    const RESOURCE_CODE = 'module.ImportCSV';
    use ArchiveBuilderManagerTrait;
    use FormatterManagerTrait;
    protected $total = 0;


    public function __construct(){
        $this->setCurrentRouter("router.ImportCSV");
    }
    
        /**
     * @param  integer  $id
     * @return Response
     *
     * This method is called when the route /admin/import/{id}
     * is called with a POST request.
     */
    public function import($start)
    {
        $form = $this->createForm("ImportCSVForm");
        $errorMessage = null;
        $successMessage = null;
        $file;
        $langData = "";
        try {
            $archiveBuilderManager = $this->getArchiveBuilderManager($this->container);
            $formatterManager = $this->getFormatterManager($this->container);
            $handler = new ImportCatalogue();
            
            $boundForm = $this->validateForm($form);

            $reset = $boundForm->get("reset_catalog")->getData();
            
            if ($boundForm->has("url_file") && $boundForm->get("url_file")->getData() != "") {
                $file = new UploadedFile($boundForm->get("url_file")->getData(),$boundForm->get("name_file")->getData());
            } else {
                $file = $boundForm->get("file_upload")->getData();
            }
            
            $langData = $boundForm->get("language")->getData();
            $lang = LangQuery::create()->findPk(
                $boundForm->get("language")->getData()
            );
            /**
             * We have to check the extension manually because of composed file formats as tar.gz or tar.bz2
             */
            $name = $file->getClientOriginalName();

            $tools = $this->retrieveFormatTools(
                $name,
                $handler,
                $formatterManager,
                $archiveBuilderManager
            );

            /** @var AbstractFormatter $formatter */
            $formatter = $tools["formatter"];
            
            $content = file_get_contents($file->getPathname());
            $handler->initData($content,$lang, $formatter);
            $this->total = $handler->getTotalCount();

            /**
             * Process the import: dispatch events, format the file content and let the handler do it's job.
             */
            $handler->import($start, $lang, $reset);
            
        } catch (FormValidationException $e) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($e);
        }

        if ($successMessage !== null) {
            $this->getParserContext()->set("success_message", $successMessage);
        }

        if ($errorMessage !== null) {
            $form->setErrorMessage($errorMessage);

            $this->getParserContext()
                ->addForm($form)
                ->setGeneralError($errorMessage)
            ;
        }
        $next_start = $start + $handler->getChunkSize();
        $remaining = max(0, $this->total - $next_start);

        $fs = new Filesystem();
        $nameFile = $file->getClientOriginalName();
        $path = "cache/";
        
        if (!$fs->exists($path.$nameFile)) {
            $file->move($path,$nameFile);
        }
        
        
        if ($remaining === 0) {
            $fs->remove($path.$nameFile);
        }
        
        return $this->render(
            'importerCSV',
            array(
                'title' => "Import Catalogue",
                'chunk_size' => $handler->getChunkSize(),
                'total' => $this->total,
                'errors' => "",
                'file_import' => $path.$nameFile,
                'file_name' => $nameFile,
                'start' => $next_start,
                'remaining' => $remaining,
                'reload' => $remaining > 0,
                'next_route' => $this->getRoute("importCSV.done", array('total_errors' => 0)),
                'startover_route' =>  $this->getRoute("importCSV.import", array('start' => 0)),
                'messages' => '',
                'lang' => $langData
            )
        );
    }
    
    public function importDoneAction(){
         
    }
    
    public function indexAction()
    {
        if (null !== $response = $this->checkAuth(self::RESOURCE_CODE, array(), AccessManager::VIEW)) {
            return $response;
        }

        // Render the edition template.
        return $this->render('welcomeImportCSV');
    }
    
    public function retrieveFormatTools(
        $fileName,
        ImportCatalogue $handler,
        FormatterManager $formatterManager,
        ArchiveBuilderManager $archiveBuilderManager
    ) {
        $nameLength = strlen($fileName);

        $types = $handler->getHandledTypes();

        $formats =
            $formatterManager->getExtensionsByTypes($types, true) +
            $archiveBuilderManager->getExtensions(true)
        ;

        $uploadFormat = null;

        /** @var \Thelia\Core\FileFormat\Formatting\AbstractFormatter $formatter */
        $formatter = null;

        /** @var \Thelia\Core\FileFormat\Archive\AbstractArchiveBuilder $archiveBuilder */
        $archiveBuilder = null;

        foreach ($formats as $objectName => $format) {
            $formatLength = strlen($format);
            $formatExtension = substr($fileName, -$formatLength);

            if ($nameLength >= $formatLength  && $formatExtension === $format) {
                $uploadFormat = $format;


                try {
                    $formatter = $formatterManager->get($objectName);
                } catch (\OutOfBoundsException $e) {
                }

                try {
                    $archiveBuilder = $archiveBuilderManager->get($objectName);
                } catch (\OutOfBoundsException $e) {
                }

                break;
            }
        }

        $this->checkFileExtension($fileName, $uploadFormat);

        return array(
            "formatter" => $formatter,
            "archive_builder" => $archiveBuilder,
            "extension" => $uploadFormat,
            "types" => $types,
        );
    }

    public function checkFileExtension($fileName, $uploadFormat)
    {
        if ($uploadFormat === null) {
            $splitName = explode(".", $fileName);
            $ext = "";

            if (1 < $limit = count($splitName)) {
                $ext = "." . $splitName[$limit-1];
            }

            throw new FormValidationException(
                $this->getTranslator()->trans(
                    "The extension \"%ext\" is not allowed",
                    [
                        "%ext" => $ext
                    ]
                )
            );
        }
    }
}
