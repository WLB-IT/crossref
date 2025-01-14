<?php

/**
 * @file plugins/generic/crossref/filter/MonographCrossrefXmlFilter.php
 *
 * @brief Class that converts a monograph to a crossref XML document.
 */

namespace APP\plugins\generic\crossref\filter;

use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\filter\FilterGroup;
use DOMDocument;
use DateTime;
use DOMElement;
use APP\facades\Repo;
use APP\core\Application;
use PKP\core\PKPApplication;
use APP\monograph\Chapter;
use PKP\i18n\LocaleConversion;

class MonographCrossrefXmlFilter extends NativeExportFilter
{
	/**
	 * Constructor
	 * @param FilterGroup $filterGroup 
	 */
	function __construct($filterGroup)
	{
		$this->setDisplayName('Crossref Monograph XML Export');
		parent::__construct($filterGroup);
	}

	//
	// Implement template methods from Filter
	//
	/**
	 * @see Filter::process()
	 * @param Submission $pubObjets Array of submission objects.
	 * 
	 * @return DOMDocument
	 */
	function &process(&$pubObjects)
	{

		// Create the XML document.
		$doc = new \DOMDocument('1.0', 'utf-8');
		$doc->preserveWhiteSpace = false;
		$doc->formatOutput = true;
		$deployment = $this->getDeployment();

		// Create the root node.
		$rootNode = $this->createRootNode($doc);
		$doc->appendChild($rootNode);

		// Current publication and submission information.
		assert(count($pubObjects) === 1);
		$pubObject = $pubObjects[0];
		$publication = $pubObject->getCurrentPublication();
		$subId = $publication->getData('submissionId');

		// Create head element.
		$headNode = $this->createHeadNode($doc, $deployment, $subId);
		$rootNode->appendChild($headNode);

		// Create body element.
		$bodyNode = $this->createBodyNode($doc, $deployment, $pubObject);
		$rootNode->appendChild($bodyNode);

		return $doc;
	}

	/**
	 * Create and return the root node.
	 * @param DOMDocument $doc DOMDocument
	 * @return DOMElement
	 */
	function createRootNode($doc)
	{
		$deployment = $this->getDeployment();
		$rootNode = $doc->createElementNS($deployment->getNamespace(), $deployment->getRootElementName());
		$rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
		$rootNode->setAttribute('version', $deployment->getXmlSchemaVersion());
		$rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
		return $rootNode;
	}

	/**
	 * Creates head node of the XML.
	 * @param DOMDocument $doc
	 * @param NativeImportExportDeployment $deployment
	 * @param integer $subId Submission ID.
	 * @return DOMElement
	 */
	function createHeadNode($doc, $deployment, $subId)
	{
		$headNode = $doc->createElementNS($deployment->getNamespace(), 'head');

		// Get current datetime.
		date_default_timezone_set('Europe/Berlin');
		$dateTime = new DateTime();

		// Get depositor information.
		$plugin = $deployment->getPlugin();
		$context = $deployment->getContext();
		$depositorName =  $plugin->getSetting($context->getId(), 'depositorName');
		$depositorEmail = $plugin->getSetting($context->getId(), 'depositorEmail');

		// Custom doi batch ID node: date-time-submissionID.
		$doiBatchId = $dateTime->format('Y-m-d-G-i-s') . '-' . $subId;
		$doiBatchIdNode = $this->dataFieldNode($deployment, $doc, 'doi_batch_id');
		$doiBatchIdNode->appendChild($doc->createTextNode($doiBatchId));
		$headNode->appendChild($doiBatchIdNode);

		// Timestamp node: transaction of the XML.
		$timestamp = $dateTime->format('YmdGis');
		$timeStampNode = $this->dataFieldNode($deployment, $doc, 'timestamp');
		$timeStampNode->appendChild($doc->createTextNode($timestamp));
		$headNode->appendChild($timeStampNode);

		// Depositor info node.
		$depositorNode = $this->dataFieldNode($deployment, $doc, 'depositor');
		$depositorNameSubFieldNode = $this->subFieldNode($deployment, $doc, 'depositor_name', $depositorName);
		$depositorEmailSubFieldNode = $this->subFieldNode($deployment, $doc, 'email_address', $depositorEmail);
		$depositorNode->appendChild($depositorNameSubFieldNode);
		$depositorNode->appendChild($depositorEmailSubFieldNode);
		$headNode->appendChild($depositorNode);

		// Registrant node.
		$registrantNode = $this->dataFieldNode($deployment, $doc, 'registrant');
		$registrantNode->appendChild($doc->createTextNode($depositorName));
		$headNode->appendChild($registrantNode);

		return $headNode;
	}

