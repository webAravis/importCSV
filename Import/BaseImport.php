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

use ImportCSV\ImportCSV;
use ImportCSV\Model\Db;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\Country;
use Thelia\Model\CountryI18nQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\CustomerTitle;
use Thelia\Model\CustomerTitleI18nQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Map\RewritingUrlTableMap;
use Thelia\Model\RewritingUrlQuery;

class BaseImport
{

    const CHUNK_SIZE = 100;

    protected $dispatcher;
    protected $thelia_version;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;

        $serviceContainer = Propel::getServiceContainer();

        $serviceContainer->setLogger('defaultLogger', Tlog::getInstance());
        $con = Propel::getConnection(ProductTableMap::DATABASE_NAME);
        $con->useDebug(true);
    }

    public function getChunkSize()
    {
        return self::CHUNK_SIZE;
    }

    public function preImport()
    {
    }

    public function import($startRecord = 0)
    {
        // Override this method, please.
    }

    public function postImport()
    {
    }

    private $currency_cache;

    /**
     * @param  bool $t1id
     * @return \Thelia\Model\Currency
     * @throws ImportException
     */
    public function getT2Currency($t1id = false)
    {
        if (!isset($this->currency_cache)) {

            if ($t1id !== false && $t1id > 0) {
                $obj = $this->t1db->query_obj("select * from devise where id=?", array($t1id));
            } else {
                try {
                    $obj = $this->t1db->query_obj("select * from devise where defaut=1");
                } catch (\Exception $ex) {
                    // Thelia 1.5.1, no default column
                    $obj = $this->t1db->query_obj("select * from devise order by id asc limit 1");
                }
            }

            if ($obj == false) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find the Thelia 1 currency %cur",
                        array('%cur' => $t1id === false ? 'Default' : "ID=$t1id"),
                        ImportCSV::DOMAIN
                    )
                );

            }

            $currency = CurrencyQuery::create()->findOneByCode(strtolower($obj->code));

            if ($currency === null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 lang for Thelia 1 lang code '%code'",
                        array("%code" => $obj->code),
                        ImportCSV::DOMAIN
                    )
                );
            }

            $this->currency_cache = $currency;
        }

        return $this->currency_cache;
    }

    private $lang_cache = array();

    /**
     * @param $id_lang_thelia_1
     * @return Lang
     * @throws ImportException
     */
    public function getT2Lang($id_lang_thelia_1)
    {
        $lang = null;

        if (!isset($this->lang_cache[$id_lang_thelia_1])) {

            if ($id_lang_thelia_1 > 0) {
                $obj = $this->t1db->query_obj("select * from lang where id=?", array($id_lang_thelia_1));
            } else {
                $obj = $this->t1db->query_obj("select * from lang order by id asc limit 1");
            }

            if ($obj == false || $obj == null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 1 lang for id '%id'",
                        array("%id" => $id_lang_thelia_1),
                        ImportCSV::DOMAIN
                    )
                );
            }

            if (isset($obj->code)) {
                $lang = LangQuery::create()->findOneByCode(strtolower($obj->code));
            } else // Thelia < 1.5.1
            {
                $lang = LangQuery::create()->filterByLocale("fr_FR")->findOneByTitle($obj->description);
            }

            if ($lang === null) {

                // If the lang id is not zero, create it in T2
                if ($id_lang_thelia_1 > 0) {

                    $lang = new Lang();

                    if (isset($obj->code)) {
                        $lang
                            ->setTitle($obj->description)
                            ->setCode($obj->code)
                            ->setLocale("$obj->code" . "_" . strtoupper($obj->code));;
                    } else {
                        $lang
                            ->setTitle("Imported Thelia lang $id_lang_thelia_1")
                            ->setCode("")
                            ->setLocale("");
                    }

                    $lang
                        ->setDatetimeFormat('d/m/Y H:i:s')
                        ->setDecimals(2)
                        ->setDecimalSeparator('.')
                        ->setThousandsSeparator(' ')
                        ->setTimeFormat('H:i:s')
                        ->setDateFormat('d/m/Y')
                        ->save();

                    Tlog::getInstance()->addInfo("Created Thelia 2 lang from Thelia 1 lang ID=$id_lang_thelia_1");

                }
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 lang for Thelia 1 lang id %id",
                        array("%id" => $id_lang_thelia_1),
                        ImportCSV::DOMAIN
                    )
                );
            }

            $this->lang_cache[$id_lang_thelia_1] = $lang;
        }

        return $this->lang_cache[$id_lang_thelia_1];
    }

    private $title_cache = array();

    /**
     * @param $id_title_thelia_1
     * @return CustomerTitle
     * @throws ImportException
     */
    public function getT2CustomerTitle($id_title_thelia_1)
    {
        $title = null;

        if (!isset($this->title_cache[$id_title_thelia_1])) {
            try {
                $obj = $this->t1db->query_obj(
                    "select * from raisondesc where raison=? limit 1",
                    array($id_title_thelia_1)
                );

                if ($obj == false) {
                    throw new ImportException(
                        Translator::getInstance()->trans(
                            "Failed to find a Thelia 1 customer title for id '%id'",
                            array("%id" => $id_title_thelia_1),
                            ImportCSV::DOMAIN
                        )
                    );
                }

                // Find the T1 object lang
                $lang = $this->getT2Lang($obj->lang);

                // Get the T2 title for this lang
                $title = CustomerTitleI18nQuery::create()->filterByLocale($lang->getLocale())->findOneByShort(
                    $obj->court
                );

                if ($title === null) {
                    throw new ImportException(
                        Translator::getInstance()->trans(
                            "Failed to find a Thelia 2 customer title for Thelia 1 short title '%title'",
                            array("%title" => $obj->court),
                            ImportCSV::DOMAIN
                        )
                    );
                }

            } catch (\Exception $ex) {
                if ($id_title_thelia_1 == 1 || $id_title_thelia_1 > 3) {
                    $title = CustomerTitleI18nQuery::create()->filterByLocale('fr_FR')->findOneByShort('Mme');
                } else {
                    if ($id_title_thelia_1 == 2) {
                        $title = CustomerTitleI18nQuery::create()->filterByLocale('fr_FR')->findOneByShort('Mlle');
                    } else {
                        if ($id_title_thelia_1 == 3) {
                            $title = CustomerTitleI18nQuery::create()->filterByLocale('fr_FR')->findOneByShort('M.');
                        }
                    }
                }
            }

            if ($title === null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 title for Thelia 1 title ID '%id'",
                        array("%id" => $id_title_thelia_1),
                        ImportCSV::DOMAIN
                    )
                );
            }

            $this->title_cache[$id_title_thelia_1] = $title;
        }

        return $this->title_cache[$id_title_thelia_1];
    }

    private $country_cache = array();

    /**
     * @param $id_country_thelia_1
     * @return Country
     * @throws ImportException
     */
    public function getT2Country($id_country_thelia_1)
    {
        if (!isset($this->country_cache[$id_country_thelia_1])) {

            $id = $id_country_thelia_1;

            $country = null;

            try {
                $obj = $this->t1db->query_obj("select isoalpha3 from pays where id=?", array($id_country_thelia_1));
            } catch (\Exception $ex) {
                $obj = false;
            }

            if ($obj == false) {

                $obj = $this->t1db->query_obj(
                    "select pays, titre from paysdesc where pays=? and lang=1",
                    array($id_country_thelia_1)
                );

                if ($obj == false) {
                    throw new ImportException(
                        Translator::getInstance()->trans(
                            "Failed to find a Thelia 1 country for id '%id'",
                            array("%id" => $id_country_thelia_1),
                            ImportCSV::DOMAIN
                        )
                    );
                }

                $id = $obj->pays;

                if (null === $countryI18n = CountryI18nQuery::create()->filterByLocale('fr_FR')->findOneByTitle(
                        "$obj->titre%"
                    )
                ) {
                    throw new ImportException(
                        Translator::getInstance()->trans(
                            "Failed to find a Thelia 1 country for '%title'",
                            array("%title" => $obj->titre),
                            ImportCSV::DOMAIN
                        )
                    );
                }

                $country = CountryQuery::create()->findPk($countryI18n->getId());
            } else {
                // Get the T2 country
                $country = CountryQuery::create()->findOneByIsoalpha3($obj->isoalpha3);
            }

            if ($country == null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 country for Thelia 1 country '%id'",
                        array("%id" => $id),
                        ImportCSV::DOMAIN
                    )
                );
            }

            $this->country_cache[$id_country_thelia_1] = $country;
        }

        return $this->country_cache[$id_country_thelia_1];
    }

    protected function updateRewrittenUrl($t2_object, $locale, $id_lang_t1, $fond_t1, $params_t1)
    {
        try {
            $t1_obj = $this->t1db->query_obj(
                "select * from reecriture where fond=? and param like? and lang=? and actif=1",
                array($fond_t1, "&$params_t1", $id_lang_t1)
            );
        }
        catch (\Exception $ex) {
            $t1_obj = false;
        }

        if ($t1_obj) {

            Tlog::getInstance()->info("Found rewritten URL $t1_obj->url for fond $fond_t1, with params $params_t1");

            try {
                // Delete all previous instance for the T2 object and for the rewritten URL
                // Also empty url rewriting table
                $con = Propel::getConnection(RewritingUrlTableMap::DATABASE_NAME);

                $con->exec('SET FOREIGN_KEY_CHECKS=0');

                RewritingUrlQuery::create()
                    ->filterByViewLocale($locale)
                    ->findByViewId($t2_object->getId())
                    ->delete();

                RewritingUrlQuery::create()
                    ->filterByViewLocale($locale)
                    ->findByUrl($t1_obj->url)
                    ->delete();

                $con->exec('SET FOREIGN_KEY_CHECKS=1');

                $t2_object->setRewrittenUrl($locale, $t1_obj->url);

                Tlog::getInstance()->info("Imported rewritten URL for locale $locale, $t1_obj->url");

            } catch (\Exception $ex) {
                Tlog::getInstance()
                    ->addError(
                        "Failed to create rewritten URL for locale $locale, fond $fond_t1, with params $params_t1: ",
                        $ex->getMessage()
                    );
            }
        } else {
            Tlog::getInstance()
                ->addNotice(
                    "No rewritten URL was found for locale $locale, fond '$fond_t1', with params '$params_t1', lang $id_lang_t1"
                );
        }
    }
}