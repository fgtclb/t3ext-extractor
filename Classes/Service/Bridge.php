<?php
namespace Causal\Extractor\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Xavier Perseguers <xavier@causal.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource;

/**
 * This is a bridge service between TYPO3 metadata extraction services
 * and FAL extractors for Local Driver.
 *
 * @category    Service
 * @package     TYPO3
 * @subpackage  tx_extractor
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class Bridge implements \TYPO3\CMS\Core\Resource\Index\ExtractorInterface {

	/**
	 * @var array
	 */
	static protected $serviceSubTypes = array();

	/**
	 * @var string
	 */
	protected $extKey = 'extractor';

	/**
	 * Returns an array of supported file types
	 * An empty array indicates all file types
	 *
	 * @return array
	 */
	public function getFileTypeRestrictions() {
		return array();
	}

	/**
	 * Gets all supported DriverTypes.
	 *
	 * Since some processors may only work for local files, and other
	 * are especially made for processing files from remote.
	 *
	 * Returns array of strings with driver names of Drivers which are supported,
	 * If the driver did not register a name, it's the class name.
	 * empty array indicates no restrictions
	 *
	 * @return array
	 */
	public function getDriverRestrictions() {
		return array(
			'Local',
		);
	}

	/**
	 * Returns the data priority of the processing Service.
	 * Defines the precedence if several processors
	 * can handle the same file.
	 *
	 * Should be between 1 and 100, 100 is more important than 1
	 *
	 * @return integer
	 */
	public function getPriority() {
		return 50;
	}

	/**
	 * Returns the execution priority of the extraction Service.
	 * Should be between 1 and 100, 100 means runs as first service, 1 runs at last service.
	 *
	 * @return integer
	 */
	public function getExecutionPriority() {
		return 50;
	}

	/**
	 * Checks if the given file can be processed by this Extractor
	 *
	 * @param Resource\File $file
	 * @return boolean
	 */
	public function canProcess(Resource\File $file) {
		$serviceSubTypes = $this->findServiceSubTypesByExtension($file->getProperty('extension'));
		return count($serviceSubTypes) > 0;
	}

	/**
	 * The actual processing TASK.
	 *
	 * Should return an array with database properties for sys_file_metadata to write
	 *
	 * @param Resource\File $file
	 * @param array $previousExtractedData optional, contains the array of already extracted data
	 * @return array
	 */
	public function extractMetaData(Resource\File $file, array $previousExtractedData = array()) {
		$serviceSubTypes = $this->findServiceSubTypesByExtension($file->getProperty('extension'));

		$metadata = array();
		foreach ($serviceSubTypes as $serviceSubType) {
			$data = $this->getMetadata($file, $serviceSubType);

			// Existing data has precedence over new information, due to service's precedence
			$metadata = array_merge($data, $metadata);
		}

		return $metadata;
	}

	/**
	 * Returns an array of available serviceSubType keys providing metadata extraction
	 * for a given file extension.
	 *
	 * @param string $extension
	 * @return array
	 */
	protected function findServiceSubTypesByExtension($extension) {
		if (isset(static::$serviceSubTypes[$extension])) {
			return static::$serviceSubTypes[$extension];
		}

		$serviceSubTypes = array();
		$service = GeneralUtility::makeInstanceService('metaExtract', $extension);
		if (is_object($service)) {
			$serviceSubTypes[] = $extension;
		}
		unset($service);

		if (GeneralUtility::inList('jpg,jpeg,tif,tiff', $extension)) {
			$alternativeServiceSubType = array('image:iptc', 'image:exif');
			foreach ($alternativeServiceSubType as $alternativeServiceSubType) {
				$service = GeneralUtility::makeInstanceService('metaExtract', $alternativeServiceSubType);
				if (is_object($service)) {
					$serviceSubTypes[] = $alternativeServiceSubType;
				}
				unset($service);
			}
		}

		static::$serviceSubTypes[$extension] = $serviceSubTypes;
		return $serviceSubTypes;
	}

	/**
	 * Returns metadata for a given file.
	 *
	 * @param Resource\File $file
	 * @param array $serviceSubType
	 * @return array
	 */
	protected function getMetadata(Resource\File $file, $serviceSubType) {
		$data = array();
		$serviceChain = '';

		$pathConfiguration = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($this->extKey) . 'Configuration/Services/';

		/** @var \TYPO3\CMS\Core\Service\AbstractService $serviceObj */
		while (is_object($serviceObj = GeneralUtility::makeInstanceService('metaExtract', $serviceSubType, $serviceChain))) {
			$serviceChain .= ',' . $serviceObj->getServiceKey();

			$mappingFilename = $pathConfiguration . $serviceObj->getServiceKey() . '/' . str_replace(':', '_', $serviceSubType) . '.json';
			if (!is_file($mappingFilename)) {
				// Try a default mapping
				$mappingFilename = $pathConfiguration . $serviceObj->getServiceKey() . '/default.json';
			}
			if (!is_file($mappingFilename)) {
				continue;
			}

			$dataMapping = json_decode(file_get_contents($mappingFilename), TRUE);
			if (!is_array($dataMapping)) {
				continue;
			}

			$fileName = $file->getForLocalProcessing(FALSE);
			$serviceObj->setInputFile($fileName, $file->getProperty('extension'));

			if ($serviceObj->process()) {
				$output = $serviceObj->getOutput();
				if (is_array($output)) {
					$output = $this->remapServiceOutput($output, $dataMapping);

					// Existing data has precedence over new information, due to service's precedence
					$data = array_merge_recursive($output, $data);
				}
			}
		}

		return $data;
	}

	/**
	 * Remaps $data coming from a service to a FAL-compliant array.
	 *
	 * @param array $data
	 * @param array $mapping
	 * @return array
	 */
	protected function remapServiceOutput(array $data, array $mapping) {
		$output = array();

		foreach ($mapping as $m) {
			$falKey = $m['FAL'];
			$alternativeKeys = $m['DATA'];
			if (!is_array($alternativeKeys)) {
				$alternativeKeys = array($alternativeKeys);
			}

			$value = NULL;
			foreach ($alternativeKeys as $dataKey) {
				list($compoundKey, $processor) = explode('->', $dataKey);
				$keys = explode('|', $compoundKey);
				$value = $data;
				foreach ($keys as $key) {
					if (substr($key, 0, 7) === 'static:') {
						$value = substr($key, 7);
					} else {
						$value = isset($value[$key]) ? $value[$key] : NULL;
					}
					if ($value === NULL) {
						break;
					}
				}
				if (isset($processor)) {
					$value = call_user_func($processor, $value);
				}
				if ($value !== NULL) {
					// Do not try any further alternative key, we have a value
					break;
				}
			}

			if ($value !== NULL) {
				$output[$falKey] = $value;
			}
		}

		return $output;
	}

}
