<?php

/**
 * Page for editing sidebar gadget instance settings
 *
 * @package   Site
 * @copyright 2008-2016 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class SiteSidebarSettings extends AdminDBEdit
{
	// {{{ protected properties

	/**
	 * @var SiteGadgetInstance
	 */
	protected $gadget_instance;

	/**
	 * @var SiteGadget
	 */
	protected $gadget;

	protected $setting_widgets = array();

	// }}}

	// init phase
	// {{{ protected function initInternal()

	protected function initInternal()
	{
		parent::initInternal();
		$this->ui->loadFromXML($this->getUiXml());
		$this->initGadgetInstance();
		$this->initGadget();
		$this->initSettingsUi();
	}

	// }}}
	// {{{ protected function initGadgetInstance()

	protected function initGadgetInstance()
	{
		$class_name = SwatDBClassMap::get('SiteGadgetInstance');
		$this->gadget_instance = new $class_name();
		$this->gadget_instance->setDatabase($this->app->db);

		if ($this->id === null || !$this->gadget_instance->load($this->id)) {
			throw new AdminNotFoundException(
				sprintf(Site::_('Sidebar gadget with id ‘%s’ not found.'),
					$this->id));
		}

		if ($this->gadget_instance->instance === null) {
			if ($this->app->getInstanceId() !== null) {
				throw new AdminNotFoundException(
					sprintf(Site::_('Sidebar gadget with id ‘%s’ not found.'),
						$this->id));
			}
		} else {
			if ($this->gadget_instance->instance->id !==
				$this->app->getInstanceId()) {
				throw new AdminNotFoundException(
					sprintf(Site::_('Sidebar gadget with id ‘%s’ not found.'),
						$this->id));
			}
		}
	}

	// }}}
	// {{{ protected function initGadget()

	protected function initGadget()
	{
		$this->gadget = SiteGadgetFactory::get($this->app,
			$this->gadget_instance);
	}

	// }}}
	// {{{ protected function initSettingsUi()

	protected function initSettingsUi()
	{
		$settings = $this->gadget->getSettings();

		$container = $this->ui->getWidget('settings_container');

		foreach ($settings as $id => $setting) {
			switch ($setting->getType()) {
			case 'boolean':
				$widget = new SwatCheckbox();
				$widget->id = $id;
				// default boolean values
				$widget->value = $setting->getDefault();
				break;

			case 'date':
				$widget = new SwatDateEntry();
				$widget->id = $id;
				break;

			case 'integer':
				$widget = new SwatIntegerEntry();
				$widget->id = $id;
				break;

			case 'integer':
				$widget = new SwatFloatEntry();
				$widget->id = $id;
				break;

			case 'text':
				$widget = new SwatTextarea();
				$widget->id = $id;
				$widget->rows = 4;
				$widget->columns = 60;
				break;

			case 'string':
			default:
				$widget = new SwatEntry();
				$widget->id = $id;
				break;

			}

			$field = new SwatFormField();
			$field->title = $setting->getTitle();

			if ($setting->getDefault() !== null) {
				$em_tag = new SwatHtmlTag('em');
				$em_tag->setContent($this->getSettingDefaultValue($setting));
				$field->note = sprintf(Site::_('Default: %s'), $em_tag);
				$field->note_content_type = 'text/xml';
			}
			$field->add($widget);

			$this->settings_widgets[$id] = $widget;

			$container->add($field);
		}
	}

	// }}}
	// {{{ protected function getSettingDefaultValue()

	protected function getSettingDefaultValue(SiteGadgetSetting $setting)
	{
		switch ($setting->getType()) {
		case 'boolean':
			if ($setting->getDefault()) {
				$value = 'true';
			} else {
				$value = 'false';
			}
			break;

		case 'date':
			$value = $setting->getDefault()->formatLikeIntl(
				SwatDate::DF_DATE_TIME);
			break;

		case 'integer':
		case 'float':
			$locale = SwatI18NLocale::get();
			$value = $locale->formatNumber($setting->getDefault());
			break;

		case 'string':
		case 'text':
		default:
			$value = $setting->getDefault();
		}

		return $value;
	}

	// }}}
	// {{{ protected function getUiXml()

	protected function getUiXml()
	{
		return __DIR__.'/settings.xml';
	}

	// }}}

	// process phase
	// {{{ protected function saveDBData()

	protected function saveDBData()
	{
		// add and update setting values on wrapper
		$class_name = SwatDBClassMap::get('SiteGadgetInstanceSettingValue');
		$settings = $this->gadget->getSettings();
		$setting_values = $this->gadget_instance->setting_values;
		foreach ($settings as $id => $setting) {
			$widget = $this->settings_widgets[$id];
			if ($widget->value !== null) {
				if (isset($setting_values[$id])) {
					$setting_values[$id]->setValue($setting->getType(),
						$widget->value);
				} else {
					$setting_value = new $class_name();
					$setting_value->name = $id;
					$setting_value->setValue($setting->getType(),
						$widget->value);

					$setting_value->gadget_instance = $this->gadget_instance;
					$setting_values->add($setting_value);
				}
			}
		}

		// save wrapper
		if ($this->gadget_instance->setting_values->isModified()) {
			$this->gadget_instance->setting_values->save();

			// clear the caches
			$this->gadget_instance->caches->delete();

			if (isset($this->app->memcache)) {
				$this->app->memcache->delete('gadget_instances');
			}

			$message = new SwatMessage(sprintf(
				Site::_('“%s” has been saved.'),
				$this->gadget->getTitle()));

			$this->app->messages->add($message);
		}
	}

	// }}}
	// {{{ protected function relocate()

	protected function relocate()
	{
		$this->app->relocate('Sidebar');
	}

	// }}}

	// build phase
	// {{{ protected function buildInternal()

	protected function buildInternal()
	{
		parent::buildInternal();

		$this->ui->getWidget('edit_frame')->subtitle =
			$this->gadget->getTitle();
	}

	// }}}
	// {{{ protected function loadDBData()

	protected function loadDBData()
	{
		$values = $this->gadget_instance->setting_values;
		$settings = $this->gadget->getSettings();
		foreach ($values as $object) {
			$setting_name = $object->name;
			if (array_key_exists($setting_name, $settings)) {
				$widget = $this->settings_widgets[$setting_name];
				$type = $settings[$setting_name]->getType();
				$widget->value = $object->getValue($type);
			}
		}
	}

	// }}}
	// {{{ protected function buildNavBar()

	protected function buildNavBar()
	{
		parent::buildNavBar();

		$this->navbar->popEntry();

		$this->navbar->addEntry(new SwatNavBarEntry(
			Site::_('Edit Sidebar Gadget')));
	}

	// }}}
}

?>
