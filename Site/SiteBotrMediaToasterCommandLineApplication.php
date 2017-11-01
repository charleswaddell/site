<?php

/**
 * Abstract application for applications that access media on bits on the run.
 *
 * @package   Site
 * @copyright 2011-2016 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 * @todo      do something better with the isMarkedX() methods. Maybe move the
 *            tags to constants.
 */
abstract class SiteBotrMediaToasterCommandLineApplication extends SiteCommandLineApplication
{
	// {{{ public properties

	/**
	 * A convenience reference to the database object
	 *
	 * @var MDB2_Driver
	 */
	public $db;

	// }}}
	// {{{ protected properties

	/**
	 * A SiteBotrMediaToaster Object for accessing and updating video on BOTR
	 *
	 * @var SiteBotrMediaToaster
	 */
	protected $toaster;

	/**
	 * Whether or not to reset the tags used by the script on files that have
	 * already been tagged.
	 *
	 * If true, this will return media to a virgin state for the calling script.
	 *
	 * @var boolean
	 */
	protected $reset_tags;

	/**
	 * Array of source files to validate.
	 *
	 * @var array
	 */
	protected $source_files = array();

	/**
	 * Directory where source files are stored.
	 *
	 * @var string
	 */
	protected $source_directory;

	/**
	 * Array of all media returned from Botr
	 *
	 * @var array
	 */
	protected $media;

	/**
	 * Array of media dataobjects that exist in the site's database
	 *
	 * Indexed by BOTR key.
	 *
	 * @var array
	 */
	protected $media_objects = array();

	// Ignore these lines because the link extends the max line width
	// @codingStandardsIgnoreStart
	/**
	 * Search filters when looking up BOTR media by API.
	 *
	 * An array of search terms when doing the Botr API call, with the array key
	 * as the field to search and the array value as the search string. See
	 * {@link http://developer.longtailvideo.com/botr/system-api/methods/videos/list.html BOTR Documentation}
	 * for details on writing search filters.
	 *
	 * @var array
	 */
	// @codingStandardsIgnoreEnd
	protected $search_filters = array();

	/**
	 * Search filters to exclude by when looking up BOTR media by API.
	 *
	 * An array of search terms when doing the Botr API call, with the array key
	 * as the field to search and the array value as the search string. As BOTR
	 * does not support any concept of filtering negatively, this are run after
	 * we lookup the media, and fake the behaviour expected.
	 *
	 * @var array
	 */
	protected $exclusion_filters = array();

	/**
	 * Various tags added to metadata on BOTR to mark status of video.
	 *
	 * @var string
	 */
	protected $valid_tag_filesize   = 'validated.filesize';
	protected $valid_tag_md5        = 'validated.md5';
	protected $invalid_tag_filesize = 'invalid.filesize';
	protected $invalid_tag_md5      = 'invalid.md5';
	protected $original_missing_tag = 'original_missing';
	protected $duplicate_tag        = 'duplicate';
	protected $encoded_tag          = 'encoded';
	protected $imported_tag         = 'imported';
	protected $delete_tag           = 'delete';
	protected $ignored_tag          = 'ignored';
	protected $original_deleted_tag = 'original.deleted';

	protected $locale;

	// }}}
	// {{{ public function __construct()

	public function __construct($id, $filename, $title, $documentation)
	{
		parent::__construct($id, $filename, $title, $documentation);

		$instance = new SiteCommandLineArgument(array('-i', '--instance'),
			'setInstance', 'Optional. Sets the site instance for which to '.
			'run this application.');

		$instance->addParameter('string',
			'instance name must be specified.');

		$this->addCommandLineArgument($instance);

		$reset_tags = new SiteCommandLineArgument(
			array('--reset-tags'),
			'setResetTags',
			'Optional. Resets tags used by the script on the media.');

		$this->addCommandLineArgument($reset_tags);

		$this->initModules();
		$this->parseCommandLineArguments();

		$this->locale = SwatI18NLocale::get();
	}

	// }}}
	// {{{ public function setInstance()

	public function setInstance($shortname)
	{
		putenv(sprintf('instance=%s', $shortname));
		$this->instance->init();
		$this->config->init();
	}

	// }}}
	// {{{ public function setResetTags()

	public function setResetTags()
	{
		$this->reset_tags = true;
	}

