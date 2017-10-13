# Stack Exchange XML dump import

Import Stack Exchange XML dumps into database.


## Requirements

This project requires PHP 7.0+, and Composer dependencies defined in `composer.json`. Run `composer install` to install required dependencies.
Database configuration is expected to be in `config.db.json` file, formatted according to _Neevo DBAL_ format (https://neevo.smasty.net).


## Usage

```
$ php import.php CONFIG_FILE JOB_NAME
```

* **CONFIG_FILE** - configuration file in JSON format. Example configuration in `config.json`.
* **JOB_NAME** - Job name to execute, as specified in the given config.

