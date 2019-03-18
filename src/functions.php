<?php

namespace FireSessions;

// Is this the first time we include this file?
if (!function_exists('FireSessions\is_ajax_request')) {

    /**
     * Whether the current request is performed through AJAX or not
     *
     * @return bool
     */
    function is_ajax_request()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Whether the current runtime is a cli or not.
     *
     * @return bool
     */
    function is_cli()
    {
        return strpos(PHP_SAPI, 'cli') === 0 || defined('STDIN');
    }
}
