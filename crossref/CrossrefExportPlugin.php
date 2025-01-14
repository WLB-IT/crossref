<?php

/**
 * @file plugins/generic/crossref/CrossrefExportPlugin.php
 *
 * @brief Crossref export plugin
 */

namespace APP\plugins\generic\crossref;

use PKP\plugins\ImportExportPlugin;
use PKP\context\Context;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\core\Application;

use Exception;
use PKP\doi\Doi;
use PKP\db\DAORegistry;
use APP\facades\Repo;
use PKP\plugins\Plugin;
use PKP\filter\FilterDAO;
use APP\submission\Submission;
use PKP\config\Config;
use APP\plugins\IDoiRegistrationAgency;
use DateTime;
use PKP\file\TemporaryFileManager;
use PKP\file\FileManager;


class CrossrefExportPlugin extends ImportExportPlugin
{

	// Crossref settings.
	public const CROSSREF_DEPOSIT_STATUS = 'depositStatus';
	public const CROSSREF_STATUS_FAILED = 'failed';
	public const CROSSREF_API_DEPOSIT_OK = 200;
	public const CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF = 403;
	public const CROSSREF_API_URL = 'https://api.crossref.org/v2/deposits';
	public const CROSSREF_API_URL_TEST = 'https://test.crossref.org/servlet/deposit';
	public const CROSSREF_API_STATUS_URL = 'https://api.crossref.org/servlet/submissionDownload';

	/**
	 * Constructor.
	 */
	public function __construct(protected IDoiRegistrationAgency|Plugin $agencyPlugin)
	{
		parent::__construct();
	}

