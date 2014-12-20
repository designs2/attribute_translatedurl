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
 * @subpackage AttributeUrl
 * @author     Andreas Isaak <info@andreas-isaak.de>
 * @author     Christopher Boelter <christopher@boelter.eu>
 * @author     Oliver Hoff <oliver@hofff.com>
 * @copyright  The MetaModels team.
 * @license    LGPL.
 * @filesource
 */

/**
 * Register the classes
 */
ClassLoader::addClasses(array
(
	'MetaModels\Attribute\TranslatedUrl\TranslatedUrl'     => 'system/modules/metamodelsattribute_translatedurl/MetaModels/Attribute/TranslatedUrl/TranslatedUrl.php'
));

/**
 * Register the templates
 */
TemplateLoader::addFiles(array
(
	'mm_attr_translatedurl' => 'system/modules/metamodelsattribute_translatedurl/templates',
));
