# Ludum Dare Feedback Friends

An incentive to comment on games, for Ludum Dare 36.

## How it works

This mini-site is basically a LD games browser, improved. It uses a slightly clever "comment coolness" system to feature games with the least feedback received first.

## Setup

* Requirements: Apache/PHP/MySQL stack, [Composer](https://getcomposer.org/)
* Run `composer install` from the sources root
* Copy `config.sample.php` to `config.php` and customize it
* Browse to `/install.php` once to run the MySQL setup
* Browse to `/scraping.php` to grab a few games
* Done

## I want to contribute!

Great! Feel free to check the [Github issues](https://github.com/mkalam-alami/ludumdare-feedback-friends/issues) for things to do ; or if you have a feature idea, please create an issue first to discuss it with the maintainers. Happy coding!

## Notes

### Scraping cron task

```* * * * * timeout 60s php /var/www/ldff/scraping.php```