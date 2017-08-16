[![Build Status](https://travis-ci.org/kattsoftware/FireSessions.svg?branch=master)](https://travis-ci.org/kattsoftware/FireSessions)

## Introduction

**FireSessions** is a PHP library for managing your project sessions. It lets you store the session data on your local disk, on a Memcached server, or a Redis one; the library can manage 3 types of session variables: user data, flash data and temp data. This library, through all its drivers, supports locking for opened sessions.

## Installation

You can install FireSessions by using [composer](https://getcomposer.org/):

```
composer require kattsoftware/firesessions:^1.0
```

## Configuration
To start using this library, you must set it up, e.g.:

```php
<?php

require_once 'vendor/autoload.php';

use FireSessions\Session;

$config = array(
    'driver' => Session::FILES_DRIVER,
    'cookie_name' => 'fsessions',
    'save_path' => __DIR__ . DIRECTORY_SEPARATOR . 'sessions',
    'expiration' => 7200,
    'regenerate_time' => 300,
    'destroy_on_regenerate' => false
);

$session = new Session($config);
```

As you can see, the settings are an associative array; they may differ from driver to driver. Below is a list of settings which can be set regardless of the used driver:

| Setting | Options | Default value | Description |
|---|---|---|---|
| `driver` | string | `Session::FILES_DRIVER` | The session driver to be used; can be any of these constants: `Session::FILES_DRIVER`, `Session::MEMCACHED_DRIVER`, `Session::REDIS_DRIVER` or the name of any custom created driver (see below). |
| `cookie_name` | string (A-Z, a-z, _ or - are accepted) | fs_session | The name of the cookie that will be send to user's browser. |
| `expiration` | time, in seconds (int) | 7200 (2 hours) | The number of seconds your session should live, before it expires. Using `0` (zero) will make the session to last until browser is closed.  |
| `match_ip` | `true`/`false` (bool) | `false` | Whether to check for user's IP address to be the same as the one when the session was created. There may be some scenarios where enabling this may lead to issues (e.g. a mobile network losing the signal and reconnecting, getting a different IP address). |
| `regenerate_time` | time, in seconds (int) | 300 | Specifies how many seconds it will take to regenerate the session ID, replacing the current one with another generated. Setting this configuration to `0` will disable the regeneration of session ID (however, disabling this will increase the chances of possible session fixation attacks). |
| `destroy_on_regenerate` | `true`/`false` (bool) | `false` | If session ID regeneration is enabled, this tells whether to delete the old session data on session ID regeneration step or to leave it for deletion by PHP's garbage collector. |

### Files driver

The files driver will save your session data files on the server's disk. The only setting left here to be set is `save_path`.

`save_path` is  an absolute full path of a writable directory, where PHP process can put its session files. If it's not set, FileSessions will try to use the `session_save_path()` function return value.

### Memcached driver

This driver will save all your session data on a [Memcached](http://php.net/manual/en/book.memcached.php) server instance.

Here, `save_path` is a comma-delimited list of `server:port` pairs:

```php
$config['save_path'] = 'localhost:11211,192.168.1.1:11211';
```

You may also add a third parameter (`:weight`), which specifies the priority of a Memcached server:

```php
// 192.168.0.1:11211 has a higher priority (5), while 192.168.1.1:11211 has the weight 1.
$config['save_path'] = '192.168.0.1:11211:5,192.168.1.1:11211:1';
```

Please keep in mind that this may lead to performance gains or loses; it may depend from environment to environment. If you are unsure, do not set the servers' weights.

By default, the Memcached driver will retrieve the local pool servers ([`Memcached::getServerList()`](http://php.net/manual/en/memcached.getserverlist.php)) available. To disable this, you can use the `fetch_pool_servers` setting:

```php
$config['fetch_pool_servers'] = false;
```

### Redis driver

The Redis driver will save your session data on a [Redis](https://redis.io/) server instance. 

For this driver, the `save_path` setting is a comma-delimited set of connecting parameters names and their values (`param=value`):

```php
$config['save_path'] = 'host=localhost,port=6379,password=myPassword,database=2,timeout=30,prefix=sessions:';

// host - (required) the hostname of Redis server
// port - (optional) the Redis server port
// password - (optional) Redis authentication password
// database - (optional) which Redis database to use
// timeout - (optional) Redis connection timeout, in seconds
// prefix - (optional) Prefix of the entries names (if not set, the "cookie_name" setting will be used)
```

## Usage

FireSession implements 3 types of session variables: user data, flash data and temp data.

### User data

_User data_ represents the collection of variables you set during a whole session lifetime. You can use them to store the ID of logged user, the privileges for a specific user, etc. Simply put, they are the classic `$_SESSION` variables.

To _set user data variable_, you can use the following call:

```php
$session->setUserdata('logged_userid', 1234);
$session->setUserdata('logged_username', 'myUsername');

// or

$session->logged_userid = 1234;
$session->logged_username = 'myUsername';

// you may also set the variable by assigning $_SESSION variable:

$_SESSION['logged_userid'] = 1234;
$_SESSION['logged_username'] = 'myUsername';

// you can also set multiple user data at once:
$session->setUserdata(array(
    'logged_userid' => 1234,
    'logged_username' => 'myUsername'
));
```

However, the recommendation here is to use the `$session` variables (the `Session` instance), so you will have consistency all over your project.

To _get user data variable_, you can use the following call:

```php
echo $session->userdata('logged_userid'); // outputs 1234
echo $session->userdata('logged_username'); // outputs myUsername

// or

echo $session->logged_userid; // outputs 1234
echo $session->logged_username; // outputs myUsername

// or you can use the $_SESSION variable:

echo $_SESSION['logged_userid']; // outputs 1234
echo $_SESSION['logged_username']; // outputs myUsername
```

Once again, the recommendation is to use the `Session` instance. :)

If the requested variable doesn't exists, `userdata()` will return `null`.

If you need _all user data_, you can ask for the whole associative array of user data:

```php
var_dump($session->userdata()); // Outputs all user data
```

To _remove user data_, you can use the following call:

```php
// removes the "logged_userid" variable
$session->unsetUserdata('logged_userid');

// removes multiple user data
$session->unsetUserdata(array('logged_userid', 'logger_username'));
```

### Flash data

Flash data are the same as user data, except their lifetime is the current and the next HTTP request. After that, they will be deleted.

To _set flash data_, you can use the following call:

```php
$session->setFlashdata('success_message', 'Your profile has been saved!');

// setting multiple flash data at once:

$session->setFlashdata(array(
    'firstname_validation' => 'Invalid first name!',
    'email_validation' => 'Invalid e-mail address!'
));
```

To _get flash data_ value, you can use the following call:

```php
echo $session->flashdata('email_validation');
// outputs The typed e-mail address is invalid!

// you can also fetch the entire flash data as an associative array:
var_dump($session->flashdata());
```

If, after being redirected to the next request, you want to _keep flash data_ for another request, you can use the following call:

```php
$session->keepFlashdata('email_validation');
// Preserves the "email_validation" flash for another request as well

// Preserve more than one flash data for another request
$session->keepFlashdata(array('email_validation', 'firstname_validation'));
```

To _remove flash data_, you can use the following call:

```php
// removes the "email_validation" flash data
$session->unsetFlashdata('email_validation');

// removes multiple flash data
$session->unsetUserdata(array('email_validation', 'firstname_validation'));
```

### Temp data

Temp data are similar to flash data, except they live for a giving number of seconds, instead of current and next request.

To _set temp data_, you can use the following call:

```php
// this will create a temp data 'quiz_score', having the value of 72
// and expiring after 300 seconds
$session->setTempdata('quiz_score', 73, 300);

// setting multiple temp data at once
$session->setTempdata(array(
    'quiz_question1' => 10,
    'quiz_question2' => 0
), array(
    'quiz_question1' => 300, // quiz_question1 will expire after 300 seconds
    'quiz_question2' => 350 // quiz_question2 will expire after 350 seconds
));

// or you can use the same expiration time for all items:

$session->setTempdata(array(
    'quiz_question1' => 10,
    'quiz_question2' => 0
), 300);
```

To _get temp data_ value, you can use the following call:

```php
echo $session->tempdata('quiz_question1');
// outputs 10

// you can also fetch the entire temp data as an associative array:
var_dump($session->tempdata());
```

To _remove temp data_, you can ue the following call:

```php
// removes the 'quiz_question1' temp data
$session->unsetTempdata('quiz_question1');

// or you may set multiple temp data at once:

$session->unsetTempdata('quiz_question1', 'quiz_question2');
```

You can also check if certain variables exist:
```php
// all of the below calls return a boolean value
$session->hasUserdata('logged_userid'); 
$session->hasFlashdata('email_validation');
$session->hasTempdata('quizz_answers');
```

If you want to destroy the entire session (which means all types of variables), you can use the following call:

```php
// Deletes the user, flash and temp data + the session cookie
$session->destroy();
```

### Using the `SessionFactory`

If, for any reasons, you will have different places in your project where you will need this library, you will need the `Session` instance more than one time. In this case, `new Session($config)` won't work, because the lib was previously created.

In this case, you can use the `SessionFactory` in all places, so you will get always the first instance:

```php
<?php

use FireSessions\SessionFactory;

// here goes your config
// $config = array(...);

// A global instance is created
$session = SessionFactory::getInstance($config);

// ... in another place/file, you may get the already created instance again:

$session = SessionFactory::getInstance();
```

### Defining your custom drivers

You may create your own session drivers as well! In this case, you will use the `FireSessions\DriversFactory` factory.

To do that, you will need to build a class extending the `FireSessions\BaseSessionDriver` class (this, by its nature, implements [`SessionHandlerInterface`](http://php.net/manual/en/class.sessionhandlerinterface.php)). Besides implementing the `SessionHandlerInterface`'s methods, you will have to implement the additional `acquireLock` and `releaseLock` abstract methods as well.

Don't forget that your custom driver should receive the `$config` array as well.

After that, please call the parent constructor like this:
 
```php
parent::__construct($config);
```

Once you are there, you can make use of the already available methods, such as `BaseSessionDriver::destroyCookie()` and `BaseSessionDriver::getIp()`.
 
To make your custom driver available for being used, use the following call before instantiating the `Session` client:

```php
DriversFactory::registerDriver('myDriver', MyDriver::class);

// or you can send the already created custom driver instance:
// $myDriver = new MyDriver(...);
DriversFactory::registerDriver('myDriver', $myDriver);
```

If you are providing the class name as the second parameter (and not an actual instance of the custom driver), then the factory will try to create an instance by providing `$config` as parameter.

Now you are ready to use your custom driver!

```php
$config = array(
    'driver' => 'myDriver',
    ...
);

$session = new Session($config);
```

## Acknowledgments

This library closely follows the implementation and the API of the [CodeIgniter 3](https://www.codeigniter.com/) Session library. Big thanks go to their members and contributors!

## License

The library is licensed under the MIT License (MIT). See the `LICENSE` file for more information.

Copyright (c) 2017 KattSoftware dev team.