	// }}}
	// {{{ public function addSearchFilter()

	public function addSearchFilter($search_term, $search_field = '*')
	{
		$this->search_filters[$search_field] = $search_term;
	}

	// }}}
	// {{{ public function addExclusionFilter()

	public function addExclusionFilter($search_term, $search_field = '*')
	{
		$this->exclusion_filters[$search_field] = $search_term;
	}

	// }}}
	// {{{ public function run()

	/**
	 * Runs this application
	 */
	public function run()
	{
		$this->lock();

		$this->initInternal();

		if ($this->reset_tags) {
			$this->resetTags();
		}

		$this->runInternal();

		$this->debug("All done.\n", true);

		$this->unlock();
	}

	// }}}
	// {{{ public function setSourceDirectory()

	public function setSourceDirectory($directory)
	{
		$this->source_directory = $directory;
	}

	// }}}
	// {{{ abstract protected function runInternal()

	abstract protected function runInternal();

	// }}}

	// init phase
	// {{{ protected function initInternal()

	protected function initInternal()
	{
		$this->initToaster();
	}

	// }}}
	// {{{ protected function initToaster()

	protected function initToaster()
	{
		$this->toaster = new SiteBotrMediaToaster($this);
	}

	// }}}

	// run phase
	// {{{ protected function resetTags()

	protected function resetTags()
	{
		$media = $this->getMedia();
		$tags  = $this->getResetTags();

		if (count($tags)) {
			foreach ($media as $media_file) {
				$this->toaster->updateMediaRemoveTagsByKey($media_file['key'],
					$tags);
			}

			$this->resetMediaCache();

			$this->debug(sprintf(
				"Reset %s tags for %s media files on BOTR.\n",
				SwatString::toList(array_values($this->getResetTags()), '&',
					', ', false),
				$this->locale->formatNumber(count($media))));
		}
	}

	// }}}
	// {{{ protected function getResetTags()

	protected function getResetTags()
	{
		return array();
	}

	// }}}

	// helper methods
	// {{{ protected function getSourceFiles()

	protected function getSourceFiles()
	{
		if (count($this->source_files) == 0) {
			$iterator = new RecursiveDirectoryIterator(
				$this->getSourceDirectory()
			);

			// Only call the flag if it exists.  FilesystemIterator does not
			// exist prior to PHP 5.3
			if ($iterator instanceof FilesystemIterator) {
				$iterator->setFlags(FilesystemIterator::SKIP_DOTS);
			}

			// only grab media types we upload.
			$valid_extensions = array(
				'mp4',
				'mov',
				'm4v',
				'm4a',
				'mp3',
				);

			// only add videos if they belong to certain directories
			$valid_directories = array(
				'video',
				'videos',
				'samples',
				'video-extras',
				);

			foreach (new RecursiveIteratorIterator($iterator) as
				$path => $file) {
				// Catch exception and just treat as a file that needs further
				// investigation.
				$key = $file->getFileName();

				// ignore .DS_Store and ._ files
				$skip = ((mb_substr($key, 0, 2) == '._') || $key == '.DS_Store');

				$extension = $this->getExtension($key);
				$directory = end(explode('/', $file->getPath()));

				if ($skip === false &&
					array_search($extension, $valid_extensions) !== false &&
					array_search($directory, $valid_directories) !== false) {
					$old_error_handler =
						set_error_handler('SourceFileErrorHandler');

					try {
						$this->addSourceFile($key, $path, $file);
					} catch(Exception $e) {
						$this->handleSourceFileException($key, $path, $file,
							$e);
					}

					restore_error_handler();
				}
			}
		}

		return $this->source_files;
	}

	// }}}
	// {{{ protected function addSourceFile()

	protected function addSourceFile($key, $path, SplFileInfo $file)
	{
		if (isset($this->source_files[$key])) {
			throw new SiteBotrMediaCommandLineSourceFileException(
				sprintf(
					"Source file ‘%s’ duplicate.\nVersion 1: %s\n Version 2: %s",
					$key,
					$path,
					$this->source_files[$key]['path']
				)
			);
		}

		$this->source_files[$key]['path'] = $path;
		$this->source_files[$key]['md5']  = $this->getMd5($path);
	}

	// }}}
	// {{{ protected function getExtension()

