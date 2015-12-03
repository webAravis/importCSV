<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia 1 Database Importation Tool                                           */
/*                                                                                   */
/*      Copyright (c) CQFDev                                                         */
/*      email : contact@cqfdev.fr                                                    */
/*      web : http://www.cqfdev.fr                                                   */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace ImportCSV\Import;

use Thelia\Core\FileFormat\Formatting\FormatterData;
use Thelia\Core\FileFormat\FormatType;
use Thelia\Model\AttributeCombination;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\CategoryImageQuery;
use Thelia\Model\CategoryDocumentQuery;
use Thelia\Model\CategoryAssociatedContentQuery;
use Thelia\Model\Currency;
use Thelia\Model\FeatureProduct;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeQuery;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\Feature;
use Thelia\Model\FeatureQuery;
use Thelia\Model\FeatureAv;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductDocument;
use Thelia\Model\ProductDocumentQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TemplateQuery;
use UnexpectedValueException;

class ImportCatalogue extends BaseImport
{
    private $product_corresp;
    private $tpl_corresp;
    private $tax_corresp;
    private $data;

    public function __construct() {
        
    }
    
    public function initData($content,$lang = 1,$formatter)
    {
        $this->data = $formatter
            ->decode($content)
            ->setLang($lang)
        ;
    }

    public function getChunkSize()
    {
        return 50;
    }

    public function getTotalCount()
    {
        if (isset($this->data)) {
            return count($this->data->getData());
        }
        return 0;
    }
    protected function checkMandatoryColumns(array $row)
    {
        $mandatoryColumns = $this->getMandatoryColumns();
        sort($mandatoryColumns);

        $diff = [];

        foreach ($mandatoryColumns as $name) {
            if (!isset($row[$name]) || empty($row[$name])) {
                $diff[] = $name;
            }
        }

        if (!empty($diff)) {
            throw new \UnexpectedValueException(
                "The following columns are missing: ".implode(", ", $diff)
            );
        }
    }

