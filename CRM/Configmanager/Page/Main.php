<?php

// phpcs:ignore -- The legacy CiviCRM class name is fixed by CiviCRM autoloading.
class CRM_Configmanager_Page_Main extends CRM_Core_Page {

  public function run() {
    (new \Civi\ConfigManager\UI\MainPage($this))->run();
    parent::run();
  }

}