	protected function getExtension($filename)
	{
		$extension = null;

		$info = pathinfo($filename);
		if (isset($info['extension'])) {
			$extension = $info['extension'];
		}

		return $extension;
	}

	// }}}
	// {{{ protected function getMd5()

	protected function getMd5($path)
	{
		// md5 is crazy slow on the large video files, so load from an external
		// md5 file that only needs to be generated once.
		$md5_filename = str_replace($this->getExtension($path), 'md5', $path);

		if (file_exists($md5_filename)) {
			$md5 = file_get_contents($md5_filename);
		} else {
			$md5 = md5_file($path);
			file_put_contents($md5_filename, $md5);
		}

		return $md5;
	}

	// }}}
	// {{{ protected function handleSourceFileException()

	protected function handleSourceFileException(
		$key,
		$path,
		SplFileInfo $file,
		Exception $e
	) {
		// do nothing by default.
	}

	// }}}
	// {{{ protected function getSourceDirectory()

	protected function getSourceDirectory()
	{
		$directory = $this->source_directory;

		if ($this->getInstance() !== null) {
			$directory.= '/'.$this->getInstance()->shortname;
		}

		return $directory;
	}

	// }}}
	// {{{ protected function getMedia()

	protected function getMedia(array $options = array())
	{
		// local options come second so they overwrite any defaults.
		$options = array_merge(
			$this->getDefaultMediaOptions(),
			$this->getSearchFilterOptions(),
			$options);

		if ($this->media === null) {
			$this->media = array();

			$media = $this->toaster->listMedia($options);

			foreach ($media as $media_file) {
				if (!$this->excludeMediaFile($media_file)) {
					$this->media[$media_file['key']] = $media_file;
				}
			}
		}

		return $this->media;
	}

	// }}}
	// {{{ protected function excludeMediaFile()

	/**
	 * Fake expected behaviour for negative filtering.
	 *
	 * This may make more sense in SiteBotrMediaToaster. If so it should be
	 * improved to match the various rules BOTR's API supports when searching.
	 *
	 * @return boolean whether or not the row should be filtered from the
	 *                  results
	 */
	protected function excludeMediaFile(array $media_file)
	{
		$exclude = false;

		foreach ($this->exclusion_filters as $filter_key => $filter_term) {
			if ($filter_key == '*') {
				// TODO: search recursively through any arrays.
				foreach ($media_file as $key => $value) {
					if (mb_stripos($value, $filter_term) !== false) {
						$exclude = true;
						break;
					}
				}
			} else {
				// only need to support one level of nested search terms.
				$filter_key_parts = explode('.', $filter_key, 2);

				if (count($filter_key_parts) > 1) {
					if (isset(
							$media_file[$filter_key_parts[0]][$filter_key_parts[1]]
						) && mb_stripos(
							$media_file[$filter_key_parts[0]][$filter_key_parts[1]],
							$filter_term
						) !== false) {
						$exclude = true;
					}
				} else {
					if (array_key_exists($key, $media_file)) {
						if (mb_stripos($attribute_value, $filter_term) !== false) {
							$exclude = true;
						}
					}
				}
			}
		}

		return $exclude;
	}

	// }}}
	// {{{ protected function getDefaultMediaOptions()

	protected function getDefaultMediaOptions()
	{
		$options = array(
			'statuses_filter' => 'ready',
		);

		if (count($this->search_filters)) {
			foreach ($this->search_filters as $field => $term) {
				$options['search:'.$field] = $term;
			}
		}

		return $options;
	}

	// }}}
	// {{{ protected function getSearchFilterOptions()

	protected function getSearchFilterOptions()
	{
		$options = array();

		if (count($this->search_filters)) {
			foreach ($this->search_filters as $field => $term) {
				$options['search:'.$field] = $term;
			}
		}

		return $options;
	}

	// }}}
	// {{{ protected function getMediaObjects()

	protected function getMediaObjects()
	{
		if ($this->media_objects == null) {
			$sql = $this->getMediaObjectSql();

			$objects = SwatDB::query($this->db, $sql,
				SwatDBClassMap::get('SiteBotrMediaWrapper')
			);

			$this->media_objects = array();
			foreach ($objects as $object) {
				$this->media_objects[$object->key] = $object;
			}
		}

		return $this->media_objects;
	}

