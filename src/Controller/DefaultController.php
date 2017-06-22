<?php /**
 * @file
 * Contains \Drupal\file_resup\Controller\DefaultController.
 */

namespace Drupal\file_resup\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Default controller for the file_resup module.
 */
class DefaultController extends ControllerBase {

  public function file_resup_upload() {
    // Get the form build ID.
    $form_parents = func_get_args();
    $form_build_id = (string) array_pop($form_parents);
    if (empty($_REQUEST['form_build_id']) || $form_build_id != $_REQUEST['form_build_id']) {
      drupal_exit();
    }

    // Get a valid upload ID.
    if (empty($_REQUEST['resup_file_id']) || !($upload_id = file_resup_upload_id($_REQUEST['resup_file_id']))) {
      drupal_exit();
    }

    // On method GET...
    if ($_SERVER['REQUEST_METHOD'] == 'GET') {
      // Attempt to find a record for the upload.
      $upload = file_resup_upload_load($upload_id);

      // If found, return how many chunks were uploaded so far.
      if ($upload) {
        return file_resup_plain_output($upload->uploaded_chunks);
      }

      // If not, prepare a new upload.
      // Get the form.
      $_POST['form_build_id'] = $form_build_id;
      list($form) = ajax_get_form();

      // Get the form element.
      $element = \Drupal\Component\Utility\NestedArray::getValue($form, $form_parents);
      if (!$element) {
        drupal_exit();
      }

      // Retrieve the file name and size.
      if (empty($_GET['resup_file_name']) || empty($_GET['resup_file_size'])) {
        drupal_exit();
      }
      $filename = $_GET['resup_file_name'];
      $filesize = $_GET['resup_file_size'];

      // Validate the file name length.
      if (strlen($filename) > 240) {
        drupal_exit();
      }

      // Validate the file extension.
      if (isset($element['#file_resup_upload_validators']['file_validate_extensions'][0])) {
        $regex = '/\.(?:' . preg_replace('/ +/', '|', preg_quote($element['#file_resup_upload_validators']['file_validate_extensions'][0])) . ')$/i';
        if (!preg_match($regex, $filename)) {
          drupal_exit();
        }
      }

      // Validate the file size.
      if (!preg_match('`^[1-9]\d*$`', $filesize) || $filesize > $element['#file_resup_upload_validators']['file_validate_size'][0]) {
        drupal_exit();
      }

      // Retrieve the upload location scheme from the form element.
      $scheme = \Drupal::service("file_system")->uriScheme($element['#upload_location']);
      if (!$scheme || !\Drupal::service("file_system")->validScheme($scheme)) {
        drupal_exit();
      }

      // Prepare the file_resup_temporary private directory.
      $directory = $scheme . '://' . FILE_RESUP_TEMPORARY;
      if (!file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
        drupal_exit();
      }
      file_create_htaccess($directory, TRUE);

      // Insert a new upload record.
      $upload = new stdClass();
      $upload->upload_id = $upload_id;
      $upload->filename = $filename;
      $upload->filesize = $filesize;
      $upload->scheme = $scheme;
      $upload->timestamp = time();
      try {
        if (!\Drupal::database()->insert('file_resup')->fields($upload)->execute()) {
          drupal_exit();
        }
      }
      
        catch (Exception $e) {
        drupal_exit();
      }

      // No upload file should exist at this point.
      file_resup_upload_delete_file($upload);

      // Return 0 as the number of uploaded chunks.
      return file_resup_plain_output('0');
    }

    // On method POST...
    // Ensure we have a valid uploaded file.
    if (empty($_FILES['resup_chunk'])) {
      drupal_exit();
    }
    $file = $_FILES['resup_chunk'];
    if ($file['error'] != UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > file_resup_chunksize()) {
      drupal_exit();
    }

    // Validate the format of the chunk number.
    if (empty($_POST['resup_chunk_number']) || !preg_match('`^[1-9]\d*$`', $_POST['resup_chunk_number'])) {
      drupal_exit();
    }
    $chunk_number = (int) $_POST['resup_chunk_number'];

    // Get the upload record.
    $upload = file_resup_upload_load($upload_id);

    // If no record was found, return nothing.
    if (!$upload) {
      drupal_exit();
    }

    // Validate the chunk number.
    if ($chunk_number > ceil($upload->filesize / file_resup_chunksize())) {
      drupal_exit();
    }

    // If we were given an unexpected chunk number, return what we expected.
    if ($chunk_number != $upload->uploaded_chunks + 1) {
      return file_resup_plain_output($upload->uploaded_chunks);
    }

    // Open the upload file.
    $fp = @fopen(file_resup_upload_uri($upload), 'ab');
    if (!$fp) {
      drupal_exit();
    }

    // Acquire an exclusive lock.
    if (!flock($fp, LOCK_EX)) {
      fclose($fp);
      drupal_exit();
    }

    // Update the record and append the chunk.
    $transaction = db_transaction();
    try {
      $affected = db_update('file_resup')
        ->fields([
        'uploaded_chunks' => $chunk_number,
        'timestamp' => time(),
      ])
        ->condition('upload_id', $upload_id)
        ->condition('uploaded_chunks', $chunk_number - 1)
        ->execute();
      if (!$affected || !($contents = file_get_contents($file['tmp_name'])) || !fwrite($fp, $contents)) {
        throw new Exception();
      }
    }
    
      catch (Exception $e) {
      $transaction->rollback();
      flock($fp, LOCK_UN);
      fclose($fp);
      drupal_exit();
    }

    // Commit the transaction.
    unset($transaction);

    // Flush the output then unlock and close the file.
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // Return the updated number of uploaded chunks.
    file_resup_plain_output($chunk_number);
  }

}
