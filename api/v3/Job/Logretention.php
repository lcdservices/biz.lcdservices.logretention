<?php

/**
 * Job.logretention API specification (optional)
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_job_logretention_spec(&$params) {
}

/**
 * Job.logretention API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_logretention($params) {
  if (!CRM_Core_Config::singleton()->logging) {
    return civicrm_api3_create_error('Logging must be enabled in order to use this API.');
  }
  
  $dsn = defined('CIVICRM_LOGGING_DSN') ? DB::parseDSN(CIVICRM_LOGGING_DSN) : DB::parseDSN(CIVICRM_DSN);
  $loggingDB = $dsn['database']; //logging database
  $retention_period = Civi::settings()->get('retention_period');
  if(!isset($retention_period) || empty($retention_period)){
    return civicrm_api3_create_error('Please set a retention period value in the Log Retention Settings before using this API.');
  }
  $retention_unit = 'month';
  if($retention_period > 1){
    $retention_unit = 'months';
  }
  $retentionPeriod = $retention_period .' '.$retention_unit;
  $ages_ago = date('Y-m-d H:i:s', strtotime("today - $retentionPeriod"));
  
  $schema = new CRM_Logging_Schema();
  $tables = $schema->getLogTableSpec();

  // build _logTables for custom tables
  $customTables = $schema->entityCustomDataLogTables('Contact');
  $logTables = array();
  $excludeLogTables = array();
  foreach($tables as $key=>$value) {
    if($value['engine'] == 'INNODB'){
       $logTables[] = 'log_'.$key;
    }
    else{
      $excludeLogTables[] = 'log_'.$key;
    }
  }
  $logTables = $logTables + $customTables;
  
  $params = array(
    1 => array($ages_ago, 'String'),
  );
  //Civi::log()->debug('logretention', ['params' => $params]);

  //get tables excluded from log retention
  $tables_excluded = Civi::settings()->get('tables_excluded');
  if (empty($tables_excluded)){
    $tables_excluded = array();
  }
  foreach($logTables as $table){
    if (!in_array($table, $tables_excluded)){
      $entity_id = "
        SELECT id
        FROM `{$loggingDB}`.$table
        GROUP BY id
      ";
      $entity_data = CRM_Core_DAO::executeQuery($entity_id);

      while ($entity_data->fetch()) {
        $id = $entity_data->id;

        $daoMaxDate = "
          SELECT max(log_date) as max_log_date
          FROM `{$loggingDB}`.$table
          WHERE log_date < %1
            AND id = $id
          LIMIT 1
        ";
        $max_log_date = CRM_Core_DAO::singleValueQuery($daoMaxDate, $params);

        if ($max_log_date) {
          $sql = "
            DELETE FROM `{$loggingDB}`.$table 
            WHERE log_date < %1 
              AND log_date <> '$max_log_date' 
              AND id = $id
          ";
          //Civi::log()->debug('logretention', ['max_log_date' => $max_log_date, 'sql' => $sql]);
          CRM_Core_DAO::executeQuery($sql, $params);

          $sql = "
            DELETE FROM civicrm_log
            WHERE modified_date < %1
              AND modified_date <> '$max_log_date'
              AND entity_id = $id
            ";
          CRM_Core_DAO::executeQuery($sql, $params);
        }
      }
    }
  }
  return civicrm_api3_create_success("Deleted log entries that were older than $retentionPeriod");
}
