# wp-updatehelper

Simple cli tool based on [Laravel Zero](https://github.com/laravel-zero/laravel-zero) to update your WordPress plugins and translations using [wp-cli](https://github.com/wp-cli/wp-cli).

1. Find all active plugins that can be updated.
2. Ask if the update should be installed and install the update.
3. Commit changes to your repository.
4. Ask if translations should be updated and update the translations.
5. Commit changes to your repository.
6. Output a changelog with all updates.

## Usage

```sh
$ wp-updatehelper update
```

## License

The MIT License (MIT). Please see the [license file](LICENSE.md) for details.