    public function import($startRecord = 0, $lang = "FR", $reset = 0)
    {
        $errors = [];
        $nbLevels = 3;
        $cpt = 0;
        
        if ($reset) {
            AttributeQuery::create()->deleteAll();
            AttributeAvQuery::create()->deleteAll();
            
            FeatureQuery::create()->deleteAll();
            FeatureAvQuery::create()->deleteAll();

            ProductImageQuery::create()->deleteAll();
            ProductDocumentQuery::create()->deleteAll();
            
            ProductQuery::create()->deleteAll();
            CategoryQuery::create()->deleteAll();

            CategoryImageQuery::create()->deleteAll();
            CategoryDocumentQuery::create()->deleteAll();

            CategoryAssociatedContentQuery::create()->deleteAll();

            ProductSaleElementsQuery::create()->deleteAll();
            ProductPriceQuery::create()->deleteAll();
        }
        
        $max = $startRecord+$this->getChunkSize();
        if ($max > $this->getTotalCount()) {
            $max = $this->getTotalCount();
        }
        
        for ($cpt = $startRecord; $cpt < $max; $cpt++){
            
            if (null !== $row = $this->data->getRow($cpt)) {
                try
                {
                    /**
                     * Check for mandatory columns
                     */
                    $this->checkMandatoryColumns($row);

                    // Creation de la structure du catalogue (catégories)
                    $this->createStructureCatalogue($nbLevels, $row);

                    // Création des marques
                    $this->createObjectIfNotExists($row['brand'], "Brand");

                    // Création des déclinaisons et valeurs de déclinaisons
                    $declinaisons = array();
                    $idDeclinaisons = array();
                    foreach ($row as $key => $value) {
                        if (strpos($key, 'dec_') === 0 && $value !== "") {
                            $declinaisons[$key] = $value;
                        }
                    }
                    foreach ($declinaisons as $nomDeclinaison => $valeurdeclinaison) {
                        $idDeclinaison = $this->createObjectIfNotExists($nomDeclinaison, "Attribute");

                        $idDeclinaisonValue = $this->createObjectValueIfNotExists($valeurdeclinaison, $this->findObjectByTitle($nomDeclinaison, "Attribute"), "Attribute");
                        $idDeclinaisons[$idDeclinaison] = $idDeclinaisonValue;
                    }

                    // Création des caractéristiques et valeurs de caractéristiques
                    $caracteristiques = array();
                    $idFeatures = array();
                    foreach ($row as $key => $value) {
                        if (strpos($key, 'carac_') === 0) {
                            $caracteristiques[$key] = $value;
                        }
                    }
                    foreach ($caracteristiques as $nomCaracteristique => $valeurCaracteristique) {
                        $idFeature = $this->createObjectIfNotExists($nomCaracteristique, "Feature");

                        $idFeatureValue = $this->createObjectValueIfNotExists($valeurCaracteristique, $this->findObjectByTitle($nomCaracteristique, "Feature"), "Feature");
                        $idFeatures[$idFeature] = $idFeatureValue;
                    }

                    // Création fiche produit de base                
                    $categoryProduct = 0;
                    for ($index = 0; $index < $nbLevels; $index++) {
                        $colonne = "rub".$index;
                        if ($row[$colonne] != "") {
                            $catParent = $categoryProduct;
                            $categoryProduct = "";

                            $title = utf8_encode($row[$colonne]);
                            $title = $this->normalizeTitle($title);
                            $title = strtoupper($title);

                            $tabTitle = explode(";", $title);
                            $tabCatParent = explode(";", $catParent);
                            $tabIdCat = [];
                            foreach ($tabCatParent as $idCatParent) {
                                foreach ($tabTitle as $title) {
                                    array_push($tabIdCat, $this->findObjectByTitle($title, "Category", $idCatParent));
                                }
                            }
                            $categoryProduct = implode(";", $tabIdCat);
                        }
                    }

                    $product_id = $this->createProductIfNotExists($row['title'], "Product", $categoryProduct, $row['price'], $row['brand'], $row['id']);

                    // Création ProductSaleElement
                    $this->createProductSaleElementIfNotExists($product_id, $idDeclinaisons, $row['price'], $row['promoprice'], $row['stock'], $row['id']);

                    $this->setFeaturesProduct($product_id, $idFeatures);
                } catch (UnexpectedValueException $ex)
                {
    //                echo 'Ex : '.$ex."<br />";
    //                array_push($errors, $ex);
                }
            }
        }

        return new ImportChunkResult($cpt, $errors);
    }
    
    
    
    
    protected function normalizeTitle ($string) {
        $table = array(
            'Š'=>'S', 'š'=>'s', 'Đ'=>'Dj', 'đ'=>'dj', 'Ž'=>'Z', 'ž'=>'z', 'Č'=>'C', 'č'=>'c', 'Ć'=>'C', 'ć'=>'c',
            'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
            'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
            'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss',
            'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e',
            'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o',
            'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'ý'=>'y', 'þ'=>'b',
            'ÿ'=>'y', 'Ŕ'=>'R', 'ŕ'=>'r',
        );

        return strtr($string, $table);
    }
    
    protected function deleteAllProductFeatures($product_id){
        $featureQuery = new FeatureProductQuery();
        $featureQuery->filterByProductId($product_id);
        $res = $featureQuery->find()->getData();
        foreach ($res as $featureProduct) {
            $featureProduct->delete();
        }
    }
    
    protected function setFeaturesProduct($product_id, $array_features){
        $this->deleteAllProductFeatures($product_id);
        
        foreach ($array_features as $featureId => $featureAvId) {
            $featureProduct = new FeatureProduct();
            $featureProduct->setProductId($product_id);
            $featureProduct->setFeatureId($featureId);
            $featureProduct->setFeatureAvId($featureAvId);

            $featureProduct->save();
        }
    }
    
    protected function findProductSaleElement($product_id, $pseref){
        $pseidReturn = -1;
        
        $pseQuery = new ProductSaleElementsQuery();
        $pseQuery->filterByProductId($product_id);
        $pseQuery->filterByRef($pseref);
        
        $res = $pseQuery->find()->getData();
        
        foreach ($res as $pse) {
            $pseidReturn = $pse->getId();
        }
        
        return $pseidReturn;
    }
    