	/**
	 * Creates body node of the XML.
	 * @param DOMDocument $doc
	 * @param NativeImportExportDeployment $deployment
	 * @param Submission $pubObject
	 * @return DOMElement
	 */
	function createBodyNode($doc, $deployment, $pubObject)
	{
		// Create body node.
		$bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');

		// Create book node.
		$bookNode = $this->dataFieldNode($deployment, $doc, 'book', ['book_type' => 'edited_book']);

		// Create book metadata node and append to book node.
		$bookMetadataNode = $this->createBookMetadataNode($deployment, $doc, $pubObject);
		$bookNode->appendChild($bookMetadataNode);

		// Create chapter metadata node and append to book node.
		$publication = $pubObject->getCurrentPublication();
		$chapters = $publication->getData('chapters');
		if ($chapters) {
			foreach ($chapters as $chapter) {
				$chapterMetadataNode = $this->createContentItemNode($deployment, $doc, $chapter, $pubObject);
				$bookNode->appendChild($chapterMetadataNode);
			}
		}

		$bodyNode->appendChild($bookNode);
		return $bodyNode;
	}

	/**
	 * Generate the data field node.
	 * @param NativeImportExportDeployment $deployment
	 * @param DOMDocument $doc
	 * @param string $dataElement
	 * @param array $attributes Additional attributes and their labels. 
	 * @param string $textContent Additional text content e.g. if there is no subfield.
	 * @return DOMElement
	 */
	function dataFieldNode($deployment, $doc, $dataElement, $attributes = [], $textContent = '')
	{
		$dataFieldNode = $doc->createElementNS($deployment->getNamespace(), $dataElement);
		if ($attributes) {
			foreach ($attributes as $attributeLabel => $attribute) {
				$dataFieldNode->setAttribute($attributeLabel, $attribute);
			}
		}
		if (!empty($textContent)) {
			$dataFieldNode->appendChild($doc->createTextNode($textContent));
		}
		return $dataFieldNode;
	}

	/**
	 * Generate the subfield node.
	 * @param NativeImportExportDeployment $deployment
	 * @param DOMDocument $doc
	 * @param string $nodeLabel
	 * @param string $value
	 * @return DOMElement
	 */
	function subFieldNode($deployment, $doc, $nodeLabel, $value)
	{
		$subFieldNode = $doc->createElementNS($deployment->getNamespace(), $nodeLabel);
		$subFieldNode->appendChild($doc->createTextNode($value));
		return $subFieldNode;
	}

	/**
	 * Generate the metadata node for a book.
	 * @param NativeImportExportDeployment $deployment
	 * @param DOMElement $doc
	 * @param Submission $pubObject
	 * @return DOMElement
	 */
	function createBookMetadataNode($deployment, $doc, $pubObject)
	{
		$publication = $pubObject->getCurrentPublication();
		$locale = $publication->getData('locale');
		$langCode = LocaleConversion::getIso1FromLocale($locale);

		// Check if single monograph or part of series.
		$seriesId = $publication->getData('seriesId');
		if (!empty($seriesId)) {
			$seriesDao = Repo::section();
			$series = $seriesDao->get($seriesId); 
		}

		// Create metadata node.
		if ($series) {
			$bookMetadataNode = $this->dataFieldNode($deployment, $doc, 'book_series_metadata', ['language' => $langCode]);
			$bookMetadataNodeWithSubfields = $this->createMetadataNodeSubfields($deployment, $doc, $pubObject, $bookMetadataNode, 'series');
		} else {
			$bookMetadataNode = $this->dataFieldNode($deployment, $doc, 'book_metadata', ['language' => $langCode]);
			$bookMetadataNodeWithSubfields = $this->createMetadataNodeSubfields($deployment, $doc, $pubObject, $bookMetadataNode, '');
		}
		return $bookMetadataNodeWithSubfields;
	}

