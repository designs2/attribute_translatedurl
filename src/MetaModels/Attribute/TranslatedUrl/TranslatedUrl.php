<?php

/**
 * The MetaModels extension allows the creation of multiple collections of custom items,
 * each with its own unique set of selectable attributes, with attribute extendability.
 * The Front-End modules allow you to build powerful listing and filtering of the
 * data in each collection.
 *
 * PHP version 5
 *
 * @package    MetaModels
 * @subpackage AttributeTranslatedUrl
 * @author     Oliver Hoff <oliver@hofff.com>
 * @author     Andreas Isaak <info@andreas-isaak.de>
 * @author     Christopher Boelter <christopher@boelter.eu>
 * @copyright  The MetaModels team.
 * @license    LGPL.
 * @filesource
 */

namespace MetaModels\Attribute\TranslatedUrl;

use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\ManipulateWidgetEvent;
use MetaModels\Attribute\TranslatedReference;
use MetaModels\DcGeneral\Events\UrlWizardHandler;

/**
 * Handle the translated url attribute.
 *
 * @package    MetaModels
 * @subpackage AttributeTranslatedUrl
 */
class TranslatedUrl extends TranslatedReference
{

    /**
     * {@inheritdoc}
     */
    public function getFilterUrlValue($value)
    {
        return htmlencode(serialize($value));
    }

    /**
     * {@inheritdoc}
     */
    public function getAttributeSettingNames()
    {
        return array_merge(parent::getAttributeSettingNames(), array(
            'no_external_link',
            'mandatory',
            'trim_title'
        ));
    }

    /**
     * {@inheritdoc}
     */
    protected function getValueTable()
    {
        return 'tl_metamodel_translatedurl';
    }

