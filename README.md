## Introduction

**FireSessions** is a PHP library for managing your site sessions.

## Installing 

You can install FireSessions by using [composer](https://getcomposer.org/):

```
composer require kattsoftware/firesessions:^1.0
```

## Configuration
To start using this library, you must set it up:

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

`TODO`

## Usage

FireSession implements 3 types of session variables: user data, flash data and temp data.

### User data

_User data_ represents the collection of variables you set during a whole session lifetime. You can use them to store the ID of logged user, the privileges for a specific user, etc. Simply put, they are the classic `$_SESSION` variables.

To **set a user data variable**, you can use the following call:

```php
$session->setUserdata('logged_userid', 1234);
$session->setUserdata('logged_username', 'myUsername');

// or

$session->logged_userid = 1234;
$session->logged_username = 'myUsername';

// you may also set the variable by assigning $_SESSION variable:

$_SESSION['logged_userid'] = 1234;
$_SESSION['logged_username'] = 'myUsername';
```

However, the recommendation here is to use the `$session` variables (the `Session` instance), so you will have consistency all over your project.

You can also set more user data at once:

```php
$session->setUserdata(array(
    'logged_userid' => 1234,
    'logged_username' => 'myUsername'
));
```

To **get a user data variable**, you can use the following call:

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

If you need **all user data**, you can ask for the whole associative array of user data:

```php
var_dump($session->userdata()); // Outputs all user data
```

To **remove user data**, you can use the following call:

```php
// removes the "logged_userid" variable
$session->unsetUserdata('logged_userid');

// removes multiple user data
$session->unsetUserdata(array('logged_userid', 'logger_username'));
```

### Flash data

Flash data are the same as user data, except their lifetime is the current and the next HTTP request. After that, they will be deleted.

To **set flash data**, you can use the following call:

```php
$session->setFlashdata('success_message', 'Your profile has been saved!');

// setting multiple flash data at once:

$session->setFlashdata(array(
    'firstname_validation' => 'Invalid first name!',
    'email_validation' => 'Invalid e-mail address!'
));
```

To **get flash data** value, you can use the following call:

```php
echo $session->flashdata('email_validation');
// outputs The typed e-mail address is invalid!

// you can also fetch the entire flash data as an associative array:
var_dump($session->flashdata());
```

If, after being redirected to the next request, you want to **keep flash data** for another request, you can use the following call:

```php
$session->keepFlashdata('email_validation');
// Preserves the "email_validation" flash for another request as well

// Preserve more than one flash data for another request
$session->keepFlashdata(array('email_validation', 'firstname_validation'));
```

To **remove flash data**, you can use the following call:

```php
// removes the "email_validation" flash data
$session->unsetFlashdata('email_validation');

// removes multiple flash data
$session->unsetUserdata(array('email_validation', 'firstname_validation'));
```

### Temp data

Temp data are similar to flash data, except they live for a giving number of seconds, instead of current and next request.

`TODO`

## Other

You can also check if certain variables exist:
```php
TODO
```

## License

The library is licensed under the MIT License (MIT). See the `LICENSE` file for more information.

Copyright (c) 2017 KattSoftware dev team.