	// }}}
	// {{{ protected function getMediaObjectSql()

	protected function getMediaObjectSql()
	{
		$where = $this->getMediaObjectWhere();
		$join  = $this->getMediaObjectJoin();

		// Treat key as a sign it's BOTR media in the DB.
		$sql = sprintf(
			'select Media.*
			from Media %s
			where 1=1 and key %s %s %s
			order by Media.id',
			$join,
			SwatDB::equalityOperator(null, true),
			$this->db->quote(null, 'integer'),
			$where
		);

		return $sql;
	}

	// }}}
	// {{{ protected function getMediaObjectWhere()

	protected function getMediaObjectWhere()
	{
		return null;
	}

	// }}}
	// {{{ protected function getMediaObjectJoin()

	protected function getMediaObjectJoin()
	{
		return null;
	}

	// }}}
	// {{{ protected function resetMediaCache()

	protected function resetMediaCache()
	{
		$this->media = null;
	}

	// }}}
	// {{{ protected function mediaFileIsMarkedValid()

	protected function mediaFileIsMarkedValid(array $media_file)
	{
		$valid = false;

		// Only needs one valid tag to be considered valid.
		if ((
				$this->hasTag($media_file, $this->valid_tag_filesize) ||
				$this->hasTag($media_file, $this->valid_tag_md5)
			) &&
			!(
				$this->hasTag($media_file, $this->invalid_tag_filesize) ||
				$this->hasTag($media_file, $this->invalid_tag_md5)
			)) {
			$valid = true;
		}

		return $valid;
	}

	// }}}
	// {{{ protected function mediaFileIsMarkedInvalid()

	protected function mediaFileIsMarkedInvalid(array $media_file)
	{
		$invalid = false;

		// Only needs one invalid tag to be considered invalid. Note that this
		// is not just the inverse of mediaFileIsMarkedValid() because
		// mediaFileIsMarkedValid() checks for "valid" tags and when we're
		// checking for invalid files we don't care about those.
		if ($this->hasTag($media_file, $this->invalid_tag_filesize) ||
			$this->hasTag($media_file, $this->invalid_tag_md5)) {
			$invalid = true;
		}

		return $invalid;
	}

	// }}}
	// {{{ protected function mediaFileIsMarkedEncoded()

	protected function mediaFileIsMarkedEncoded(array $media_file)
	{
		$encoded = false;

		if ($this->hasTag($media_file, $this->encoded_tag)) {
			$encoded = true;
		}

		return $encoded;
	}

	// }}}
	// {{{ protected function mediaFileIsPublic()

	protected function mediaFileIsPublic(array $media_file)
	{
		$public = false;

		if ($this->hasTag($media_file, $this->ignored_tag)) {
			$public = true;
		}

		return $public;
	}

	// }}}
	// {{{ protected function mediaFileIsIgnorable()

	protected function mediaFileIsIgnorable(array $media_file)
	{
		$ignorable = false;

		if ($this->hasTag($media_file, $this->delete_tag) ||
			$this->hasTag($media_file, $this->ignored_tag)) {
			$ignorable = true;
		}

		return $ignorable;
	}

	// }}}
	// {{{ protected function mediaFileIsMarkedDeleted()

	protected function mediaFileIsMarkedDeleted(array $media_file)
	{
		$deleted = false;

		if ($this->hasTag($media_file, $this->delete_tag)) {
			$deleted = true;
		}

		return $deleted;
	}

	// }}}
	// {{{ protected function mediaFileOriginalIsDownloadable()

	protected function mediaFileOriginalIsDownloadable(array $media_file)
	{
		$downloadable = false;

		// Only download the originals for those marked with the original
		// missing. Ignore videos that aren't imported or are marked to be
		// deleted.
		if (!$this->hasTag($media_file, $this->delete_tag) &&
			$this->hasTag($media_file, $this->imported_tag) &&
			$this->hasTag($media_file, $this->original_missing_tag)) {
			$downloadable = true;
		}

		return $downloadable;
	}

	// }}}
	// {{{ protected function hasTag()

	protected function hasTag(array $media_file, $tag)
	{
		$tags = explode(',', str_replace(' ', '', $media_file['tags']));

		return in_array($tag, $tags);
	}

	// }}}
	// {{{ protected function download($from, $to)

