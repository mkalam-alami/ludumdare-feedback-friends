# Ludum Dare Feedback Friends

An incentive to comment on games, for Ludum Dare 36.

## How it works

This mini-site is basically a LD games browser, improved. It uses a slightly clever "comment coolness" system to feature games with the least feedback received first.

## Notes

* "Feedback friends" just means other authors with whom you commented on each other's game
* TODO Instead of ratings, I'm considering a stupid but potentially fun trophy system, that will analyze keywords in comments to give awards at the end of the competition, such as "Most WTF Entry", "Cutest Entry", "Least Playable Entry", etc. The awards could then actually be added (manually) on the user entry pages.

## Setup

* Requirements: Apache/PHP/MySQL stack, [Composer](https://getcomposer.org/)
* Run `composer install` from the sources root
* Copy `config.sample.php` to `config.php` and customize it
* Browse to `/install.php` once to run the MySQL setup
* Browse to `/scraping.php` to grab a few games
* Done