    /**
     * {@inheritdoc}
     */
    public function valueToWidget($value)
    {
        if ($this->get('trim_title')) {
            return $value['href'];
        } else {
            return array($value['title'], $value['href']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function widgetToValue($value, $idValue)
    {
        if ($this->get('trim_title')) {
            return array('href' => $value);
        } else {
            return array_combine(array('title', 'href'), $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getFieldDefinition($overrides = array())
    {
        $arrFieldDef = parent::getFieldDefinition($overrides);

        $arrFieldDef['inputType'] = 'text';
        if (!isset($arrFieldDef['eval']['tl_class'])) {
            $arrFieldDef['eval']['tl_class'] = '';
        }
        $arrFieldDef['eval']['tl_class'] .= ' wizard inline';

        if (!$this->get('trim_title')) {
            $arrFieldDef['eval']['size']      = 2;
            $arrFieldDef['eval']['multiple']  = true;
            $arrFieldDef['eval']['tl_class'] .= ' metamodelsattribute_url';
        }

        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = $this->getMetaModel()->getServiceContainer()->getEventDispatcher();
        $dispatcher->addListener(
            ManipulateWidgetEvent::NAME,
            array(new UrlWizardHandler($this->getMetaModel(), $this->getColName()), 'getWizard')
        );

        return $arrFieldDef;
    }

    /**
     * {@inheritdoc}
     */

    public function getFilterOptions($ids, $usedOnly, &$count = null)
    {
        // not supported
        return array();
    }

    /**
     * {@inheritdoc}
     */
    public function searchForInLanguages($pattern, $languages = array())
    {
        $pattern   = str_replace(array('*', '?'), array('%', '_'), $pattern);
        $joinTable = $this->getValueTable();

        $languages = (array) $languages;
        if ($languages) {
            $languageWildcards = self::generateWildcards($languages);
            $languageCondition = 'AND language IN (' . $languageWildcards . ')';
        }

        $sql = <<<SQL
SELECT	DISTINCT item_id AS id
FROM	$joinTable
WHERE	(title LIKE ? OR href LIKE ?)
AND		att_id = ?
$languageCondition
SQL;

        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $this->get('id');
        $params   = array_merge($params, $languages);

        $result = \Database::getInstance()->prepare($sql)->executeUncached($params);

        return $result->fetchEach('id');
    }

    /**
     * {@inheritdoc}
     */
    public function sortIds($ids, $direction)
    {
        $ids = (array) $ids;

        if (count($ids) < 2) {
            return $ids;
        }

        $modelTable                         = $this->getMetaModel()->getTableName();
        $joinTable                          = $this->getValueTable();
        $direction  == 'DESC' || $direction = 'ASC';

        $idWildcards = self::generateWildcards($ids);
        $sql         = <<<SQL
SELECT		_model.id
FROM		$modelTable		AS _model

LEFT JOIN	$joinTable		AS _active		ON _active.item_id = _model.id
											AND _active.att_id = ?
											AND _active.language = ?

LEFT JOIN	$joinTable		AS _fallback	ON _active.item_id IS NULL
											AND _fallback.item_id = _model.id
											AND _fallback.att_id = ?
											AND _fallback.language = ?

WHERE		_model.id IN ($idWildcards)

ORDER BY	COALESCE(_active.title, _active.href, _fallback.title, _fallback.href) $direction,
			COALESCE(_active.href, _fallback.href) $direction
SQL;

        $params[] = $this->get('id');
        $params[] = $this->getMetaModel()->getActiveLanguage();
        $params[] = $this->get('id');
        $params[] = $this->getMetaModel()->getFallbackLanguage();
        $params   = array_merge($params, $ids);

        $result = \Database::getInstance()->prepare($sql)->execute($params);

        return $result->fetchEach('id');
    }

    /**
     * {@inheritdoc}
     */
    public function setTranslatedDataFor($values, $language)
    {
        $values = (array) $values;
        if (!$values) {
            return;
        }

        $this->unsetValueFor(array_keys($values), $language);

        $wildcards = self::generateWildcards($values, '(?,?,?,?,?,?)');
        $joinTable = $this->getValueTable();
        $time      = time();

        $sql = <<<SQL
INSERT INTO	$joinTable
			(att_id, item_id, language, tstamp, href, title)
VALUES		$wildcards
SQL;

        foreach ($values as $id => $value) {
            $params[] = $this->get('id');
            $params[] = $id;
            $params[] = $language;
            $params[] = $time;
            $params[] = $value['href'];
            $params[] = strlen($value['title']) ? $value['title'] : null;
        }

        \Database::getInstance()->prepare($sql)->executeUncached($params);
    }

    /**
     * {@inheritdoc}
     */
    public function getTranslatedDataFor($ids, $language)
    {
        $ids = (array) $ids;

        if (!$ids) {
            return array();
        }

        $idWildcards = self::generateWildcards($ids);
        $joinTable   = $this->getValueTable();

        $sql = <<<SQL
SELECT		item_id AS id, href, title
FROM		$joinTable
WHERE		att_id = ?
AND			language = ?
AND			item_id IN ($idWildcards)
SQL;

        $params[] = $this->get('id');
        $params[] = $language;
        $params   = array_merge($params, $ids);

        $result = \Database::getInstance()->prepare($sql)->executeUncached($params);
        while ($result->next()) {
            $values[$result->id] = array('href' => $result->href, 'title' => $result->title);
        }

        return (array) $values;
    }

    /**
     * {@inheritdoc}
     */
    public function unsetValueFor($ids, $language)
    {
        $ids = (array) $ids;

        if (!$ids) {
            return;
        }

        $idWildcards = self::generateWildcards($ids);
        $joinTable   = $this->getValueTable();

        $sql = <<<SQL
DELETE FROM	$joinTable
WHERE		att_id = ?
AND			language = ?
AND			item_id IN ($idWildcards)
SQL;

        $params[] = $this->get('id');
        $params[] = $language;
        $params   = array_merge($params, $ids);

        \Database::getInstance()->prepare($sql)->executeUncached($params);
    }

    /**
     * Generate the SQL-Statement wildcards.
     *
     * @param array  $values   The values for the query.
     * @param string $wildcard The wildcard sign for the query.
     *
     * @return string
     */
    public static function generateWildcards(array $values, $wildcard = '?')
    {
        return rtrim(str_repeat($wildcard . ',', count($values)), ',');
    }
}