	protected function download(
		$source,
		$destination,
		$prefix = null,
		$filesize_to_check = null
	) {
		if ($filesize_to_check > PHP_INT_MAX) {
			throw new SiteBotrMediaCommandLineDownloadException(
				sprintf(
					'File too large to download %s.',
					SwatString::byteFormat($filesize_to_check)
				)
			);
		}

		if (file_exists($destination)) {
			throw new SiteBotrMediaCommandLineFileExistsException(
				sprintf(
					'File already exists “%s”.',
					$destination
				)
			);
		}

		// separate the prefix with a dash for prettier temp filenames
		if ($prefix !== null) {
			$prefix.= '-';
		}

		// add SiteBotrMedia to the prefix for easier searching on the temp
		// filename.
		$prefix    = 'SiteBotrMedia-'.$prefix;
		$info      = pathinfo($destination);
		$directory = $info['dirname'];
		$temp_file = tempnam(sys_get_temp_dir(), $prefix);

		if (!file_exists($directory) && !mkdir($directory, 0777, true)) {
			throw new SiteBotrMediaCommandLineDownloadException(
				sprintf(
					'Unable to create directory “%s”.',
					$directory
				)
			);
		}

		if (!copy($source, $temp_file)) {
			throw new SiteBotrMediaCommandLineDownloadException(
				sprintf(
					'Unable to download “%s” to “%s”.',
					$source,
					$temp_file
				)
			);
		}

		if ($filesize_to_check < PHP_INT_MAX) {
			$local_filesize = filesize($temp_file);
			if ($local_filesize != $filesize_to_check) {
				unlink($temp_file);
				throw new SiteBotrMediaCommandLineDownloadException(
					sprintf(
						"Downloaded file size mismatch\n".
						"%s bytes on BOTR\n".
						"%s bytes locally.",
						$filesize_to_check,
						$local_filesize
					)
				);
			}
		}

		if (!rename($temp_file, $destination)) {
			throw new SiteBotrMediaCommandLineDownloadException(
				sprintf(
					'Unable to move “%s” to “%s”.',
					$temp_file,
					$destination
				)
			);
		}

		// update permissions (-rw-rw----)
		if (!chmod($destination, 0660)) {
			throw new SiteBotrMediaCommandLineDownloadException(
				sprintf(
					'Unable to change permissions on “%s”.',
					$destination
				)
			);
		}

		return true;
	}

	// }}}
	// {{{ protected function setOriginalFilenames()

	protected function setOriginalFilenames()
	{
		$media = $this->getMedia();
		$count = 0;

		$this->debug('Updating missing original filenames... ');

		foreach ($media as $media_file) {
			if (!isset($media_file['custom']['original_filename'])) {
				$count++;
				// save fields on Botr.
				$values = array(
					'custom' => array(
						'original_filename' =>
							$this->getOriginalFilename($media_file),
						),
					);

				$this->toaster->updateMediaByKey($media_file['key'], $values);
			}
		}

		// reset the cache so that its up to date with the original filenames
		// when we use it later.
		if ($count) {
			$this->resetMediaCache();
		}

		$this->debug(sprintf("%s updated.\n",
			$this->locale->formatNumber($count)));
	}

	// }}}
	// {{{ protected function getOriginalFilename()

	protected function getOriginalFilename(array $media_file)
	{
		return (isset($media_file['custom']['original_filename'])) ?
			$media_file['custom']['original_filename'] :
			trim($media_file['title']);
	}

	// }}}

	// boilerplate code
	// {{{ protected function getDefaultModuleList()

	protected function getDefaultModuleList()
	{
		return array_merge(
			parent::getDefaultModuleList(),
			[
				'database' => SiteDatabaseModule::class,
				'instance' => SiteMultipleInstanceModule::class,
			]
		);
	}

	// }}}
	// {{{ protected function configure()

	protected function configure(SiteConfigModule $config)
	{
		parent::configure($config);

		$this->database->dsn = $config->database->dsn;
	}

	// }}}
}

// {{{ function SourceFileErrorHandler()

function SourceFileErrorHandler($errno, $errstr, $errfile, $errline)
{
	throw new SiteBotrMediaCommandLineException(
		$errstr,
		$errno,
		0,
		$errfile,
		$errline
	);
}

// }}}

?>