	/**
	 * Create subfields of metadata node.
	 * @param NativeImportExportDeployment $deployment
	 * @param DOMDocument $doc
	 * @param Submission $pubObject Submission object.
	 * @param DOMElement $bookMetadataNode Metadata object.
	 * @param string $bookType Single monograph, part of series. etc.
	 * @return DOMElement
	 */
	function createMetadataNodeSubfields($deployment, $doc, $pubObject, $bookMetadataNode, $bookType)
	{

		// Get necessary vars.
		$publication = $pubObject->getCurrentPublication();
		$request = Application::get()->getRequest();
		$locale = $publication->getData('locale');
		$context = $deployment->getContext();

		// Create titles subfield.
		$titlesNode = $this->dataFieldNode($deployment, $doc, 'titles',);
		$title = $pubObject->getLocalizedTitle($locale);
		if (!$title) {
			$this->addError(__('plugins.generic.crossref.missingElement', ['param1' => $pubObject->getId(), 'param2' => 'Title']));
		} else {
			$titleSubfield = $this->subFieldNode($deployment, $doc, 'title', $title);
			$titlesNode->appendChild($titleSubfield);
		}

		// Create publication date subfield.
		$publicationDateNode = $this->dataFieldNode($deployment, $doc, 'publication_date', ['media_type' => 'online']);
		$pubYear =  date("Y", strtotime($publication->getData('datePublished')));
		$yearSubfield = $this->subFieldNode($deployment, $doc, 'year', $pubYear);
		$publicationDateNode->appendChild($yearSubfield);

		// Create doi subfield.
		$doiId = $publication->getData('doiId');
		if ($doiId) {
			$doi = Repo::doi()->get((int) $doiId)->getData('doi');
			$url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'catalog', 'book', $pubObject->getBestId());
			$doiDataNode = $this->dataFieldNode($deployment, $doc, 'doi_data');
			$doiSubfield = $this->subFieldNode($deployment, $doc, 'doi', $doi);
			$resourceSubfield = $this->subFieldNode($deployment, $doc, 'resource', $url);
			$doiDataNode->appendChild($doiSubfield);
			$doiDataNode->appendChild($resourceSubfield);
		}

		// Create publisher subfield.
		$publisherNode = $this->dataFieldNode($deployment, $doc, 'publisher', '');
		$publisher = $context->getData('name', $locale);
		if (!$publisher) {
			$this->addError(__('plugins.generic.crossref.missingElement', ['param1' => $pubObject->getId(), 'param2' => 'Publisher Name']));
		} else {
			$publisherNameSubfield = $this->subFieldNode($deployment, $doc, 'publisher_name', $publisher);
			$publisherNode->appendChild($publisherNameSubfield);
		}

		// Specific fields for series.
		if ($bookType === 'series') {

			// Create series datafield node.
			$seriesMetadataNode = $this->dataFieldNode($deployment, $doc, 'series_metadata');

			// Create series titles, issn node, volume (series position).
			$seriesDao = Repo::section();
			$seriesId = $publication->getData('seriesId');
			if (!empty($seriesId)) {

				// Titles node.
				$series = $seriesDao->get($seriesId);
				$seriesTitle = $series->getLocalizedTitle();
				if (!$seriesTitle) {
					$this->addError(__('plugins.generic.crossref.missingElement', ['param1' => $pubObject->getId(), 'param2' => 'Series Title']));
				} else {
					$seriesTitlesNode = $this->dataFieldNode($deployment, $doc, 'titles');
					$seriesTitleSubfield = $this->subFieldNode($deployment, $doc, 'title', $seriesTitle);
					$seriesTitlesNode->appendChild($seriesTitleSubfield);
				}

				// ISSN node.
				$seriesISSN = $series->getOnlineISSN();
				if(!$seriesISSN) {
					$this->addError(__('plugins.generic.crossref.missingElement', ['param1' => $pubObject->getId(), 'param2' => 'Series ISSN']));
				} else {
					$seriesISSNNode = $this->dataFieldNode($deployment, $doc, 'issn',);
					$seriesISSNNode->appendChild($doc->createTextNode($seriesISSN));
				}
			
				// Series position node.
				$seriesPosition = $publication->getData('seriesPosition');
				if (!$seriesPosition) {
					$this->addError(__('plugins.generic.crossref.missingElement', ['param1' => $pubObject->getId(), 'param2' => 'Series Position']));
				} else {
					$seriesPositionNode = $this->dataFieldNode($deployment, $doc, 'volume');
					$seriesPositionNode->appendChild($doc->createTextNode($seriesPosition));
				}
			}

			// Append series titles and issn node.
			if ($seriesTitlesNode) {
				$seriesMetadataNode->appendChild($seriesTitlesNode);
			}
			if ($seriesISSNNode) {
				$seriesMetadataNode->appendChild($seriesISSNNode);
			}

			// Append to main metadata node.
			$bookMetadataNode->appendChild($seriesMetadataNode);
		}

		// Append nodes to metadata node.
		$bookMetadataNode->appendChild($titlesNode);
		if ($bookType === 'series' && $seriesPositionNode) {
			$bookMetadataNode->appendChild($seriesPositionNode);
		}

