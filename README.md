# wp-updatehelper

Simple cli tool to update your WordPress plugins and translations using [wp-cli](https://github.com/wp-cli/wp-cli).

1. Check if WordPress needs to be updated.
2. Ask if WordPress should be updated and update WordPress.
3. Commit changes to your repository.
4. Find all active plugins that can be updated.
5. Ask if the update should be installed and install the update.
6. Commit changes to your repository.
7. Ask if translations should be updated and update the translations.
8. Commit changes to your repository.
9. Output a changelog with all updates.

## Usage

```sh
$ wp-updatehelper
```

## License

The MIT License (MIT). Please see the [license file](LICENSE.md) for details.
