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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
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
        $pattern           = str_replace(array('*', '?'), array('%', '_'), $pattern);
        $languageCondition = '';

        $languages = (array) $languages;
        if ($languages) {
            $languageCondition = 'AND langcode IN (' . $this->parameterMask($languages) . ')';
        }

        $sql = sprintf(
            'SELECT DISTINCT item_id AS id FROM %1$s WHERE (title LIKE ? OR href LIKE ?) AND att_id = ?%2$s',
            $this->getValueTable(),
            $languageCondition
        );

        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $this->get('id');
        $params   = array_merge($params, $languages);

        $result = $this->getMetaModel()->getServiceContainer()->getDatabase()->prepare($sql)->execute($params);

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

        if ($direction !== 'DESC') {
            $direction = 'ASC';
        }

        $sql = sprintf(
            'SELECT _model.id FROM %1$s AS _model
            LEFT JOIN %2$s AS _active ON _active.item_id=_model.id
                                        AND _active.att_id=?
                                        AND _active.langcode=?
            LEFT JOIN %2$s AS _fallback ON _active.item_id IS NULL
                                        AND _fallback.item_id=_model.id
                                        AND _fallback.att_id=?
                                        AND _fallback.langcode=?
            WHERE _model.id IN (%3$s)
            ORDER BY COALESCE(_active.title, _active.href, _fallback.title, _fallback.href) %4$s,
                     COALESCE(_active.href, _fallback.href) %4$s',
            $this->getMetaModel()->getTableName(),
            $this->getValueTable(),
            $this->parameterMask($ids),
            $direction
        );

        $params[] = $this->get('id');
        $params[] = $this->getMetaModel()->getActiveLanguage();
        $params[] = $this->get('id');
        $params[] = $this->getMetaModel()->getFallbackLanguage();
        $params   = array_merge($params, $ids);
        $result   = $this->getMetaModel()->getServiceContainer()->getDatabase()->prepare($sql)->execute($params);

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

        $sql = sprintf(
            'INSERT INTO %1$s (att_id, item_id, langcode, tstamp, href, title) VALUES %2$s',
            $this->getValueTable(),
            rtrim(str_repeat('(?,?,?,?,?,?),', count($values)), ',')
        );

        $time   = time();
        $params = array();
        foreach ($values as $id => $value) {
            $params[] = $this->get('id');
            $params[] = $id;
            $params[] = $language;
            $params[] = $time;
            $params[] = $value['href'];
            $params[] = strlen($value['title']) ? $value['title'] : null;
        }

        $this->getMetaModel()->getServiceContainer()->getDatabase()->prepare($sql)->execute($params);
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

        $sql = sprintf(
            'SELECT item_id AS id, href, title
            FROM %1$s
            WHERE att_id=?
            AND langcode=?
            AND item_id IN (%2$s)',
            $this->getValueTable(),
            $this->parameterMask($ids)
        );

        $params[] = $this->get('id');
        $params[] = $language;
        $params   = array_merge($params, $ids);

        $result = $this->getMetaModel()->getServiceContainer()->getDatabase()->prepare($sql)->execute($params);
        $values = array();
        while ($result->next()) {
            $values[$result->id] = array('href' => $result->href, 'title' => $result->title);
        }

        return $values;
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

        $sql = sprintf(
            'DELETE FROM %1$s
            WHERE att_id=?
            AND langcode=?
            AND item_id IN (%2$s)',
            $this->getValueTable(),
            $this->parameterMask($ids)
        );

        $params[] = $this->get('id');
        $params[] = $language;
        $params   = array_merge($params, $ids);

        $this->getMetaModel()->getServiceContainer()->getDatabase()->prepare($sql)->execute($params);
    }
}
