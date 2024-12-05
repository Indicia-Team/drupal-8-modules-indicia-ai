# drupal-module-indicia-ai

A set of modules for automated identification of species.

There are multiple proxy modules for communicating with various services and
a single indicia_ai module offering a uniform interface to all the available
services.

Refer to the README of each module for a detailed description. It is the
intention that users will only interact with the indicia_ai module.

## Installation

The module has a dependancy on the Indicia iForm module. If this is not already
present on your website then it will need adding. Some familiarity with
composer is assumed.

### Installation with iForm module already present

Edit the composer.json file in the root of your website and add a repository
thus:
```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Indicia-Team/drupal-8-modules-indicia-ai"
        }
    ]
```
At a command prompt, change directory to the root of your site and execute
`composer require indicia/indicia-ai`

### Installation when iForm module absent

Edit the composer.json file in the root of your website and add repositories
thus:
```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Indicia-Team/drupal-8-modules-indicia-ai"
        },
        {
            "type": "vcs",
            "url": "https://github.com/Indicia-Team/drupal-8-module-iform"
        },
        {
            "type": "vcs",
            "url": "https://github.com/Indicia-Team/client_helpers"
        },
        {
            "type": "vcs",
            "url": "https://github.com/Indicia-Team/media"
        }
    ]
```
Still in composer.json, add installer paths thus:
```json
    "extra": {
        "installer-paths": {
            "web/modules/custom/iform/{$name}": [
                "indicia/client_helpers",
                "indicia/media"
            ],
            "web/modules/custom/{$name}": ["type:drupal-custom-module"]
        }
    },
```
At a command prompt, change directory to the root of your site and execute
`composer require indicia/indicia-ai drupal/iform indicia/client_helpers indicia/media`

Repositories and installer-paths are not inherited from the composer files of
dependencies which is why we need to add them in the root composer.json file.

## Configuration

After installing the module,

    - enable it in /admin/modules,


## Development

A DDEV configuration is provided for local development. To set this up

1. If not already present
[install DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/).

1. Git clone the module from github.

1. At a command prompt, change to the folder where you just cloned the module and
run `ddev start`.

1. Run `ddev poser`.

   - When asked to trust php-http/discovery, respond with y(es).
   - When asked to trust tbachert/spi, respond with n(o)

1. Run `ddev symlink-project`.

1. With a browser, navigate to the url given by DDEV, probably
https://drupal-8-modules-indicia-ai.ddev.site and complete the normal Drupal
installation

Additional ddev commands are courtesy of https://github.com/ddev/ddev-drupal-contrib

For information on step debugging, see the
[configuration instructions](https://ddev.readthedocs.io/en/latest/users/debugging-profiling/step-debugging/).


Follow the configuration instructions above to enable and configure the module.
