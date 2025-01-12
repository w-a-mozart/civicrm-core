<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\WorkflowMessage;

use Civi\WorkflowMessage\Exception\WorkflowMessageException;

/**
 * A WorkflowMessage describes the inputs to an automated email messages.
 *
 * These classes may be instantiated by either class-name or workflow-name.
 *
 * Ex: $msgWf = new \CRM_Foo_WorkflowMessage_MyAlert(['tplParams' => [...tplValues...]]);
 * Ex: $msgWf = new \CRM_Foo_WorkflowMessage_MyAlert(['modelProps' => [...classProperties...]]);
 * Ex: $msgWf = WorkflowMessage::create('my_alert_name', ['tplParams' => [...tplValues...]]);
 * Ex: $msgWf = WorkflowMessage::create('my_alert_name', ['modelProps' => [...classProperties...]]);
 *
 * Instantiating by class-name will provide better hinting and inspection.
 * However, some workflows may not have specific classes at the time of writing.
 * Instantiating by workflow-name will work regardless of whether there is a specific class.
 */
class WorkflowMessage {

  /**
   * Create a new instance of the workflow-message context.
   *
   * @param string $wfName
   *   Name of the workflow.
   *   Ex: 'case_activity'
   * @param array $imports
   *   List of data to use when populating the message.
   *
   *   The parameters may be given in a mix of formats. This mix reflects two modes of operation:
   *
   *   - (Informal/Generic) Traditionally, workflow-messages did not have formal parameters. Instead,
   *     they relied on a mix of un(der)documented/unverifiable inputs -- supplied as a mix of Smarty
   *     assignments, token-data, and sendTemplate() params.
   *   - (Formal) More recently, workflow-messages could be defined with a PHP class that lists the
   *     inputs explicitly.
   *
   *   You may supply inputs using these keys:
   *
   *   - `tplParams` (array): Smarty data. These values go to `$smarty->assign()`.
   *   - `tokenContext` (array): Token-processing data. These values go to `$tokenProcessor->context`.
   *   - `envelope` (array): Email delivery data. These values go to `sendTemplate(...)`
   *   - `modelProps` (array): Formal parameters defined by a class.
   *
   *   Informal workflow-messages ONLY support 'tplParams', 'tokenContext', and/or 'envelope'.
   *   Formal workflow-messages accept any format.
   *
   * @return \Civi\WorkflowMessage\WorkflowMessageInterface
   *   If there is a workflow-message class, then it will return an instance of that class.
   *   Otherwise, it will return an instance of `GenericWorkflowMessage`.
   * @throws \Civi\WorkflowMessage\Exception\WorkflowMessageException
   */
  public static function create(string $wfName, array $imports = []) {
    $classMap = static::getWorkflowNameClassMap();
    $class = $classMap[$wfName] ?? 'Civi\WorkflowMessage\GenericWorkflowMessage';
    $imports['envelope']['valueName'] = $wfName;
    $model = new $class();
    static::importAll($model, $imports);
    return $model;
  }

  /**
   * Import a batch of params, updating the $model.
   *
   * @param \Civi\WorkflowMessage\WorkflowMessageInterface $model
   * @param array $params
   *   List of parameters, per MessageTemplate::sendTemplate().
   *   Ex: Initialize using adhoc data:
   *       ['tplParams' => [...], 'tokenContext' => [...]]
   *   Ex: Initialize using properties of the class-model
   *       ['modelProps' => [...]]
   * @return \Civi\WorkflowMessage\WorkflowMessageInterface
   *   The updated model.
   * @throws \Civi\WorkflowMessage\Exception\WorkflowMessageException
   */
  public static function importAll(WorkflowMessageInterface $model, array $params) {
    // The $params format is defined to match the traditional format of CRM_Core_BAO_MessageTemplate::sendTemplate().
    // At the top level, it is an "envelope", but it also has keys for other sections.
    if (isset($params['model'])) {
      if ($params['model'] !== $model) {
        throw new WorkflowMessageException(sprintf("%s: Cannot apply mismatched model", get_class($model)));
      }
      unset($params['model']);
    }

    \CRM_Utils_Array::pathMove($params, ['contactId'], ['tokenContext', 'contactId']);

    // Core#644 - handle Email ID passed as "From".
    if (isset($params['from'])) {
      $params['from'] = \CRM_Utils_Mail::formatFromAddress($params['from']);
    }

    if (isset($params['tplParams'])) {
      $model->import('tplParams', $params['tplParams']);
      unset($params['tplParams']);
    }
    if (isset($params['tokenContext'])) {
      $model->import('tokenContext', $params['tokenContext']);
      unset($params['tokenContext']);
    }
    if (isset($params['modelProps'])) {
      $model->import('modelProps', $params['modelProps']);
      unset($params['modelProps']);
    }
    if (isset($params['envelope'])) {
      $model->import('envelope', $params['envelope']);
      unset($params['envelope']);
    }
    $model->import('envelope', $params);
    return $model;
  }

  /**
   * @param \Civi\WorkflowMessage\WorkflowMessageInterface $model
   * @return array
   *   List of parameters, per MessageTemplate::sendTemplate().
   *   Ex: ['tplParams' => [...], 'tokenContext' => [...]]
   */
  public static function exportAll(WorkflowMessageInterface $model): array {
    // The format is defined to match the traditional format of CRM_Core_BAO_MessageTemplate::sendTemplate().
    // At the top level, it is an "envelope", but it also has keys for other sections.
    $values = $model->export('envelope');
    $values['tplParams'] = $model->export('tplParams');
    $values['tokenContext'] = $model->export('tokenContext');
    if (isset($values['tokenContext']['contactId'])) {
      $values['contactId'] = $values['tokenContext']['contactId'];
    }
    return $values;
  }

  /**
   * @return array
   *   Array(string $workflowName => string $className).
   *   Ex: ["case_activity" => "CRM_Case_WorkflowMessage_CaseActivity"]
   * @internal
   */
  public static function getWorkflowNameClassMap() {
    $cache = \Civi::cache('long');
    $cacheKey = 'WorkflowMessage-' . __FUNCTION__;
    $map = $cache->get($cacheKey);
    if ($map === NULL) {
      $map = [];
      $map['generic'] = GenericWorkflowMessage::class;
      $baseDirs = explode(PATH_SEPARATOR, get_include_path());
      foreach ($baseDirs as $baseDir) {
        $baseDir = \CRM_Utils_File::addTrailingSlash($baseDir);
        $glob = (array) glob($baseDir . 'CRM/*/WorkflowMessage/*.php');
        $glob = preg_grep('/\.ex\.php$/', $glob, PREG_GREP_INVERT);
        foreach ($glob as $file) {
          $class = strtr(preg_replace('/\.php$/', '', \CRM_Utils_File::relativize($file, $baseDir)), ['/' => '_', '\\' => '_']);
          if (class_exists($class) && (new \ReflectionClass($class))->implementsInterface(WorkflowMessageInterface::class)) {
            $map[$class::WORKFLOW] = $class;
          }
        }
      }
      $cache->set($cacheKey, $map);
    }
    return $map;
  }

}