    protected function createProductSaleElementIfNotExists($product_id, $array_combinations, $price, $promoprice, $quantity, $pseref){
        $pseid = $this->findProductSaleElement($product_id, $pseref);
        if ($pseid == -1) {
            $currency = Currency::getDefaultCurrency();
            
            $productQuery = new ProductQuery();
            $product = $productQuery->findPk($product_id);
            
            $pse = new ProductSaleElements();
            $pse->setProductId($product_id);
            $pse->setRef($pseref);
            $pse->setQuantity($quantity);
            $pse->setIsDefault(1);

            $pse->save();

            foreach ($array_combinations as $attributeId => $attributeAvId) {
                $attributeCombinations = new AttributeCombination();

                $attributeCombinations->setAttributeAvId($attributeAvId);
                $attributeCombinations->setAttributeId($attributeId);
                $attributeCombinations->setProductSaleElementsId($pse->getId());

                $attributeCombinations->save();
            }

            $pricequery = ProductPriceQuery::create()
                ->filterByProductSaleElementsId($pse->getId())
                ->findOneByCurrencyId($currency->getId())
            ;

            if ($pricequery === null) {
                $pricequery = new ProductPrice();

                $pricequery
                    ->setProductSaleElements($pse)
                    ->setCurrency($currency)
                ;
            }

            $pricequery->setPrice($price);
            $pricequery->setPromoPrice($promoprice);

            $pricequery->save();
        } else {
            // maj PSE
            $currency = Currency::getDefaultCurrency();
            $pseQuery = new ProductSaleElementsQuery();
            $pse = $pseQuery->findPk($pseid);
            
            $pse->setQuantity($quantity);
            
            $pricequery = ProductPriceQuery::create()
                ->filterByProductSaleElementsId($pse->getId())
                ->findOneByCurrencyId($currency->getId())
            ;

            if ($pricequery === null) {
                $pricequery = new ProductPrice();

                $pricequery
                    ->setProductSaleElements($pse)
                    ->setCurrency($currency)
                ;
            }

            $pricequery->setPrice($price);
            $pricequery->setPromoPrice($promoprice);

            $pricequery->save();
        }
    }
    
    protected function createObjectValueIfNotExists($value, $parent_pk, $object_name){
        $value = utf8_encode($value);
        $idObject = $this->findObjectValueByTitle($value, $object_name, $parent_pk);
        if ($idObject == -1) {
            $className = "Thelia\\Model\\".$object_name."Av";
            $objectValue = new $className();

            $objectValue->setLocale("fr_FR");
            $objectValue->setTitle($value);
            
            $funcName = "set".$object_name."Id";
            $objectValue->$funcName($parent_pk);
            $objectValue->save();
            $idObject = $objectValue->getId();
        }
        
        return $idObject;
    }
    
    protected function findObjectValueByTitle($title, $object_name, $parent_pk){
        $className = "Thelia\\Model\\".$object_name."AvQuery";
        $queryObjectValue = new $className();
        
        $funcName = "findBy".$object_name."Id";
        $res = $queryObjectValue->$funcName($parent_pk);
        foreach ($res as $objectValue) {
            $objectValue->setLocale("fr_FR");
            if ($objectValue->getTitle() == $title) {
                return $objectValue->getId();
            }
        }
        
        return -1;
    }
    
    protected function createProductIfNotExists($title, $object_name, $parent, $base_price, $brand_name, $reference){
        $tabParent = explode(";", $parent);
        
        $objectRef = $this->findObjectByTitle($title, $object_name, $tabParent[0]);
        if ($objectRef == -1) {
            $monProd = new Product();
            
            $monProd->create($tabParent[0], $base_price, 1, 1, 1, 1);
            $colPse = $monProd->getProductSaleElementss();
            foreach ($colPse as $pse){
                $pse->setQuantity(0);
            }
            $monProd->setLocale("fr_FR");
            $monProd->setTitle($title);
            $monProd->setBrandId($this->findObjectByTitle($brand_name, "Brand"));
            $monProd->setVisible(1);
            $monProd->setRef($reference);
            $monProd->setTemplateId(1);
            
            $objectRef = $monProd->getId();
            
            if (count($tabParent)>1) {
                foreach ($tabParent as $key => $parentCat) {
                    if ($key < 1) {
                        continue;
                    }
                    $categoryQuery = new CategoryQuery();
                    $monProd->addCategory($categoryQuery->findPk($parentCat));
                }
            }
            
            $monProd->save();
        } else {
            // mise à jour du produit
            $queryProd = new ProductQuery();
            $monProd = $queryProd->findPk($objectRef);
            
            $monProd->setLocale("fr_FR");
            $monProd->setTitle($title);
            $monProd->setBrandId($this->findObjectByTitle($brand_name, "Brand"));
            $monProd->save();
        }
        
        return $objectRef;
    }
    