		// Append to book node.
		if ($publicationDateNode) {
			$bookMetadataNode->appendChild($publicationDateNode);
		}
		if ($publisherNode) {
			$bookMetadataNode->appendChild($publisherNode);
		}
		if ($doiDataNode) {
			$bookMetadataNode->appendChild($doiDataNode);
		}
		return $bookMetadataNode;
	}

	/**
	 * Generate the content item node for a chapter.
	 * @param NativeImportExportDeployment $deployment
	 * @param DOMElement $doc
	 * @param Chapter $chapter
	 * @param Submission $pubObject
	 * @return DOMElement
	 */
	function createContentItemNode($deployment, $doc, $chapter, $pubObject)
	{
		// Create content item node.
		$chapterNode = $this->dataFieldNode($deployment, $doc, 'content_item', ['component_type' => 'chapter', 'publication_type' => 'full_text']);

		// Contributors.
		$chapterAuthors = $chapter->getAuthors();
		$submissionId = $pubObject->getId();
		$publication = $pubObject->getCurrentPublication();
		$request = Application::get()->getRequest();
		$locale = $publication->getData('locale');
		if ($chapterAuthors->count() > 0) {
			$contributorsNode = $this->dataFieldNode($deployment, $doc, 'contributors');
			$isFirst = true;
			foreach ($chapterAuthors as $author) {

				// Distinguish between author and organization.
				$surname = $author->getFamilyName($locale);
				$givenName = $author->getGivenName($locale);
				if ($givenName && $surname) {
					if ($isFirst) {
						$authorNode = $this->dataFieldNode($deployment, $doc, 'person_name', ['sequence' => 'first', 'contributor_role' => 'author']);
						$isFirst = false;
					} else {
						$authorNode = $this->dataFieldNode($deployment, $doc, 'person_name', ['sequence' => 'additional', 'contributor_role' => 'author']);
					}
					$authorGivenNameSubfield = $this->subFieldNode($deployment, $doc, 'given_name', $givenName);
					$authorSurnameSubfield = $this->subFieldNode($deployment, $doc, 'surname', $surname);
					$authorNode->appendChild($authorGivenNameSubfield);
					$authorNode->appendChild($authorSurnameSubfield);
					$contributorsNode->appendChild($authorNode);
				} else if ($givenName && !$surname) {

					// If surname is missing/author has only one name, put it under the surname field as described here: 
					// https://www.crossref.org/documentation/schema-library/markup-guide-metadata-segments/contributors/
					if ($isFirst) {
						$authorNode = $this->dataFieldNode($deployment, $doc, 'person_name', ['sequence' => 'first', 'contributor_role' => 'author']);
						$isFirst = false;
					} else {
						$authorNode = $this->dataFieldNode($deployment, $doc, 'person_name', ['sequence' => 'additional', 'contributor_role' => 'author']);
					}
					$authorSurnameSubfield = $this->subFieldNode($deployment, $doc, 'surname', $givenName);
					$authorNode->appendChild($authorSurnameSubfield);
					$contributorsNode->appendChild($authorNode);
				}
			}
		}

		// Titles.
		$chapterTitle = $chapter->getTitle($locale);
		if ($chapterTitle) {
			$titlesNode = $this->dataFieldNode($deployment, $doc, 'titles');
			$titleSubfieldNode = $this->subFieldNode($deployment, $doc, 'title', $chapterTitle);
			$titlesNode->appendChild($titleSubfieldNode);
		}

		// Pages.
		$chapterPages = $chapter->getPages();
		if ($chapterPages) {
			$chapterPagesExploded = explode("-", $chapterPages);
			$firstPage = $chapterPagesExploded[0];
			$lastPage = $chapterPagesExploded[1] ? $chapterPagesExploded[1] : $chapterPagesExploded[0];
			$pagesNode = $this->dataFieldNode($deployment, $doc, 'pages');
			$firstPageSubnode = $this->subFieldNode($deployment, $doc, 'first_page', $firstPage);
			$lastPageSubnode = $this->subFieldNode($deployment, $doc, 'last_page', $lastPage);
			$pagesNode->appendChild($firstPageSubnode);
			$pagesNode->appendChild($lastPageSubnode);
		}

		// DOI Data node.
		$chapterDoi = $chapter->getDoi();
		if ($chapterDoi) {
			$request = Application::get()->getRequest();
			$url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'catalog', 'book', [$pubObject->getBestId(), 'chapter', $chapter->getSourceChapterId()]);
			$doiDataNode = $this->dataFieldNode($deployment, $doc, 'doi_data');
			$doiSubfield = $this->subFieldNode($deployment, $doc, 'doi', $chapterDoi);
			$resourceSubfield = $this->subFieldNode($deployment, $doc, 'resource', $url);
			$doiDataNode->appendChild($doiSubfield);
			$doiDataNode->appendChild($resourceSubfield);
		} else {
			$this->addError(__('plugins.generic.crossref.missingElement', ['param1' => $submissionId, 'param2' => 'Kapitel-DOI']));
		}

		// Append nodes to content item node.
		if ($contributorsNode) {
			$chapterNode->appendChild($contributorsNode);
		}
		if ($titlesNode) {
			$chapterNode->appendChild($titlesNode);
		}
		if ($pagesNode) {
			$chapterNode->appendChild($pagesNode);
		}
		if ($doiDataNode) {
			$chapterNode->appendChild($doiDataNode);
		}
		return $chapterNode;
	}
}
