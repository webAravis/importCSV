<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace ImportCSV\Form;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\ExecutionContextInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Model\LangQuery;
use Thelia\Form\BaseForm;

/**
 * Class ImportForm
 * @package Thelia\Form
 * @author Benjamin Perche <bperche@openstudio.fr>
 */
class ImportCSVForm extends BaseForm
{
    protected function buildForm()
    {
        $this->formBuilder
            ->add("file_upload", "file", array(
            "label" => $this->translator->trans("File to upload"),
            "label_attr" => ["for" => "file_to_upload"],
            "required" => true,
            ))
            ->add("url_file", "hidden", array(
            "label" => "url_file"
            ))
            ->add("name_file", "hidden", array(
            "label" => "name_file"
            ))
            ->add("language", "integer", array(
                "label" => $this->translator->trans("Language"),
                "label_attr" => ["for" => "language"],
                "required" => true,
                "constraints" => [
                    new Callback([
                        "methods" => [
                            [$this, "checkLanguage"],
                        ],
                    ]),
                ],
            ))
            ->add("reset_catalog", "integer", array(
                "label" => $this->translator->trans("Reset catalog"),
                "label_attr" => ["for" => "reset_catalog"],
                "required" => true
            ))
        ;
    }

    /**
     * @return string the name of you form. This name must be unique
     */
    public function getName()
    {
        return "thelia_importCSV";
    }

    public function checkLanguage($value, ExecutionContextInterface $context)
    {
        if (null === LangQuery::create()->findPk($value)) {
            $context->addViolation(
                $this->translator->trans(
                    "The language \"%id\" doesn't exist",
                    [
                        "%id" => $value
                    ]
                )
            );
        }
    }
}
