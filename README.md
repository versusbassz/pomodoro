# POMOdoro (WordPress Translation Cache)

Note: it's a fork of https://github.com/pressjitsu/pomodoro/, initially.

## Motivation

The WordPress gettext implementation is very slow. It uses objects that
cannot be cached into memory without reinstanting them.

Pomodoro stores all seen translations as a PHP hashtable (array), that can be
subsequently stored into a file as PHP code and levereged via OPcache when loaded.

Moreover, pomodoro does lazyloading for strings that are only encountered in output.
It does not preload everything it can for a domain until there's no other choice.

## But does it really work?

Seems like that. It's better to measure for every certain site separately.

Example on a vanilla `ru_RU` locale WordPress site:

Before:

![Before](https://raw.githubusercontent.com/versusbassz/pomodoro/main/assets/before.png)

After:

![After](https://raw.githubusercontent.com/versusbassz/pomodoro/main/assets/after.png)

## Installation

Drop `pomodoro.php` into `wp-content/mu-plugins` and enjoy the added speed :)

The more plugins you have the better the performance gains.

You can use `POMODORO_CACHE_DIR` constant to change cache directory (needs full path).

## WP-CLI interface
```shell
# print stats about cache directory and files
# to see more raw data use --format parameter
wp pomodoro stats
wp pomodoro stats --format=print_r
wp pomodoro stats --format=var_dump
wp pomodoro stats --format=json
wp pomodoro stats --format=json --pretty

# list cached files
wp pomodoro list

# prune cached files
# note: remember that the removed files can be recreated immediately 
#       by other requests or CLI-commands done after the moment of removing.
wp pomodoro prune

# prune cached files for a specific textdomain
# note: all cached files for that textdomain will be removed
wp pomodoro prune <textdomain>

# lint cached files
wp pomodoro lint

# print the plugin version
wp pomodoro version
```

## Other settings
If necessary, define `POMODORO_DONT_UPDATE` constant with `true` (boolean) value
to disable updating of cached files during a current request.  
It's used inside `wp pomodoro prune` command.

## License

GPLv3