    protected function createObjectIfNotExists($title, $object_name, $parent = 0) {
        $separator = "_";
        if (strpos($title, $separator) !== false) {
            $exploded_array = explode($separator, $title);
            array_shift($exploded_array);
            $title = join("_", $exploded_array);
        }
        $title = utf8_encode($title);
        $idObject = $this->findObjectByTitle($title, $object_name, $parent);
        if ($idObject == -1) {
            $className = "Thelia\\Model\\".$object_name;
            $object = new $className();
            
            $object->setLocale("fr_FR");
            $object->setTitle($title);
            
            if (!($object_name == "Feature" || $object_name == "Attribute")) {
                $object->setVisible(1);
            }

            if ($object_name == "Category") {
                $object->setParent($parent);
            }
            
            $object->save();
            $idObject = $object->getId();
            
            if ($object_name == "Feature" || $object_name == "Attribute") {
                $gabaritQuery = new TemplateQuery();
                $gabarit = $gabaritQuery->findPk(1);

                $funcName = "add".$object_name;
                $gabarit->$funcName($object);
                $gabarit->save();
            }  
        }
        
        return $idObject;
    }
    
    protected function findObjectByTitle($title, $object_name, $parent = 0){
        $separator = "_";
        if (strpos($title, $separator) !== false) {
            $exploded_array = explode($separator, $title);
            array_shift($exploded_array);
            $title = join("_", $exploded_array);
        }
        $title = utf8_encode($title);
        $className = "Thelia\\Model\\".$object_name."Query";
        $queryObject = new $className();
        
        $res = $queryObject->find()->getData();
        foreach ($res as $object) {
            $object->setLocale("fr_FR");
            if ($object->getTitle() == $title) {
                if ($object_name == "Category") {
                    if ($object->getParent() == $parent) {
                        return $object->getId();
                    }
                } else {
                    return $object->getId();
                }
            }
        }
        
        return -1;
    }
    
    protected function createStructureCatalogue($nbLevels, $row) {
        $parent = 0;
        for ($index = 0; $index < $nbLevels; $index++) {
            $tabRub = explode(";", $row["rub".$index]);
            $tabParent = [];
            foreach ($tabRub as $rubTitle) {
                $title = utf8_encode($rubTitle);
                $title = $this->normalizeTitle($title);
                $title = strtoupper($title);

                if (isset($title)&&!empty($title)) {
                    $tabParentTmp = explode(";", $parent);
                    foreach ($tabParentTmp as $parent) {
                        $this->createObjectIfNotExists($title, "Category", $parent);
                        array_push($tabParent,$this->findObjectByTitle($title, "Category", $parent));
                    }
                }
            }
            $parent = implode(";", $tabParent);
        }
    }

    protected function getMandatoryColumns()
    {
        return ["id", "rub0", "price", "stock", "brand", "title"];
    }

    /**
     * @return string|array
     *
     * Define all the type of import/formatters that this can handle
     * return a string if it handle a single type ( specific exports ),
     * or an array if multiple.
     *
     * Thelia types are defined in \Thelia\Core\FileFormat\FormatType
     *
     * example:
     * return array(
     *     FormatType::TABLE,
     *     FormatType::UNBOUNDED,
     * );
     */
    public function getHandledTypes()
    {
        return array(
            FormatType::TABLE,
            FormatType::UNBOUNDED,
        );
    }
}
