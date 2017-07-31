<?php

namespace FireSessions;

/**
 * SessionHandlerInterface is an interface which defines
 * a prototype for creating a custom session handler.
 * In order to pass a custom session handler to
 * session_set_save_handler() using its OOP invocation,
 * the class must implement this interface.
 *
 * @link http://php.net/manual/en/class.sessionhandlerinterface.php
 * @since 5.4.0
 */
interface SessionHandlerInterface
{
    public function open($save_path, $name);

    public function read($session_id);

    public function write($session_id, $session_data);

    public function close();

    public function destroy($session_id);

    public function gc($maxlifetime);
}
