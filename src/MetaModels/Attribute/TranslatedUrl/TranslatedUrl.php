<?php

namespace MetaModels\Attribute\TranslatedUrl;

use MetaModels\Attribute\TranslatedReference;

/**
 * @author Oliver Hoff <oliver@hofff.com>
 */
class TranslatedUrl extends TranslatedReference {

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\Base::getFilterUrlValue()
	 */
	public function getFilterUrlValue($value) {
		return htmlencode(serialize($value));
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\Base::getAttributeSettingNames()
	 */
	public function getAttributeSettingNames() {
		return array_merge(parent::getAttributeSettingNames(), array(
			'no_external_link',
			'mandatory',
			'trim_title'
		));
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::getValueTable()
	 */
	protected function getValueTable() {
		return 'tl_metamodel_translatedurl';
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::valueToWidget()
	 */
	public function valueToWidget($value) {
		if($this->get('trim_title')) {
			return $value['href'];
		} else {
			return array($value['title'], $value['href']);
		}
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::widgetToValue()
	 */
	public function widgetToValue($value, $id) {
		if($this->get('trim_title')) {
			return array('href' => $value);
		} else {
			return array_combine(array('title', 'href'), $value);
		}
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\Base::getFieldDefinition()
	 */
	public function getFieldDefinition($overrides = array()) {
		$field = parent::getFieldDefinition($overrides);

		$field['inputType'] = 'text';
		$field['eval']['tl_class'] .= ' wizard inline';

		if($this->get('trim_title')) {
			$field['wizard']['pagePicker'] = array('MetaModels\Helper\Url\Url', 'singlePagePicker');

		} else {
			$field['eval']['size'] = 2;
			$field['eval']['multiple'] = true;
			$field['eval']['tl_class'] .= ' metamodelsattribute_url';
			$field['wizard']['pagePicker'] = array('MetaModels\Helper\Url\Url', 'multiPagePicker');
		}

		return $field;
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::getFilterOptions()
	 */
	public function getFilterOptions($ids, $usedOnly, &$count = null) {
		return array(); // not supported
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::searchForInLanguages()
	 */
	public function searchForInLanguages($pattern, $languages = array()) {
		$pattern = str_replace(array('*', '?'), array('%', '_'), $pattern);
		$joinTable = $this->getValueTable();

		$languages = (array) $languages;
		if($languages) {
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
		$params = array_merge($params, $languages);

		$result = \Database::getInstance()->prepare($sql)->executeUncached($params);

		return $result->fetchEach('id');
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::sortIds()
	 */
	public function sortIds($ids, $direction) {
		$ids = (array) $ids;
		if(count($ids) < 2) {
			return $ids;
		}

		$modelTable = $this->getMetaModel()->getTableName();
		$joinTable = $this->getValueTable();
		$direction == 'DESC' || $direction = 'ASC';

		$idWildcards = self::generateWildcards($ids);
		$sql = <<<SQL
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
		$params = array_merge($params, $ids);

		$result = \Database::getInstance()->prepare($sql)->execute($params);

		return $result->fetchEach('id');
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::setTranslatedDataFor()
	 */
	public function setTranslatedDataFor($values, $language) {
		$values = (array) $values;
		if(!$values) {
			return;
		}

		$this->unsetValueFor(array_keys($values), $language);

		$wildcards = self::generateWildcards($values, '(?,?,?,?,?,?)');
		$joinTable = $this->getValueTable();
		$time = time();

		$sql = <<<SQL
INSERT INTO	$joinTable
			(att_id, item_id, language, tstamp, href, title)
VALUES		$wildcards
SQL;

		foreach($values as $id => $value) {
			$params[] = $this->get('id');
			$params[] = $id;
			$params[] = $language;
			$params[] = $time;
			$params[] = $value['href'];
			$params[] = strlen($value['title']) ? $value['title'] : null;
		}

		\Database::getInstance()->prepare($sql)->executeUncached($params);
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::getTranslatedDataFor()
	 */
	public function getTranslatedDataFor($ids, $language) {
		$ids = (array) $ids;
		if(!$ids) {
			return array();
		}

		$idWildcards = self::generateWildcards($ids);
		$joinTable = $this->getValueTable();

		$sql = <<<SQL
SELECT		item_id AS id, href, title
FROM		$joinTable
WHERE		att_id = ?
AND			language = ?
AND			item_id IN ($idWildcards)
SQL;

		$params[] = $this->get('id');
		$params[] = $language;
		$params = array_merge($params, $ids);

		$result = \Database::getInstance()->prepare($sql)->executeUncached($params);
		while($result->next()) {
			$values[$result->id] = array('href' => $result->href, 'title' => $result->title);
		}

		return (array) $values;
	}

	/* (non-PHPdoc)
	 * @see \MetaModels\Attribute\TranslatedReference::unsetValueFor()
	 */
	public function unsetValueFor($ids, $language) {
		$ids = (array) $ids;
		if(!$ids) {
			return;
		}

		$idWildcards = self::generateWildcards($ids);
		$joinTable = $this->getValueTable();

		$sql = <<<SQL
DELETE FROM	$joinTable
WHERE		att_id = ?
AND			language = ?
AND			item_id IN ($idWildcards)
SQL;

		$params[] = $this->get('id');
		$params[] = $language;
		$params = array_merge($params, $ids);

		\Database::getInstance()->prepare($sql)->executeUncached($params);
	}

	/**
	 * @param array $values
	 * @param string $wildcard
	 * @return string
	 */
	public static function generateWildcards(array $values, $wildcard = '?') {
		return rtrim(str_repeat($wildcard . ',', count($values)), ',');
	}

}
