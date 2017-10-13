# Stack Exchange XML dump import

Semi-automatic Stack Exchange XML dumps to Postgres importer.


## Requirements

This project requires **PHP 7.0+**, and Composer dependencies defined in `composer.json`.
Run `composer install` to install required dependencies.
Database configuration is expected to be in `config.db.json` file, formatted according
to [Neevo DBAL](https://neevo.smasty.net) format. Example configuration is included in `config.db.example.json`.


## Usage

```
$ php import.php CONFIG_FILE JOB_NAME
```

* **CONFIG_FILE** - configuration file in JSON format. Example configuration in `config.json`.
* **JOB_NAME** - Job name to execute, as specified in the given config.

This will generate a Postgres dump file (using `COPY` command) in the data directory according to the configuration.

To successfully import the whole XML dump, the following steps are necessary:

1. Populate database with tags and badges (using [DataMiner](https://github.com/stackletter/DataMiner))
2. Generate SQL dump for users (`php import.php config.json users`)
3. Import the SQL dump (`sudo -u postgres psql -d [dbname] < data/users.sql`)
4. Repeat steps 2 and 3 for (in this order!) questions, answers, comments, tags and badges.

You can use an included shell script (`autoimport.sh`) to automatically follow these steps.
**The included script makes some assumptions about your enviroment**, so inspect and modify it according to your
needs before running it.