	/**
	 * @copydoc Plugin::getName()
	 */
	public function getName()
	{
		return 'CrossrefExportPlugin';
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName()
	{
		return __('plugins.importexport.crossref.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription()
	{
		return __('plugins.importexport.crossref.description');
	}

	public function getPluginSettingsPrefix()
	{
		return 'crossrefplugin';
	}

	public function getSubmissionFilter()
	{
		return 'monograph=>crossref-xml';
	}

	/**
	 * @copydoc Plugin::register()
	 * 
	 * @param null|mixed $mainContextId
	 */
	public function register($category, $path, $mainContextId = null)
	{
		if (!parent::register($category, $path, $mainContextId)) return false;

		$success = parent::register($category, $path, $mainContextId);
		if ($success) {
			// register hooks. This will prevent DB access attempts before the
			// schema is installed.
			if (Application::isUnderMaintenance()) {
				return true;
			}
		}
		return $success;
	}


	/** 
	 * Proxy to main plugin class's `getSetting` method 
	 * 
	 */
	public function getSetting($contextId, $name)
	{
		return $this->agencyPlugin->getSetting($contextId, $name);
	}

	/**
	 * Get a list of additional setting names that should be stored with the objects.
	 * 
	 * @return array
	 */
	protected function _getObjectAdditionalSettings()
	{
		return [
			$this->getDepositBatchIdSettingName(),
			$this->getFailedMsgSettingName(),
		];
	}

	public function getExportDeploymentClassName()
	{
		return (string) \APP\plugins\generic\crossref\CrossrefExportDeployment::class;
	}

	public function executeCLI($scriptName, &$args): void
	{
		fatalError('Not implemented.');
	}

	public function usage($scriptName): void
	{
		fatalError('Not implemented.');
	}

	// /**
	//  * Mark monographs and chapters as registered.
	//  */
	// public function markRegistered($context, $objects)
	// {
	// 	foreach ($objects as $object) {

	// 		// Get all DOIs for each monograph and chapters.
	// 		if ($object instanceof Submission) {
	// 			$doiIds = Repo::doi()->getDoisForSubmission($object->getId());
	// 		}

	// 		foreach ($doiIds as $doiId) {
	// 			Repo::doi()->markRegistered($doiId);
	// 		}
	// 	}
	// }

	/**
	 * Get request failed message setting name.
	 * NB: Changed as of 3.4
	 *
	 * @return string
	 */
	public function getFailedMsgSettingName()
	{
		return $this->getPluginSettingsPrefix() . '_failedMsg';
	}

	/**
	 * Get deposit batch ID setting name.
	 * NB Changed as of 3.4
	 *
	 * @return string
	 */
	public function getDepositBatchIdSettingName()
	{
		return $this->getPluginSettingsPrefix() . '_batchId';
	}

	public function getDepositSuccessNotificationMessageKey()
	{
		return 'plugins.generic.crossref.register.success';
	}

	/**
	 * Exports and stores XML as a TemporaryFile.
	 * Multiple XMLs are packaged as .tar.gz
	 *
	 * @throws Exception
	 */
	public function exportAsDownload(Context $context, array $submissions, string $filter, ?array &$outputErrors = null): ?int
	{
		$fileManager = new TemporaryFileManager();
		$result = $this->_checkForTar();
		if ($result === true) {

			// Construct XML file for download.
			$exportedFiles = [];
			foreach ($submissions as $submission) {
				$submissionId = $submission->getId();
				$exportXml = $this->exportXML($submission, $filter, $context, $outputErrors);

				// Construct export file name.
				$dateString = new DateTime();
				$exportFileName = $this->getExportFileName(
					$this->getExportPath(),
					'monograph',
					$submissionId,
					'.xml',
					$dateString
				);
				$fileManager->writeFile($exportFileName, $exportXml);
				$exportedFiles[] = $exportFileName;
			}

			// For more than one file package the files up as a single tar.
			assert(count($exportedFiles) >= 1);
			if (count($exportedFiles) > 1) {
				$finalExportFileName = $this->getExportFileName(
					$this->getExportPath(),
					'monographs',
					$context->getId(),
					'.tar.gz',
					$dateString
				);
				$this->_tarFiles($this->getExportPath(), $finalExportFileName, $exportedFiles);

				// Remove files.
				foreach ($exportedFiles as $exportedFile) {
					$fileManager->deleteByPath($exportedFile);
				}
			} else {
				$finalExportFileName = array_shift($exportedFiles);
			}

			// Create a temporary file for the user.
			$user = Application::get()->getRequest()->getUser();
			return $fileManager->createTempFileFromExisting($finalExportFileName, $user->getId());
		}
		return null;
	}

	/**
	 * Export XMLs and deposit via HTTP-client.
	 * @param Context $context
	 * @param array $submissions 
	 * @param string $filter 
	 * @param string &$responseMessage
	 */
	public function exportAndDeposit($context, $submissions, $filter, &$responseMessage): bool
	{
		$fileManager = new FileManager();

		// Just one general error should be displayed.
		$errorsOccurred = false;

		// The new Crossref deposit API expects one request per object.
		foreach ($submissions as $submission) {

			// Create XML.
			$outputErrors = [];
			$submissionId = $submission->getId();
			$exportXml = $this->exportXML($submission, $filter, $context, $outputErrors);

			// Construct export file name.
			$dateString = new DateTime();
			$exportFileName = $this->getExportFileName(
				$this->getExportPath(),
				'monograph',
				$submissionId,
				'.xml',
				$dateString
			);
			$fileManager->writeFile($exportFileName, $exportXml);

			// Deposit the XML file if no XML-creation errors occurred.
			if ($outputErrors) {
				$errorsOccurred = true;
			} else {
				$result = $this->depositXML($submission, $context, $exportFileName);

				// Handle deposit errors.
				if (!$result) {
					$errorsOccurred = true;
				}
				if (is_array($result)) {
					$resultErrors[] = $result;
				}
			}

			// Remove all temporary files.
			$fileManager->deleteByPath($exportFileName);
		}

		// Prepare response message and return status.
		if ($errorsOccurred) {
			if (!empty($outputErrors)) {
				$responseMessage = __($outputErrors[0]);
				$this->updateDepositStatus($context, $submission, Doi::STATUS_ERROR, null, $responseMessage);
				return false;
			} else if (!empty($resultErrors)) {
				$responseMessage = __('plugins.generic.crossref.deposit.unsuccessful');
				return false;
			} else {
				$responseMessage = __('api.dois.400.depositFailed');
				return false;
			}
		} else {
			$responseMessage = $this->getDepositSuccessNotificationMessageKey();
			return true;
		}
	}

	/**
	 * Return the whole export file name.
	 *
	 * @param string $basePath Base path for temporary file storage
	 * @param string $objectsFileNamePart Part different for each object type.
	 * @param int $id Context or submission ID.   
	 * @param string $extension
	 * @param ?DateTime $dateFilenamePart
	 *
	 * @return string
	 */
	function getExportFileName($basePath, $objectsFileNamePart, $id, $extension = '.xml', ?DateTime $dateFilenamePart = null)
	{
		$dateFilenamePartString = $dateFilenamePart->format('d-m-Y');
		return $basePath . $this->getPluginSettingsPrefix() . '-' . $dateFilenamePartString . '-' . $objectsFileNamePart . '-' . $id . $extension;
	}

	/**
	 * Export selected submission as XML/tar-package.
	 * 
	 * @param mixed $submission Array of or single published submission, issue or galley
	 * @param string $filter
	 * @param Context $context
	 * @param null|mixed $outputErrors
	 *
	 * @return string XML document.
	 */
	function exportXml($submission, $filter, $context, &$outputErrors = null)
	{
		// Manage XML-filter.
		$filterDao = DAORegistry::getDAO('FilterDAO');
		/** @var FilterDAO $filterDao */
		$exportFilters = $filterDao->getObjectsByGroup($filter);
		assert(count($exportFilters) == 1); // Assert only a single serialization filter
		$exportFilter = array_shift($exportFilters);

		// Configure filter.
		$plugin = $this;
		$exportFilter->setDeployment(new CrossrefExportDeployment($context, $plugin));
		$exportFilter->setNoValidation(true);

		// Apply filter to object and save as xml if no error is thrown.
		$submissions[] = $submission;  // For this filter, we need an array.
		$exportXml = $exportFilter->execute($submissions);
		$xml = $exportXml->saveXml();

		// Handle custom filter errors.
		$hasFilterErrors = $exportFilter->hasErrors();
		if ($hasFilterErrors) {
			$filterErrors = $exportFilter->getErrors();
			$outputErrors = $filterErrors;
		}
		return $xml;
	}

	/**
	 * Create a tar archive.
	 *
	 * @param string $targetPath
	 * @param string $targetFile
	 * @param array $sourceFiles
	 */
	public function _tarFiles($targetPath, $targetFile, $sourceFiles)
	{
		assert((bool) $this->_checkForTar());

		// GZip compressed result file.
		$tarCommand = Config::getVar('cli', 'tar') . ' -czf ' . escapeshellarg($targetFile);

		// Do not reveal our internal export path by exporting only relative filenames.
		$tarCommand .= ' -C ' . escapeshellarg($targetPath);

		// Do not reveal our webserver user by forcing root as owner.
		$tarCommand .= ' --owner 0 --group 0 --';

		// Add each file individually so that other files in the directory
		// will not be included.
		foreach ($sourceFiles as $sourceFile) {
			assert(dirname($sourceFile) . '/' === $targetPath);
			if (dirname($sourceFile) . '/' !== $targetPath) {
				continue;
			}
			$tarCommand .= ' ' . escapeshellarg(basename($sourceFile));
		}
		// Execute the command.
		exec($tarCommand);
	}

	/**
	 * Test whether the tar binary is available.
	 *
	 * @return bool|array Boolean true if available otherwise
	 *  an array with an error message.
	 */
	public function _checkForTar()
	{
		$tarBinary = Config::getVar('cli', 'tar');
		if (empty($tarBinary) || !is_executable($tarBinary)) {
			$result = [
				['manager.plugins.tarCommandNotFound']
			];
		} else {
			$result = true;
		}
		return $result;
	}

	/**
	 * Return the plugin export directory.
	 *
	 * @return string The export directory path.
	 */
	public function getExportPath()
	{
		return Config::getVar('files', 'files_dir') . '/temp/';
	}

	/**
	 * Check the Crossref APIs, if deposits and registration have been successful
	 *
	 * @param Context $context
	 * @param DataObject $object The object getting deposited
	 * @param int $status
	 * @param string $batchId
	 * @param string $failedMsg (optional)
	 */
	public function updateDepositStatus($context, $object, $status, $batchId = null, $failedMsg = null)
	{

		// Updates DOI of monograph as well as chapters.
		assert($object instanceof Submission);
		if ($object instanceof Submission) {
			$doiIds = Repo::doi()->getDoisForSubmission($object->getId());
		}

		foreach ($doiIds as $doiId) {
			$doi = Repo::doi()->get($doiId);

			$editParams = [
				'status' => $status,

				// Sets new failedMsg or resets to null for removal of previous message.
				$this->getFailedMsgSettingName() => $failedMsg,
				$this->getDepositBatchIdSettingName() => $batchId,
			];

			if ($status === Doi::STATUS_REGISTERED) {
				$editParams['registrationAgency'] = $this->getName();
			}

			Repo::doi()->edit($doi, $editParams);
		}
	}

	/**
	 * Deposit selected submissions via HTTP client.
	 * 
	 * @param Submission $submission
	 * @param Context $context
	 * @param string $filename
	 */
	public function depositXML($submission, $context, $filename)
	{
		// Application is set to sandbox mode and will not run the features of plugin
		if (Config::getVar('general', 'sandbox', false)) {
			error_log('Application is set to sandbox mode and will not have any interaction with crossref external service');
			return false;
		}

		$status = null;
		$msgSave = null;
		$httpClient = Application::get()->getHttpClient();
		assert(is_readable($filename));

		try {
			$response = $httpClient->request(
				'POST',
				$this->isTestMode($context) ? static::CROSSREF_API_URL_TEST : static::CROSSREF_API_URL,
				[
					'multipart' => [
						[
							'name' => 'usr',
							'contents' => $this->getSetting($context->getId(), 'username'),
						],
						[
							'name' => 'pwd',
							'contents' => $this->getSetting($context->getId(), 'password'),
						],
						[
							'name' => 'operation',
							'contents' => 'doMDUpload',
						],
						[
							'name' => 'mdFile',
							'contents' => fopen($filename, 'r'),
						],
					]
				]
			);
		} catch (RequestException $e) {

			// Handle exception.
			$returnMessage = $e->getMessage();
			if ($e->hasResponse()) {
				$eResponseBody = $e->getResponse()->getBody();
				$eStatusCode = $e->getResponse()->getStatusCode();
				if ($eStatusCode == static::CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF) {
					$xmlDoc = new \DOMDocument('1.0', 'utf-8');
					$xmlDoc->loadXML($eResponseBody);
					$batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);
					$msg = $xmlDoc->getElementsByTagName('msg')->item(0)->nodeValue;
					$msgSave = $msg . PHP_EOL . $eResponseBody;
					$status = Doi::STATUS_ERROR;
					$this->updateDepositStatus($context, $submission, $status, $batchIdNode->nodeValue, $msgSave);
					$returnMessage = $msg . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
				} else {
					$returnMessage = $eResponseBody . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
					$this->updateDepositStatus($context, $submission, Doi::STATUS_ERROR, null, $returnMessage);
				}
			}
			return __('plugins.importexport.common.register.error.mdsError', ['param' => $returnMessage]);
		}

		// Get DOMDocument from the response XML string.
		$xmlDoc = new \DOMDocument('1.0', 'utf-8');
		$xmlDoc->loadXML($response->getBody());
		$batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);

		// Get the DOI deposit status
		// If the deposit failed
		$failureCountNode = $xmlDoc->getElementsByTagName('failure_count')->item(0);
		$failureCount = (int) $failureCountNode->nodeValue;
		if ($failureCount > 0) {
			$status = Doi::STATUS_ERROR;
			$result = false;
		} else {
			// Deposit was received
			$status = Doi::STATUS_REGISTERED;
			$result = true;

			// If there were some warnings, display them
			$warningCountNode = $xmlDoc->getElementsByTagName('warning_count')->item(0);
			$warningCount = (int) $warningCountNode->nodeValue;
			if ($warningCount > 0) {
				$result = [['plugins.importexport.crossref.register.success.warning', htmlspecialchars($response->getBody())]];
			}
		}

		// Update the status.
		if ($status) {
			$this->updateDepositStatus($context, $submission, $status, $batchIdNode->nodeValue, $msgSave, null);
		}

		return $result;
	}

	/**
	 * Check whether we are in test mode.
	 *
	 * @param Context $context
	 *
	 * @return bool
	 */
	public function isTestMode($context)
	{
		return ($this->getSetting($context->getId(), 'testMode') == 1);
	}
}
