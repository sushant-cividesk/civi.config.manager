<?php
namespace Civi\ConfigManager\Handler;

/** Internal control-flow signal for a self-referential Tag dependency. */
class TagParentPendingException extends \RuntimeException {}
