<?php

/**
 * @file plugins/generic/crossref/CrossrefExportDeployment.inc.php
 *
 * @brief Base class configuring the crossref export process to an
 * application's specifics.
 */

namespace APP\plugins\generic\crossref;

use PKP\context\Context;
use PKP\plugins\Plugin;

class CrossrefExportDeployment
{

	// XML attributes
	public const CROSSREF_XMLNS = 'http://www.crossref.org/schema/4.3.7';
	public const CROSSREF_XMLNS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
	public const CROSSREF_XSI_SCHEMALOCATION = 'http://www.crossref.org/schemas/crossref4.3.7.xsd';
	public const CROSSREF_VERSION = '4.3.7';

	/** @var Context The current import/export context */
	public $_context;

	/** @var Plugin The current import/export plugin */
	public $_plugin;

	/**
	 * Constructor
	 * @param \PKP\context\Context $context
	 * @param \PKP\plugins\Plugin $plugin
	 */
	public function __construct($context, $plugin)
	{
		$this->setContext($context);
		$this->setPlugin($plugin);
	}

	//
	// Deployment items for subclasses to override
	//
	/**
	 * Get the root element name
	 * @return string
	 */
	public function getRootElementName()
	{
		return 'doi_batch';
	}

	/**
	 * Get the namespace.
	 * @return string
	 */
	public function getNamespace()
	{
		return static::CROSSREF_XMLNS;
	}

	/**
	 * Get the schema version.
	 * @return string
	 */
	public function getXmlSchemaVersion()
	{
		return static::CROSSREF_VERSION;
	}

	/**
	 * Get the version.
	 * @return string
	 */
	public function getVersion()
	{
		return static::CROSSREF_VERSION;
	}

	/**
	 * Get the schema instance.
	 * @return string
	 */
	public function getXmlSchemaInstance()
	{
		return static::CROSSREF_XMLNS_XSI;
	}

	/**
	 * Get the schema location.
	 * @return string
	 */
	public function getXmlSchemaLocation()
	{
		return static::CROSSREF_XSI_SCHEMALOCATION;
	}

	/**
	 * Get the schema filename.
	 * @return string
	 */
	public function getSchemaFilename()
	{
		return $this->getXmlSchemaLocation();
	}

	//
	// Getter/setters
	//
	/**
	 * Set the import/export context.
	 * @param Context $context
	 */
	public function setContext($context)
	{
		$this->_context = $context;
	}

	/**
	 * Get the import/export context.
	 * @return Context
	 */
	public function getContext()
	{
		return $this->_context;
	}

	/**
	 * Set the import/export plugin.
	 * @param Plugin $plugin 
	 */
	public function setPlugin($plugin)
	{
		$this->_plugin = $plugin;
	}

	/**
	 * Get the import/export plugin.
	 * @return Plugin
	 */
	public function getPlugin()
	{
		return $this->_plugin;
	}
}
