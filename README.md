# WP Elastracsearch

An application for indexing WordPress Trac into an Elasticsearch cluster. 

Uses:
- [PHPTracRPC](https://github.com/jakoch/PHPTracRPC)
- [php-elasticsearch](https://github.com/elastic/elasticsearch-php)

This code is used to support the Elasticsearch instance behind https://tracsearch.wpteamhub.com. 

## Installation

These instructions assume you already have an Elasticsearch cluster up and running. 

1. Clone this repository
```
git clone git@github.com:earnjam/wp-elastracsearch.git
```
2. Install dependencies using composer: 
```
cd wp-elastracsearch
composer install
```
3. Rename or copy `config-sample.php` to `config.php` and update the values as required.

> **Note:** This script uses Trac's XML-RPC API to pull ticket data, which requires authentication. 
> 
> I would recommend creating a new wordpress.org account to be used for this purpose in order to separate it from any personal account. 

- `username` and `password` are for the wordpress.org account to be used
- `hosts` is an [array of your Elasticsearch cluster nodes](https://www.elastic.co/guide/en/elasticsearch/client/php-api/5.0/_configuration.html#_inline_host_configuration)
- `index` defaults to `wptrac`, but can be named anything
- `type` should stay as `_doc` unless you are using an older version of Elasticsearch that still supports custom document types. 


4. Run the `create-index.sh` script to set up the required index and proper mapping types
```
./bin/create-index.sh
```
5. Build the initial index. **Note:** This will take several hours to index all 45000+ tickets
```
./bin/build-index
```
6. Set up a cron task to update the index using the `update-index` script.