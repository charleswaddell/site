<?php

require_once 'SwatDB/SwatDBRecordsetWrapper.php';
require_once 'Site/dataobjects/SiteMediaSet.php';
require_once 'Site/dataobjects/SiteMediaEncodingWrapper.php';

/**
 * A recordset wrapper class for SiteMediaSet objects
 *
 * Note: This recordset automatically loads encodings for media sets when
 *       constructed from a database result. If this behaviour is undesirable,
 *       set the lazy_load option to true.
 *
 * @package   Site
 * @copyright 2011-2013 silverorange
 * @see       SiteMediaSet
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class SiteMediaSetWrapper extends SwatDBRecordsetWrapper
{
	// {{{ public function initializeFromResultSet()

	public function initializeFromResultSet(MDB2_Result_Common $rs)
	{
		parent::initializeFromResultSet($rs);

		if (!$this->getOption('lazy_load')) {
			$this->loadAllSubRecordsets(
				'encodings',
				$this->getMediaEncodingWrapperClass(),
				'MediaEncoding',
				'media_set',
				'',
				$this->getMediaEncodingOrderBy()
			);
		}
	}

	// }}}
	// {{{ protected function getMediaEncodingWrapperClass()

	protected function getMediaEncodingWrapperClass()
	{
		return SwatDBClassMap::get('SiteMediaEncodingWrapper');
	}

	// }}}
	// {{{ protected function getMediaEncodingOrderBy()

	protected function getMediaEncodingOrderBy()
	{
		return 'media_set';
	}

	// }}}
	// {{{ protected function init()

	protected function init()
	{
		parent::init();

		$this->row_wrapper_class =
			SwatDBClassMap::get('SiteMediaSet');

		$this->index_field = 'id';
	}

	// }}}
}

?>
