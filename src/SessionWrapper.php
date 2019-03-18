<?php

namespace FireSessions;

class SessionWrapper
{
    public function setCookieParams($lifetime, $path, $domain, $secure, $httpOnly)
    {
        session_set_cookie_params(
            $lifetime,
            $path,
            $domain,
            $secure,
            $httpOnly
        );
    }

    public function setHandler(BaseSessionDriver $handler)
    {
        return session_set_save_handler($handler, true);
    }

    public function start()
    {
        return session_start();
    }

    public function getId()
    {
        return session_id();
    }

    public function sessionSavePath($newSavePath = null)
    {
        return session_save_path($newSavePath);
    }

    public function regenerateId($destroy)
    {
        return session_regenerate_id($destroy);
    }

    public function destroy()
    {
        return session_destroy();
    }
}
