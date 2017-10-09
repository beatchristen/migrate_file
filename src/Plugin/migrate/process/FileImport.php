<?php

namespace Drupal\migrate_file\Plugin\migrate\process;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrateProcessInterface;
use Drupal\migrate\Plugin\migrate\process\FileCopy;
use Drupal\migrate\Row;
use Drupal\file\Entity\File;
use GuzzleHttp\Exception\ClientException;

/**
 * Imports a file from an local or external source.
 *
 * Files will be downloaded or copied from the source if necessary and a file
 * entity will be created for it. The file can be moved, reused, or set to be
 * automatically renamed if a duplicate exists.
 *
 * Required configuration keys:
 * - source: The source path or URI, e.g. '/path/to/foo.txt' or
 *   'public://bar.txt'.
 *
 * Optional configuration keys:
 * - destination: (recommended) The destination path or URI, e.g. '/path/to/bar/' or
 *   'public://foo.txt'. To provide a directory path (to which the file is saved
 *   using its original name), a trailing slash *must* be used to differentiate
 *   it from being a filename. If no trailing slash is provided the path will be
 *   assumed to be the destination filename. Defaults to "public://"
 * - uid: The uid to attribute the file entity to. Defaults to 0
 * - move: Boolean, if TRUE, move the file, otherwise copy the file. Only
 *   applies if the source file is local. If the source file is remote it will
 *   be copied. Defaults to FALSE.
 * - rename: Boolean, if TRUE, rename the file by appending a number
 *   until the name is unique. Defaults to FALSE.
 * - reuse: Boolean, if TRUE, reuse the current file in its existing
 *   location rather than move/copy/rename the file. Defaults to FALSE.
 * - skip_on_missing_source: (optional) Boolean, if TRUE, this field will be
 *   skipped if the source file is missing (either not available locally or 404
 *   if it's a remote file). Otherwise, the row will fail with an error.
 *   Defaults to FALSE
 * - id_only: (optional) Boolean, if TRUE, the process will return just the id
 *   instead of a entity reference array. Useful if you want to manage other
 *   sub-fields in your migration (see example below).
 *
 * The destination and uid configuration fields support copying destination
 * values. These are indicated by a starting @ sign. Values using @ must be
 * wrapped in quotes. (the same as it works with the 'source' key)
 *
 * @see Drupal\migrate\Plugin\migrate\process\Get
 *
 * Example:
 *
 * @code
 * destination:
 *   plugin: entity:node
 * source:
 *   # assuming we're using a source plugin that lets us define fields like this
 *   fields:
 *     -
 *       name: file
 *       label: 'Some file'
 *       selector: /file
 *     -
 *       name: image
 *       label: 'Main Image'
 *       selector: /image
 *   constants:
 *     file_destination: 'public://path/to/save/'
 * process:
 *   uid:
 *     plugin: default_value
 *     default_value: 1
 *   # Simple file import
 *   field_file:
 *     plugin: file_import
 *     source: file
 *     destination: constants/file_destination
 *     uid: @uid
 *     skip_on_missing_source: true
 *   # Custom field attributes
 *   field_image/target_id:
 *     plugin: file_import
 *     source: image
 *     destination: constants/file_destination
 *     uid: @uid
 *     id_only: true
 *   field_image/alt: image
 *
 *
 * @endcode
 *
 * @see \Drupal\migrate\Plugin\MigrateProcessInterface
 *
 * @MigrateProcessPlugin(
 *   id = "file_import"
 * )
 */
class FileImport extends FileCopy {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, StreamWrapperManagerInterface $stream_wrappers, FileSystemInterface $file_system, MigrateProcessInterface $download_plugin) {
    $configuration += [
      'destination' => NULL,
      'uid' => NULL,
      'skip_on_missing_source' => FALSE,
      'id_only' => FALSE,
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition, $stream_wrappers, $file_system, $download_plugin);
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    if (!$value) {
      return NULL;
    }

    // Get our file entity values
    $source = $value;
    $destination = $this->getPropertyValue($this->configuration['destination'], $row) ?: 'public://';
    $uid = $this->getPropertyValue($this->configuration['uid'], $row) ?: 0;
    $id_only = $this->configuration['id_only'];

    // If there's no we skip
    if (!$source) {
      return NULL;
    }
    elseif ($this->configuration['skip_on_missing_source'] && !$this->sourceExists($source)) {
      // If we have a source file path, but it doesn't exist, and we're meant
      // to just skip processing, we do so, but we log the message.
      $migrate_executable->saveMessage("Source file $source does not exist. Skipping.");
      return NULL;
    }

    // Build the destination file uri (in case only a directory was provided)
    $destination = $this->getDestinationFilePath($source, $destination);
    if (!$this->fileSystem->uriScheme($destination)) {
      if (empty($destination)) {
        $destination = file_default_scheme() . '://' . preg_replace('/^\//' ,'', $destination);
      }
    }
    $final_destination = '';

    // If we're in re-use mode, reuse the file if it exists
    if ($this->getOverwriteMode() == FILE_EXISTS_ERROR && $this->isLocalUri($source) && is_file($destination)) {
      // Look for a file entity with the destination uri
      if ($files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $destination])) {
        // @todo do we want to reuse actual file entites?
        $file = reset($files);
        return $id_only ? $file->id() : ['target_id' => $file->id()];
      }
      else {
        $final_destination = $destination;
      }
    }
    else {
      // The parent method will take care of our download/move/copy/rename
      // We just need to final destination to create the file object
      $final_destination = parent::transform([$source, $destination], $migrate_executable, $row, $destination_property);
    }

    if ($final_destination) {
      // Create a file entity.
      $file = File::create([
        'uri' => $final_destination,
        'uid' => $uid,
        'status' => FILE_STATUS_PERMANENT,
      ]);
      $file->save();
      return $id_only ? $file->id() : ['target_id' => $file->id()];
    }

    throw new MigrateException("File $source could not be imported to $destination");
  }

  /**
   * Gets a value from a source or destination property.
   *
   * Code is adapted from Drupal\migrate\Plugin\migrate\process\Get::transform()
   */
  protected function getPropertyValue($property, $row) {
    if ($property || (string) $property === '0') {
      $is_source = TRUE;
      if ($property[0] == '@') {
        $property = preg_replace_callback('/^(@?)((?:@@)*)([^@]|$)/', function ($matches) use (&$is_source) {
          // If there are an odd number of @ in the beginning, it's a
          // destination.
          $is_source = empty($matches[1]);
          // Remove the possible escaping and do not lose the terminating
          // non-@ either.
          return str_replace('@@', '@', $matches[2]) . $matches[3];
        }, $property);
      }
      if ($is_source) {
        return $row->getSourceProperty($property);
      }
      else {
        return $row->getDestinationProperty($property);
      }
    }
    return FALSE;
  }

  /**
   * Determines how to handle file conflicts.
   *
   * @return int
   *   FILE_EXISTS_REPLACE (default), FILE_EXISTS_RENAME, or FILE_EXISTS_ERROR
   *   depending on the current configuration.
   */
  protected function getOverwriteMode() {
    if (!empty($this->configuration['rename'])) {
      return FILE_EXISTS_RENAME;
    }
    if (!empty($this->configuration['reuse'])) {
      return FILE_EXISTS_ERROR;
    }

    return FILE_EXISTS_REPLACE;
  }

  /**
   * Check if a path is a meant to be a directory.
   *
   * We're using a trailing slash to indicate the path is a directory. This is
   * so that we can create it if it doesn't exist. Without the trailing slash
   * there would be no reliable way to know whether or not the path is meant
   * to be the target filename since files don't technically _have_ to have
   * extensions, and directory names can contain periods.
   */
  protected function isDirectory($path) {
    return substr($path, -1) == '/';
  }

  /**
   * Build the destination filename.
   *
   * @param string $source
   *   The source URI
   *
   * @param string $destination
   *   The destination URI
   *
   * @return boolean
   *   Whether or not the file exists.
   */
  protected function getDestinationFilePath($source, $destination) {
    if ($this->isDirectory($destination)) {
      $parsed_url = parse_url($source);
      $filepath = $destination . drupal_basename($parsed_url['path']);
    }
    else {
      $filepath = $destination;
    }
    return $filepath;
  }

  /**
   * Check if a source exists.
   */
  protected function sourceExists($path) {
    if ($this->isLocalUri($path)) {
      return is_file($path);
    }
    else {
      try {
        \Drupal::httpClient()->head($path);
        return TRUE;
      }
      catch (ClientException $e) {
        return FALSE;
      }
    }
  }

}
